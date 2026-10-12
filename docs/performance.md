# 页面测速

本模块自 Companion 1.4.0 提供，默认关闭。每个部署者须先完成实际 WordPress FPM／容器集成验收，核对运行身份、私有队列映射与受限执行器，再显式配置启用；安装插件不会自动部署或启用执行器服务、浏览器或系统策略。管理员可在 “工具 → 页面测速” 选择公开代表页，提交给独立的服务器端 Playwright 执行器；PHP 不启动 shell，不发起 HTTP 请求，不注册 REST、前台资源或定时任务。

## 显式配置

服务器管理员先创建私有队列目录，再在服务器配置中设置：

```php
define('PAGENEST_COMPANION_PERFORMANCE_ENABLED', true);
define('PAGENEST_COMPANION_PERFORMANCE_DIR', '/private/performance-queue');
```

路径仅为示例。目录必须已经存在，绝对路径必须等于 realpath；目录及其父路径、现有文件不能有符号链接。目录必须位于 ABSPATH、服务器 Web 根、插件目录之外，不能赋予 others 权限（0700、0750 或 0770）；文件使用 0600。PHP 和执行器须以同一 UID 运行；0700/0600 为创建权限，组共享目录权限不意味着不同 UID 可以读取任务文件。后台不能配置路径或命令，模块不会创建运行目录。未启用时不读取选择 option、不访问队列磁盘，也不注册提交处理。

## 页面选择

最多选择 8 个页面，只保存首页、博客标识或公开文章/页面 ID，option 不自动加载。首页与博客入口从 WordPress 的 home_url、show_on_front、page_on_front、page_for_posts 推导。候选从最近最多 100 个公开文章/页面选出正文长度中位页、公式或代码页、图片/PDF 页、通用导览页，并结合实际健康公开工具注册表的 indexEntries 投影。内容不足时推荐数可能少于 6；这是代表样本，不是全站扫描。

任务提交时重新从 ID 解析页面，必须 publish、post/page、无密码且 is_post_publicly_viewable。URL 必须 HTTPS、与 home_url 同 host，无用户信息、查询、fragment 或非 443 端口。普通 ?p 链接本阶段不支持。敏感管理、登录、REST、XML-RPC、OAuth、preview 路径被拒绝；不能输入任意 URL。工具公开投影不包含外站直达或鉴权工具。权限为 manage_options，操作使用 POST 与 nonce，并返回 no-store。

## 队列协议

目录中的 queue.lock 以 flock 对提交、取消和执行器状态变更互斥；worker.lock 由执行器全局非阻塞独占持有。PHP 无法取得 worker.lock 或已有有效 queued/running 任务时拒绝新任务。不能仅因时间过期就启动第二个执行器。worker.lock 无人持有时，过期 queued/running 记录安全标记为 failed、worker_expired，允许后续任务。

报告名为 job-<32 位小写十六进制 ID>.json；新任务 schema_version=2，包含 id、status、created_at/updated_at/expires_at（UTC ISO8601）、profile、origin、pages、versions、results、error。状态为 queued/running/completed/failed/cancelled。profile 包含 device=desktop/mobile、cache_mode=fresh-context/paired、repeats=1/3。fresh-context 每轮仅首次访问；paired 每轮包含 fresh 首次访问和 warm 再次访问，同一对共享 HTTP 缓存，不跨页面或轮次共享。pages 项为 key=home/blog/post:<ID>、post_id、url、title、reason；versions 为 wordpress、companion、theme。

最多 8 页、3 轮；成对模式最多 48 次导航，每次导航最多 30 秒，任务总预算仍为 12 分钟，记录有效期为创建后 30 分钟。单报告上限 512 KiB，最多保留 10 份，队列目录总文件预算严格小于 5 MiB。超预算时拒绝提交并显示管理员清理提示，不自动删除报告，也没有后台删除入口。写入使用同目录独占临时文件、flush/fsync（支持时）和原子 rename，不接受符号链接文件。

queued 取消直接更新 cancelled。running 取消写 cancel-<ID> 文件，内容仅为 schema_version=1 与 id；执行器在测量中每 200 ms、导航之间及最终写入终态的 queue.lock 内检查；取消会中止正在执行的页面，异常收尾也重新检查取消标记，避免最后一次采样期间的取消被完成状态覆盖。后台采用手动刷新，没有轮询。

results 为扁平逐轮数组，每项包含 page_key、run、cache_phase=fresh/warm、status=ok/failed、http_status、ttfb_ms、fcp_ms、lcp_ms、cls、long_task_blocking_ms、request_count、transfer_bytes、failed_requests、blocked_requests、browser_cache_requests、cache_header、duration_ms、error。时间和 CLS 可为 null，HTTP 状态可为 null；browser_cache_requests 是 CDP 观察到的浏览器 HTTP 缓存请求数，与 origin 缓存独立。cache_header 仅 HIT/MISS/BYPASS/unknown，来自本轮网络主文档中执行器配置的缓存响应头（默认 X-Pagenest-Page-Cache）；主文档由浏览器缓存返回或 304 再验证时置 unknown，不复用存储的旧响应头。error 仅安全错误码；资源超限使用 resource_limit，活动取消的采样使用 cancelled，整任务到期使用 job_timeout。fresh 失败时 warm 标记 warmup_failed 且不再导航。environment 包含 runner=playwright-navigation-v3、node_version、chromium_version、network=server-local、cache_mode（与 profile 一致）、sample_window_ms=1500 和 policy_hash（网络保护版本、origin、批准 host 集合和规范化缓存响应头名的 SHA-256 摘要）与 limits_hash（固定应用预算、真实内核限制及可见祖先限制链、临时盘容量、Chromium 沙箱模式的 SHA-256 摘要）。祖先摘要不保存 cgroup 路径。不会保留 DOM、HAR、截图、原始头、console、异常文本或敏感 URL。

completed 表示任务执行完成，个别页面仍可能 failed。后台分 fresh/warm 展示每轮结果，每项成功有效样本各自的中位数及 N/重复次数。成对摘要仅使用同页同轮两个阶段都成功、且该指标都非 null 的配对，展示首次与再次值及逐对差值的中位数；不会把独立阶段中位数之差冒称配对差值。指标缺失保留为 null，不能当作零。

schema_version=1 的已有首次访问报告，以及 runner=v2 的第二阶段报告仍可查看、导出；缺少 limits_hash 的报告不参与当前历史数值对照。未执行的旧 queued 记录只在执行器取得两种锁后升级至 schema 2；含已有结果或执行环境的 queued 旧记录被拒绝。报告保留预算不变。

## 历史对照与导出

管理员可选择两份终态报告；只有两份均 completed、使用 schema 2、有环境记录，并且 origin、device、repeats、cache_mode 与全部执行环境字段一致才允许数值对照。页面逐项要求 key 与 URL 相同，按同一阶段成功有效样本的中位数显示前后值、绝对变化与百分比及各自 N；不匹配或缺样本给出原因。基线为零或缺失时不计算百分比。组件版本允许变更，并同时展示前后版本；内容、DNS、供应商和机器负载未固定，差值不能证明某项改动的因果效果，也不产生全站评分。

导出沿用私有 admin-post POST 操作，须 manage_options、nonce、模块启用及严格 32 位 ID；仅能导出 completed/failed/cancelled 终态报告。JSON 逐层只投影协议中的页面、数值、版本与安全环境字段，未知附加字段不导出，重新验证后以固定 job-ID.json 文件名返回 private, no-store 与 nosniff。不创建磁盘副本、公开下载 URL 或报告删除入口。

## 测量限制

执行器使用匿名隔离浏览器，通过独立 CDP Fetch 的 request 与 response 阶段拦截实施网络保护。主文档严格锁定任务目标 URL，服务端 3xx 重定向直接阻止，304 同 URL 缓存再验证除外；写方法、敏感页面、API、XHR/fetch/beacon、iframe、worker、WebSocket 与未批准资源请求均禁用。请求前清除 Cookie 存储并移除 Cookie header，响应剔除 Set-Cookie，同时禁用脚本读写 Cookie、弹窗、点击与表单提交。

每轮创建全新 browser context，首次访问记录为 fresh。paired 模式在同一 context 新建页面再次导航到相同 URL，记录为 warm；开始再次导航前清除该 origin 的全部非 HTTP 存储（包含 Cookie、localStorage、IndexedDB、CacheStorage 等），不保留 DOM 或 sessionStorage，网络保护继续生效。浏览器缓存仍服从服务器 Cache-Control；再次访问不保证命中。浏览器进程及操作系统 DNS 等不保证是冷状态。HTTP 缓存保持浏览器正常行为，不主动注入 no-cache/Pragma 请求头。若强制禁用浏览器缓存，Chromium 会附带请求指令，可能令严格的匿名 origin 缓存绕过，因此不能以这种请求判定缓存无效。服务端缓存状态仅观察允许的响应头，不触发清理或改变缓存策略。结果体现服务器本地网络与受控资源环境，不代表真实用户的完整浏览旅程。

默认等待 load 后再采样 1.5 秒；FCP/LCP/CLS 是这段观察窗口内的值。长任务阻塞代理值不是 Lighthouse TBT。目前没有 Lighthouse 评分或 INP。PageNestPerformance/1.0 精确空白分隔 User-Agent token 被 Companion 浏览计数忽略，与机器人排除开关无关；该 token 不赋予登录、权限或缓存豁免。第三方分析网络因控制策略可能被拦截。

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

开发依赖可通过已有工作区的 node_modules 复用，不自动下载浏览器。本地检查包含 PHP 与 Node 任务／报告往返、文件锁互斥、HTTP/TLS 重定向、Cookie、脚本弹窗与无限循环脚本收尾、首次／再次缓存命中、304 再验证、存储隔离、成对取消、可比条件和导出白名单，以及请求数、解码字节、HTTP/TLS 代理累计字节、并发连接上限、活动取消、总时限与进程／socket 收尾，不访问生产页面。沙箱单元合约另覆盖缺失证据、继承的 seccomp 过滤器、错误身份、取消后的迟到 launch／connect、串行清理，以及失败记录写完后终止主进程；这些 mock 不作为 OS 沙箱成功证据。成对缓存的 TLS fixture 在测试入口匹配临时证书公钥使用 SPKI 例外，避免通用证书错误绕过影响缓存测试；资源预算 TLS fixture 使用回环自签证书，不用于判断缓存命中。CLI 不提供这些测试例外。PHP 合约及本地页面测试不替代合并后的 WordPress 集成与浏览器验收。

## 资源限制与启动检查

应用预算固定在 budget.cjs，不接受后台、配置文件或环境变量扩大：每次导航最多 256 个请求、16 MiB 响应体（CDP 逐块取解码长度与编码长度较大值）；每个任务最多 128 MiB 代理流量（TLS 密文双向累计，包含握手与请求）、16 个并发代理客户端连接。导航最多 30 秒，任务最多 12 分钟。任何预算触发后停止新请求、关闭当前 context、保留已完成采样并终止任务；关闭迟迟不返回时强制结束浏览器。应用预算是停止阈值，事件／socket 分块可能产生一个批次的超出，并非内核层逐字节限流。

CLI 在读取配置、领取队列和加载浏览器依赖之前，读取真实 /proc 与 cgroup v2，确认非 root、NoNewPrivs=1、进程确实属于所读 cgroup。叶节点必须直接设置不超过 1 CPU、64–768 MiB 内存、零 swap、最多 128 个 task 的有限配额；只靠更上层限额不予放行。记录叶节点至已识别挂载根的可见祖先链，不能读取完整可见链时拒绝；隐藏于命名空间之外的祖先仍须部署者核对。TMPDIR 必须真实路径、无符号链接、自身 UID 所有、0700 等私有权限，文件系统须为容量不超过 256 MiB 的 tmpfs；普通宿主 /tmp 或 Web 目录被拒绝。环境变量不能模拟成功的内核检查，CLI 没有 fixture 放行选项。读取管理员配置后，还须对同一显式浏览器完成下述真实沙箱预检，成功关闭预检浏览器后才读取任务报告、创建 queue／worker 锁或领取任务；沙箱缺失时队列保持不变。预检预算不计入 12 分钟的页面采样预算，整个服务仍受 750 秒上限约束。

entry.cjs 供受限服务使用：先完成真实身份／cgroup 检查，确认父文件系统为有界 tmpfs，才在同一个进程与挂载命名空间创建私有 TMPDIR 子目录，再调用 CLI 重新验证。不会在普通宿主磁盘创建该目录，也不修复已存在目录的错误权限。不能把创建步骤放到 ExecStartPre：执行阶段的私有 tmpfs 可能不共享。

SIGTERM／SIGINT 转为取消信号，测量中主动停止浏览器，终态在 queue.lock 内保存并退出（143／130）。后台取消标记与信号在异常终态也检查。无法正常写终态的 OOM／强杀留下 running 记录，只有 worker.lock 已释放后的下一次手动执行／提交才按原有协议标记 worker_expired；不自动重跑，不借时间过期越过仍被占用的锁。

## 手动执行器与部署

执行器配置文件位于私有绝对路径，JSON schema_version 为 1，包含 queue_dir、origin、asset_hosts（最多 16 个批准资源 host），必填 browser_executable，可选 cache_header_name（默认 x-pagenest-page-cache）。响应头名须为 1–128 个合法 HTTP token 字符，不接受空白、冒号或控制字符，按小写规范化；只保留 HIT/MISS/BYPASS/unknown，不保留原始响应头值。更换响应头名会改变 policy_hash，因此报告不再满足同环境数值对照条件；已有报告仍可查看和导出。queue_dir 与 PHP 配置指向同一个私有目录，origin 与目标站点 HTTPS origin 一致。配置路径、浏览器路径和资源白名单由服务器管理员维护，不能从后台请求传入。按 tools/performance-runner/deployment/runner.json.example 创建实际配置，并核对每个目标路径与批准资源 host。

独立 runtime/package.json 与锁文件固定 playwright-core 1.55.1，不含整个插件开发依赖，不自动下载 Chromium。前轮隔离验证复用已有 Node 20.13.1、Playwright 1.55.1、Chromium 140.0.7339.186；每个部署者须验证实际精确版本、Chromium 系统库与受限身份，不能仅因机器存在另一个版本就代替此验证。执行器源码、runtime 依赖和浏览器均在 Web 根与插件 ZIP 之外维护。后台按钮仅排队，PHP 不运行浏览器。

以 tools/performance-runner/deployment/pagenest-performance.service.example 为模板，落实以下部署与验收步骤：

1. 为 Node 源码、runtime 依赖与 Chromium 分配服务 UID 不可写的 /opt 目录，配置文件放私有 /etc 目录；队列由 WordPress FPM 与执行器的同一非 root UID 维护，0700／0600。容器 FPM 须挂载同一目录，核对 UID 与现有 PHP 路径许可；不能把宿主 UID 名称相同当作映射成立。
2. 以锁定 runtime/package-lock.json 安装最小依赖（npm ci --ignore-scripts），浏览器由管理员显式准备并指定 browser_executable。不复用可被服务 UID 修改的源码或浏览器，也不把开发 node_modules 拷进插件 ZIP。
3. 替换示例的 User、Group 与路径，检查实际 cgroup v2 delegation／控制器和祖先限制。单次手动 service 配额为 CPU 50%、MemoryMax 768 MiB、MemorySwapMax 0、TasksMax 96、LimitNOFILE 1024、Node heap 128 MiB；/tmp 私有 tmpfs 256 MiB、/var/tmp 16 MiB。RuntimeMaxSec 750、KillMode=control-group、TimeoutStopSec 5、OOMPolicy=kill 负责异常收尾。服务没有 Restart、Timer 或 Install 自动启动目标。
4. 检查只读源目录、仅队列与临时盘可写、NoNewPrivileges、Chromium namespace sandbox，以及静态批准资源 host 与 DNS/出口策略。Ubuntu 启用 AppArmor 用户命名空间限制时，另行审查 deployment/pagenest-performance-chromium.apparmor.example：仅匹配独立、root 所有且服务 UID 不可写的 Chromium 可执行文件，路径须与 runner.json 完全一致。该 userns 例外按可执行路径自动附着，不给 Node 设置 AppArmorProfile，不需要特权 launcher，也不授予额外 capability。示例使用 flags=(unconfined)，不是完整的 AppArmor 文件访问隔离；文件写入边界仍由服务挂载保护，renderer 隔离由 Chromium namespace 与 seccomp 实现。核对精确可执行路径后显式安装并加载该策略，再验证实际受限身份。执行器入口强制 chromiumSandbox=true；不以 no-sandbox 或全局放宽系统策略绕过启动失败。代理与浏览器调试入口只监听回环，代理 DNS 全结果必须公网且使用已验证 IP，禁止私网／回环／链路本地及保留地址；本地 TLS／HTTP 放行仅存在于合成测试。
5. 在相同 User／Group、目录与保护开关下，把手动 service 的 ExecStart 临时替换为下述 preflight 命令，先做无任务、无外网访问的沙箱和临时目录验收。此时额外设置 PrivateNetwork=yes。预检成功后再做经过授权的小量公开代表页任务、活动取消与资源终止验收。之后才在服务器配置中打开 PHP 模块。启用后以管理员手动 start 一次处理一个排队任务；没有守护或定时调度。

2026-10-13 的前轮隔离实验在本机自动回收的瞬时合成服务中验证真实 cgroup 配额、私有 tmpfs 同进程准备、CPU 节流、task 上限、内存 OOM 与停止服务后的整组清理。内核实验把 MemoryMax 降至 128 MiB，OOMPolicy=continue 仅用于保留测试父进程读取 oom_kill 证据；这不是生产部署例子的 OOMPolicy=kill 验收。普通磁盘临时路径与无配额进程拒绝且不领取任务。该次实验未安装生产服务、创建生产队列或下载新的浏览器；此处是前轮隔离证据，不描述任何当前站点部署状态。

### Chromium 沙箱预检与 AppArmor 边界

```sh
/usr/bin/node --max-old-space-size=128 /opt/pagenest-performance/preflight.cjs --browser /opt/pagenest-browser/chromium-headless-shell/chrome-linux/headless_shell
```

命令须在模板的真实受限 service 内执行，不能用普通交互 shell 的成功替代。预检先检查真实身份、cgroup 与私有有界 tmpfs，再加载浏览器；不读取配置／队列、不领取任务、不创建锁、不启动代理，不导航网站。只接受显式、canonical、root 所有且不可由服务 UID 修改的浏览器路径，始终 chromiumSandbox=true，没有 fixture、no-sandbox 或环境变量降级开关。使用固定本地页面创建 renderer，检查宿主视角身份、NoNewPrivs、零 permitted／effective／ambient capability、renderer 增加的 seccomp 过滤器，以及 uid_map 与 NSpid 的用户／进程命名空间隔离证据；证据不可读或缺失时拒绝。输出只含安全检查摘要和版本，不输出 PID、路径、DOM 或原始异常。helper 使用统一的 12 秒操作期限、最多 500 ms 的异步结算余量、各 1 秒的关闭／kill 预算；取消后等待受限 launch／connect 结算，再串行幂等清理。若 Playwright 启动仍保留未知进程句柄，独立 preflight 与 CLI／entry 的失败出口在安全错误记录写完后主动退出 MainPID，由 service 的 KillMode=control-group 清理全部子进程；不等待 750 秒的最终 RuntimeMaxSec。未知进程未完成清理不能被当成沙箱通过。

2026-10-13 前轮隔离实验中，本机初次失败的内核记录为未匹配浏览器从 unconfined 转入 unprivileged_userns；该宿主策略拒绝 namespace 内 capability，导致 Chromium 报 No usable sandbox。现有 Chrome／Chromium 的路径规则不覆盖 Playwright 缓存路径。在独立 AppArmor namespace 内直接给 Node 设置 profile 的尝试仍未解决；宿主同时限制非特权 unconfined 转换，不能假定 systemd AppArmorProfile 即可替代浏览器的可执行路径附着。

修复在唯一、root 所有的临时 Chromium 副本上验证：仅加载与宿主官方 Chrome 同型的精确路径 userns 例外，不修改全局 sysctl、不覆盖既有 profile、不使用 no-sandbox 或特权浏览器。完整 service 保护开关、CPU 50%、内存 768 MiB、零 swap、96 tasks、Node heap 128 MiB、256 MiB 私有 tmpfs 与 PrivateNetwork=yes 下，本地固定页面正常渲染。宿主只读验收确认 browser／renderer UID 1000、NoNewPrivs=1、CapEff=CapAmb=0；renderer 的 user／pid／net 命名空间均与执行器不同，seccomp 过滤器由 browser 的 17 个增加至 renderer 的 18 个。服务 UID 本身可读取 uid_map、NSpid 和 seccomp 状态，无须 CAP_SYS_PTRACE。新的独立预检及空队列单次执行器在同一完整保护条件下通过；未匹配规则时独立预检与执行器均拒绝且不生成队列锁。合成启动挂起和 SIGTERM 用于检验失败期限与控制组收尾：独立预检和 CLI／entry 的挂起失败均约 13.8 秒退出，信号取消保留 143；未发现存活的测试进程，队列哨兵保持不变。

以上记录属于前轮 ubuntu 隔离身份与该锁定浏览器的本机实验，不描述当前部署状态，也不替代任何部署者的确切 WordPress FPM UID、容器映射、配置与网页验收。AppArmor 模板须由服务器管理员核对目标路径后显式安装；该次隔离实验的全部临时策略、服务和独立浏览器副本已移除，共用浏览器缓存未改。合成 fixture 关闭 OS 沙箱的测试仍只覆盖测量逻辑。

内核验证是独立、显式调用的本地实验，需 Linux cgroup v2、systemd 及非 root 账户的 sudo 权限；仅创建自动回收的临时单位，使用小额有限资源，不纳入常规 CI，也不访问网站：

```sh
npm run test:performance-kernel
```

模板语法检查、合成 PHP／浏览器／内核实验与真实 WordPress 集成／生产性能验收是不同层次；每个部署者须完成实际 FPM／容器集成验收后再显式启用模块。
