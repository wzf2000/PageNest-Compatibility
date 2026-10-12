# 页面测速

本模块处于开发第一阶段，尚未合并、进行 WordPress 集成验收或上线。管理员可在 “工具 → 页面测速” 选择公开代表页，提交给独立的服务器端 Playwright 执行器；PHP 不启动 shell，不发起 HTTP 请求，不注册 REST、前台资源或定时任务。默认关闭。

## 显式配置

服务器管理员先创建私有队列目录，再在服务器配置中设置：

```php
define('PAGENEST_COMPANION_PERFORMANCE_ENABLED', true);
define('PAGENEST_COMPANION_PERFORMANCE_DIR', '/private/performance-queue');
```

路径仅为示例。目录必须已经存在，绝对路径必须等于 realpath；目录及其父路径、现有文件不能有符号链接。目录必须位于 ABSPATH、服务器 Web 根、插件目录之外，不能赋予 others 权限（0700、0750 或 0770）；文件使用 0600。PHP 和执行器须以同一 UID 运行；0700/0600 为首版创建权限，组共享目录权限不意味着不同 UID 可以读取任务文件。后台不能配置路径或命令，模块不会创建运行目录。未启用时不读取选择 option、不访问队列磁盘，也不注册提交处理。

## 页面选择

最多选择 8 个页面，只保存首页、博客标识或公开文章/页面 ID，option 不自动加载。首页与博客入口从 WordPress 的 home_url、show_on_front、page_on_front、page_for_posts 推导。候选从最近最多 100 个公开文章/页面选出正文长度中位页、公式或代码页、图片/PDF 页、通用导览页，并结合实际健康公开工具注册表的 indexEntries 投影。内容不足时推荐数可能少于 6；这是代表样本，不是全站扫描。

任务提交时重新从 ID 解析页面，必须 publish、post/page、无密码且 is_post_publicly_viewable。URL 必须 HTTPS、与 home_url 同 host，无用户信息、查询、fragment 或非 443 端口。普通 ?p 链接本阶段不支持。敏感管理、登录、REST、XML-RPC、OAuth、preview 路径被拒绝；不能输入任意 URL。工具公开投影不包含外站直达或鉴权工具。权限为 manage_options，操作使用 POST 与 nonce，并返回 no-store。

## 队列协议

目录中的 queue.lock 以 flock 对提交、取消和执行器状态变更互斥；worker.lock 由执行器全局非阻塞独占持有。PHP 无法取得 worker.lock 或已有有效 queued/running 任务时拒绝新任务。不能仅因时间过期就启动第二个执行器。worker.lock 无人持有时，过期 queued/running 记录安全标记为 failed、worker_expired，允许后续任务。

报告名为 job-<32 位小写十六进制 ID>.json；schema_version=1，包含 id、status、created_at/updated_at/expires_at（UTC ISO8601）、profile、origin、pages、versions、results、error。状态为 queued/running/completed/failed/cancelled。profile 包含 device=desktop/mobile、cache_mode=fresh-context、repeats=1/3。pages 项为 key=home/blog/post:<ID>、post_id、url、title、reason；versions 为 wordpress、companion、theme。

每次导航最多 30 秒，任务最多 12 分钟，记录有效期为创建后 30 分钟。单报告上限 512 KiB，最多保留 10 份，队列目录总文件预算严格小于 5 MiB。超预算时拒绝提交并显示管理员清理提示，不自动删除报告，也没有后台删除入口。写入使用同目录独占临时文件、flush/fsync（支持时）和原子 rename，不接受符号链接文件。

queued 取消直接更新 cancelled。running 取消写 cancel-<ID> 文件，内容仅为 schema_version=1 与 id；执行器在导航之间及最终写入终态的 queue.lock 内检查，避免最后一次采样期间的取消被完成状态覆盖。后台采用手动刷新，没有轮询。

results 为扁平逐轮数组，每项包含 page_key、run、status=ok/failed、http_status、ttfb_ms、fcp_ms、lcp_ms、cls、long_task_blocking_ms、request_count、transfer_bytes、failed_requests、blocked_requests、cache_header、duration_ms、error。时间和 CLS 可为 null，HTTP 状态可为 null；cache_header 仅 HIT/MISS/BYPASS/unknown，来自 X-Wzf-Blog-Cache。error 仅安全错误码。environment 可包含 runner=playwright-navigation-v1、node_version、chromium_version、network=server-local、cache_mode=fresh-context。不会保留 DOM、HAR、截图、原始头、console、异常文本或敏感 URL。

completed 表示任务执行完成，个别页面仍可能 failed。后台展示每轮结果和每项成功有效样本的中位数及 N/3。不同设备配置不合并比较。

## 测量限制

执行器使用匿名隔离浏览器，通过独立 CDP Fetch 的 request 与 response 阶段拦截实施网络保护。主文档严格锁定任务目标 URL，所有服务端 3xx 重定向直接阻止；写方法、敏感页面、API、XHR/fetch/beacon、iframe、worker、WebSocket 与未批准资源请求均禁用。请求前清除 Cookie 存储并移除 Cookie header，响应剔除 Set-Cookie，同时禁用脚本读写 Cookie、弹窗、点击与表单提交。

每轮创建全新 browser context，记录为 fresh-context 首次访问；浏览器进程及操作系统 DNS 等不保证是冷状态。HTTP 缓存保持浏览器正常行为，不主动注入 no-cache/Pragma 请求头；没有温缓存模式。若强制禁用浏览器缓存，Chromium 会附带请求指令，可能令严格的匿名 origin 缓存绕过，因此不能以这种请求判定缓存无效。服务端缓存状态仅观察允许的响应头，不触发清理或改变缓存策略。结果体现服务器本地网络与受控资源环境，不代表真实用户的完整浏览旅程。

默认等待 load 后再采样 1.5 秒；FCP/LCP/CLS 是这段观察窗口内的值。长任务阻塞代理值不是 Lighthouse TBT。本阶段没有 Lighthouse 评分或 INP。PageNestPerformance/1.0 精确空白分隔 User-Agent token 被 Companion 浏览计数忽略，与机器人排除开关无关；该 token 不赋予登录、权限或缓存豁免。第三方分析网络因控制策略可能被拦截。

本地检查使用合成 WordPress 函数与临时私有目录：

```sh
npm run test:performance
php tests/views.php
php tests/views.php cache
```

执行器本地契约和浏览器检查使用已有 Node 依赖以及本地 TLS fixture（需要 Linux、PHP、OpenSSL 和已安装的 Playwright Chromium）：

```sh
npm run test:performance-runner
npm run test:performance-browser
```

开发依赖可通过已有工作区的 node_modules 复用，不自动下载浏览器。本地检查包含 PHP 与 Node 任务／报告往返、文件锁互斥、HTTP/TLS 重定向、Cookie、脚本弹窗与无限循环脚本收尾，不访问生产页面。PHP 合约及本地页面测试不替代合并后的 WordPress 集成与浏览器验收。

## 手动执行器

执行器配置文件位于私有绝对路径，JSON schema_version 为 1，包含 queue_dir、origin、asset_hosts（最多 16 个批准资源 host），可选 browser_executable。queue_dir 与 PHP 配置指向同一个私有目录，origin 与目标站点 HTTPS origin 一致。配置路径和资源白名单由服务器管理员维护，不能从后台请求传入。

```sh
node tools/performance-runner/cli.cjs --once --config /absolute/private/runner.json
```

命令仅单次手动处理队列，不创建定时任务。执行器依赖 Linux 的 /usr/bin/flock，并与 PHP 使用同一 UID，保证 worker.lock 和 queue.lock 为同一套内核锁。浏览器控制入口和安全代理只监听回环，DNS 解析结果全部校验后固定目标 IP，拒绝私网、回环、链路本地与 IPv6 保留地址；CLI 不支持本地 fixture 放行。默认不批准第三方资源，需服务器明确指定 asset_hosts。

上线前必须核对进程 CPU、内存与 cgroup 限制、队列持久化和 FPM 路径许可、Chromium 低权限执行与依赖版本，保持明确的手动入口；本阶段没有创建服务或定时调度。手动启动一次 CLI 才处理一次排队任务，后台按钮本身不运行浏览器。执行器源码仅在开发目录 tools/performance-runner，未进入 WordPress 安装 ZIP；需要单独的受限运行环境，不能假设现有 PHP 镜像已经包含执行器。
