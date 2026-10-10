# 服务模块

由主插件在 `plugins_loaded` 前加载 `bootstrap.php`，读取独立受信服务器配置接口
`pagenest_companion_service_config('mail'|'turnstile'|'output')`。全部默认关闭，不写旧插件选项。
旧邮件／验证码负责人仍加载时不注册对应新服务；切换必须先明确交接负责人。

邮件通过 `pre_wp_mail` 安装 PHPMailer 子类并返回 `null`，由 WordPress 原生 `wp_mail`
执行地址／头／附件解析、各过滤器、`phpmailer_init` 和成功／失败事件。子类只接管发送：
Brevo `https://api.brevo.com/v3/smtp/email`，证书校验、禁止重定向、20 秒超时、无自动重试。
API key 和强制发件地址／名称只取服务器配置；配置 Reply-To 追加，按照旧 Brevo adapter
规则使用首个 Reply-To（API 只接受单个）。成功代表 API 接收，不代表投递到邮箱。
失败消息不包含 API 响应、请求、密钥或附件路径。不重发历史邮件，不修改评论通知 marker／锁。
占位收件域 `fake.com` 拒收。发送不经过 SMTP 连接，也不创建邮件队列。

支持 HTML／纯文本、抄送／密送、文件及字符串附件、HTML 的 AltBody 与自定义头。
非 UTF-8 正文需可用 `iconv`。Brevo transactional API 没有 CID 内嵌附件字段，
这种请求明确失败，不伪称内嵌资源已正确发出；旧 adapter 也不保证这项语义。
[Brevo 官方发送契约](https://developers.brevo.com/reference/send-transac-email)。

验证码只覆盖原生 `wp-login.php` 登录／注册／请求重置密码与 `wp-comments-post.php` 普通评论。
不拦截 REST、XML-RPC、OAuth 或 Companion 段落评论接口。每个 widget 的 action 为
`login`／`register`／`reset`／`comments`，中文、auto 主题、always 外观，窄评论容器 compact。
API 使用 defer，提交在成功前禁用，过期／网络失败提供可用键盘操作的重新验证按钮。
服务端检查 success、精确 hostname（或 `allow_hosts`）、对应 action 和 300 秒 TTL，
每次请求同一 token 仅验证一次，跨请求的一次使用由 Cloudflare Siteverify 保证。
网络、解析、缺字段、过期及错误均拒绝；不保存成功放行缓存。
[Cloudflare 服务端验证契约](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)。

输出模块默认关闭。本轮保留 WordPress 及非哈希资源 URL 的版本参数；不复制字体下载／分组，
没有速度收益承诺。可选 `html_whitespace` 只移除文档最外层 doctype/html/head 与
body/html 之间的缩进，不解析正文／head，不改代码、公式、JSON、属性或 inline 空格。
仅匿名公开无密码文章／页面的 GET，排除登录、后台、REST、XML-RPC、预览、搜索、feed、
查询参数、授权请求、Set-Cookie、私有／no-store 和非 HTML 响应。
不提供页面／对象缓存、CDN、数据库清理、测速、许可证或计划任务。
旧 Pro 保存缓存选项不表示有效缓存；不得开启 WP_CACHE 或安装 drop-in。
旧自动清理 cron 和重复 meta 的危险 SQL 不迁入；原事件精确移除属于独立部署操作，
不调用旧 Pro 停用清缓存代码或全局 flush。

离线契约：`php tests/services-contracts.php /path/to/wordpress` 使用实际 WordPress
pluggable／PHPMailer，HTTP 完全 mock，不 bootstrap 站点／数据库、不真实发送。
浏览器：`npx playwright test --config tests/services-playwright.config.cjs` 使用模拟 widget，不请求 Cloudflare。
