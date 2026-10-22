# 扩展与兼容接口

后台安装、编辑器选择和邮件注意事项见 [使用指南](USAGE.md)。

## 主题特性与加载条件

评论通知独立于主题。MathJax、Prism 前端配置与 Materialis Companion 自定义器防冲突处理仅在 `current_theme_supports('pagenest-independent-layout')` 为真时运行。MathJax 还要求文章或页面的单篇视图，并且 `mbb-math` 未入队。

## Prism 组件路径

`pagenest_prism_languages_path($path, $handle, $scripts)` filter 接收推导出的组件目录、autoloader 脚本 handle 和 `WP_Scripts` 对象。

- 默认从当前入队 autoloader 的标准路径推导 `components/`；非标准 autoloader 路径可从 core 依赖推导。
- 支持旧 wp-editormd 和其他提供方；仅注册但未入队的资源不会触发配置，已选择/输出的资源及活动依赖会参与检测。
- 自定义布局可返回 `HTTP(S)`、协议相对或站内绝对组件 URL。URL 不能含凭据、路径遍历、空白或反斜线；查询与片段会移除，最终目录统一补上斜线。
- 返回空字符串可禁用本插件的路径覆盖，保留 autoloader 自身配置；不会禁用高亮或停止组件加载。无法识别默认路径时也保持提供方配置。

## 编辑器兼容

`jetpack_markdown_preserve_pattern` 追加行内与块级公式保护模式并去重。`use_block_editor_for_post` 在文章元数据 `use_block_editor` 为字符串 `true` 时启用块编辑器，其余情况保留上游结果。编辑页选择框保存时校验 nonce、编辑权限并排除修订和自动保存。

## 旧站接入点

- `[2048_get_login_button]`：登录用户输出为空，访客输出返回当前页面的 WordPress 登录链接和提示；不提供成绩、积分或奖励逻辑。
- `pagenest_active()`：非后台、非 feed 且主题支持 `pagenest-independent-layout` 时为真。
- `pagenest_template_scope()`：首页、文章列表、文章/页面单篇视图、归档或搜索时为真，供已有资源范围适配器调用。
- Materialis Companion 兼容：在支持该主题特性时，移除来自其 `src/Companion.php` 的自定义器闭包回调，避免独立主题缺少 Kirki 类时的冲突；不负责停用插件。

> [使用指南](USAGE.md) · [贡献指南](../CONTRIBUTING.md) · [返回项目首页](../README.md)

## 兼容 profile schema 1

JSON 顶层必须含 `schema_version: 1`，其他节可按需指定，未知键拒绝。默认值见 `config.php` 的 `pagenest_companion_defaults()`。

- `theme`：`legacy_stylesheet`、`setting_prefix`、`menu_locations`、`anchor_prefixes`、`class_aliases`。后三项为旧名到新名映射；设置迁移仅在主题切换时补缺，不覆盖已配置的空值。
- `mail`：`sent_meta_keys`、`lock_option_prefixes` 字符串列表；只读旧标记和锁。
- `paragraphs`：`post_type`、`data_meta`、`uuid_meta`、`document_meta`、`registry_meta`、`library_meta`、`enabled_meta`、`rest_namespace`、`rest_aliases`、`shortcodes`。旧记录无需重写；所有兼容 namespace 使用相同权限和缓存策略。
- `chapters`：`series_meta`、`order_meta`、`document_meta`、`link_map`（Markdown 文件名到现有整数文章 ID）、`series_order`（稳定文档 ID 列表）、`series_directories`（系列原名到公开目录页面整数 ID 的映射，最多 200 项）、`document_series`（稳定文档 ID 到目录系列键的显式映射，最多 1000 项）。没有系列元数据时，已标记文档组成一个系列。
- `views`：可省略，默认 `legacy_meta=""`、`legacy_owner_function=""`。旧键仅在新键缺失时只读回退；旧负责人函数存在时停止计数与底栏展示。字段使用显式 ASCII 标识，不自动发现旧插件。回退键不得等于新存储键。
- `likes`：`counter_meta`、`record_meta`、`legacy_provider`。provider 含 `table_suffix`（加 WordPress 表前缀）、`user_column`、`post_column`、`event_column`、`event_value`、`record_column`、`record_prefix`。非空 post/event/record 条件同时成立；record 值为 `record_prefix + user_id + ':' + post_id`。表名和列名仅允许 ASCII 字母、数字、下划线，值通过 SQL 参数绑定。表不可读时拒绝新点赞。

扩展可直接调用 `pagenest_companion_like($user_id, $post_id)` 复用完整检查；必须为当前登录者。`pagenest_like_recorded` 在持久化成功并释放锁后发布，提供用户 ID、文章 ID、最新计数；订阅方自行保证事件幂等。

`experience` 是可选 Reader Experience 扩展配置节，包含 `table_suffix`、`live_option`、`rank_option`、`week_option`、`event_lock`、`weekly_lock`、`rest_namespace`、`rest_aliases`、`shortcodes`、非负整数 `panel_page_id` 和 `weekly_hook`。Companion 仅校验配置，不安装账本、重算余额或定义经验规则。

## 后台写入与功能开关

`pagenest_companion_features` option 包含 `comments`、`chapters`、`likes` 的 0/1 值；无 option 时保持三个模块可用。存在但格式错误的 option 停用功能，不回落到宽松状态。评论仍须逐篇启用。

后台只写 `_pagenest_comments_enabled`、`_pagenest_series`、`_pagenest_chapter_order`。这些键存在时优先于 profile 的旧存储键，包括显式空值与停用值。nonce、编辑权限、修订/自动保存及整份输入验证通过后才保存；显式启用只刷新对应文章注册表。

Reader Experience 的 `weekly_lock` 必须为不超过 64 字节的标识，按原值取得数据库锁以与其他周结算组件共用；`event_lock` 继续按数据库作用域隔离。

## 浏览计数接口

`pagenest_companion_views_settings` 是独立 option，包含 `enabled`（默认 0）、`count`（0 所有人、1 访客、2 登录用户）、`exclude_bots`（0/1）和 `template`（最多 500 字节的纯文本）。不加入既有三项功能开关，不改其缺省语义。计数存于 `_pagenest_companion_views`。

`pagenest_companion_views_get($post_id)` 优先读取新键，缺失时回退到明确配置的旧键，返回整数；重复／无效／超界原始行抛出异常。`pagenest_companion_views_increment($post_id)` 返回新次数或 `WP_Error`，仍检查启用、公开可读文章类型、人群、缓存与旧负责人，且不会调用经验事件。首次计数在每文章数据库锁内承接旧计数，后续原子增量；整数溢出或数据库失败拒绝写入。`pagenest_article_actions` 上的展示只读，不提供旧全局函数或短代码。

The optional `views` profile section accepts explicit `legacy_meta` and `legacy_owner_function` identifiers. Omitted fields stay empty. Reads prefer the new key and only fall back when absent; an existing legacy owner function disables counting and footer output. The new independent settings option defaults to disabled, and `_pagenest_companion_views` holds counts. The read API throws on malformed or duplicate metadata; increment returns an integer or `WP_Error`, serializes initialization and increments with a per-post database lock, and never emits experience events. Page caching is unsupported.

## 可选服务配置

### 教学内容与段落身份

段落注册保留隐藏教学阶段、折叠中的作者正文，不按当前阅读模式过滤。`data-mbb-generated` 或 `data-pagenest-generated` 节点及后代不生成段落身份；作者段落中的这类临时展示文字也不参与指纹。因此播放状态、运行输出和导航不会改变既有评论定位。回到正文时，若存在 `window.MarkBridgeTeaching.revealTarget(target)`，先展开目标所属内容，再滚动并移动焦点；不复制、重写正文或迁移评论记录。

`PAGENEST_COMPANION_SERVICES_FILE` 指向独立、受限、Web 根之外的 JSON 文件，格式为 `{"schema_version":1,"services":{}}`；它不向原共享兼容 profile 增加字段，避免破坏其他组件的严格 schema。没有常量时，邮件、验证码、社交与输出模块均关闭。配置无效只停用这些服务，既有阅读功能继续可用。新增路径必须精确加入服务器读取许可，不扩大整个目录的读取权限。各模块必须显式 `enabled: true`；开关必须为布尔值，未知字段拒绝。服务器凭据放受限、Web 根外的配置文件，不写公共源码、安装 ZIP 或错误日志。社交供应商也可由管理员通过独立后台配置入口保存覆盖值，密钥不回显；覆盖值随数据库备份，应与站点凭据同等保护。配置变更应原子替换，先校验完整服务 profile，并保存私有原版。

- `mail`：`enabled`、`provider`（仅 `brevo`）、`api_key`、`from_email`、`from_name`、`reply_to_email`、`reply_to_name`。启用要求有效强制 From 和 API key；Reply-To 追加并沿用 API 的首个地址规则。WordPress 原生 `wp_mail` 构建、过滤和事件保留；成功表示 API 接收，失败不自动重试，避免超时后的重复投递。HTML／纯文本和普通附件可用，CID 内嵌资源明确失败，不承诺旧服务未提供的投递能力。
- `turnstile`：`enabled`、`site_key`、`secret_key`、`hostname`、`scopes`（`login`、`register`、`reset`、`comments` 布尔值）、`theme`、`language`、`size`、`appearance`。服务端检查 success、精确 hostname、action 和 300 秒期限；不缓存成功结果。浏览器到 Cloudflare 加载 widget，服务端调用 Siteverify；网络失败拒绝提交并提供重试。原生四表单覆盖不包括段落评论 REST、REST/XML-RPC 认证或 OAuth 回调。
- `social`：`enabled`、`allow_registration`、`avatar_enabled`、`share`、`providers`、`legacy_apps`。`share` 有 `enabled`、`posts`、`pages` 与 `providers`（`qq`、`weibo`、`qqzone`、`wechat` 列表）。`providers` 支持 `qq`、`sina`、`baidu`、`github`、`google`、`wechat`，每个包含 `enabled`、`client_id`、`client_secret`、`redirect_uri`，可选 `legacy_app_id`（仅前三个旧供应商可非空）。回调必须 HTTPS 同源，迁移保持供应商已登记的精确 URL；QQ 身份按应用 OpenID，不根据 unionid 或邮箱合并。`legacy_apps` 显式映射旧提供者到应用 scope；无实现的历史提供者仅保留数据，不假定能登录。
- `output`：`enabled`、`html_whitespace`，默认均 false。可选处理只移除匿名公开 HTML 文档外壳缩进，保护正文和代码／公式／脚本；保留资源 URL 版本，无缓存、字体远程下载、数据库清理、测速或定时任务。

社交映射保存在 `_pagenest_social_bindings` 和以提供者／应用／subject 摘要命名的唯一 option 中。原 `open_type_*`、头像及旧 token 不删除或批量视作有效令牌。迁移为显式管理员维护动作，不在插件加载时运行；先 dry run、检查重复与原用户归属，再应用。解绑墓碑优先于旧字段，重复迁移不得复活已解除身份。迁移后出现新注册或绑定时，回退前必须带冲突守卫同步新增映射，不能仅恢复旧程序，更不能覆盖整库丢弃新的内容。

OAuth state 随机、十分钟有效、一次使用，绑定浏览器 cookie、提供者、应用、当前用户、当前 WordPress 会话与登录／绑定／身份确认操作；返回地址只允许同源。绑定和解绑要求登录、POST、nonce 与十分钟内的显式身份确认；密码变化或切换会话使确认失效。可用当前密码或已归属于本人的当前供应商重新确认；新授权身份不能替代原账号认证。密码确认每个账号十分钟最多五次尝试，历史登录不会自动获得近期认证。未知异常不展示上游响应或身份。

恢复邮箱必须通过本模块验证，不能仅靠邮箱格式或历史字段。用户保存有效邮箱后，近期认证并主动发送验证邮件，每账号每小时最多三次；三十分钟一次性令牌只以摘要存储。GET 只显示确认页，登录对应账号后 POST 与 nonce 才消费令牌并验证当前邮箱；邮件发送成功不等于验证成功。`_pagenest_social_verified_email` 记录精确邮箱摘要与验证时间，实际邮箱变更撤销验证和未完成挑战。没有已验证恢复邮箱时，必须保留至少一个当前已配置且 owner 一致的供应商；解绑保留墓碑，不删除账号。

未知第三方身份返回后不会自动注册：最小身份与白名单头像进入十分钟、匿名浏览器绑定、一次消费的待选择记录，不保存供应商令牌。用户选择创建后先填写资料，用户名／邮箱遵循核心注册的必填、格式和重复检查，昵称选填。错误保留资料与待注册凭据；实际创建在待注册、用户名／邮箱及身份锁内重新校验，成功后才消费授权并建立普通 subscriber，不自动验证邮箱，不新增注册通知邮件；若身份已被其他请求注册／绑定则拒绝，不能转而登录该账号。选择已有账号时丢弃授权记录，登录后到个人资料重新授权绑定。登录／退出清除待选择 cookie。原已绑定用户继续登录原 ID，不按邮箱或昵称合并。社交按钮不会触发经验补发。

微信二维码使用 Kazuhiko Arase 的 MIT 授权 [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator/tree/83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8)，许可证随包保留；固定源 `js/dist/qrcode.js` 的 SHA256 为 `79ec86f82856005b1c887905cfccfcfbec3821ca61c7fd5a952faa5f778f791c`，经本仓库格式化后的资源由 manifest 标识。二维码本地生成，不向第三方二维码服务提交文章链接。其他分享按钮仅在点击后打开对应供应商网页，不自动发布微博。服务端请求强制验证 TLS，并限定官方提供者地址；供应商真实授权与实际邮件送达须单独验收，模拟 HTTP 不等于外部成功。

维护测试：`php tests/services-contracts.php /path/to/wordpress` 使用实际核心与 PHPMailer、模拟 HTTP，不加载生产数据库或发信；`php tests/social-contracts.php` 检查身份与回调契约；`npm run test:service-browser` 检查验证码可达性。`tests/social-security-wordpress.php` 在合成 WordPress 验证近期认证、邮箱与注册规则；`tools/social-security-concurrency.py` 验证注册、邮箱及待选择记录的双进程竞争，已接入 WordPress CI。浏览器可通过 `PAGENEST_SOCIAL_SECURITY_UI_ROOT` 使用合成核心生成的表单，检查桌面／手机布局及表单归属。所有维护测试文件和依赖排除出安装包。

用户删除后的 `deleted_user` 钩子只在确认用户表中原 ID 已不存在后清理其 owner 与解绑记录，身份锁内重新读取并核对归属；锁忙或数据库失败保留记录，登录／绑定的孤立 owner 核验作为历史残留兜底。不在删除前释放身份，也不删除转移给存活用户的记录。

GitHub 和 Google 使用每次授权独立的 S256 PKCE；Google 同时绑定 nonce，并通过固定官方证书地址校验 RS256、issuer、audience／azp、时间与 subject。需要 PHP OpenSSL；证书缓存遵守响应期限且最多一小时，未知 key 最多刷新一次，不访问令牌自带的 key URL。GitHub 仅使用数字用户 ID；Google 使用 subject；微信网站扫码仅使用当前应用 OpenID，严格比对令牌与资料响应。三者均不按邮箱、昵称或 UnionID 自动归并。旧 GitHub 字段与历史作用域只保留，不能通过新增配置自动接管；新供应商 client_id 与同名历史作用域相同时，配置拒绝加载。

新增供应商配置与人工验收步骤见 [使用指南](USAGE.md#新增社交供应商)。

## 社交后台覆盖

`pagenest_social_settings` option 使用 `schema_version: 1`，仅包含 `switches`（`enabled`、`allow_registration`）和 `providers`（提供者到 `enabled`、`client_id`、`client_secret`、`redirect_uri` 的映射）。未指定项继承服务器服务 profile；没有服务器文件时继承默认关闭值。后台不提供修改 `legacy_apps`、`legacy_app_id`、邮件、验证码、分享或输出节的入口。服务器 profile 无效时所有可选服务继续关闭，数据库覆盖不能绕过；后台覆盖损坏时仅关闭社交模块。

该 option 不注册 REST 设置，不自动加载，不在首次访问页面时复制服务器密钥。管理员写入走独立 POST／nonce，供应商逐个保存且完整验证最终配置；任何失败保持已存配置。密码字段从不回填原密钥，空值保留当前有效密钥，清除须明确提交且停用提供者；更换 Client ID 必须同时更换密钥或禁用清除。恢复继承会重新采用服务器当时的开关与凭据。后台覆盖随 WordPress 数据库备份，不能视为与数据库隔离的秘密存储。

### Google 服务端临时出口

`social.google_proxy` 仅由服务器服务 profile 配置，默认 `{"enabled":false,"unix_socket":""}`，后台数据库覆盖不接受此字段。显式启用时，`unix_socket` 指向 PHP 进程可访问的绝对 Unix socket 路径（最长 107 字节，仅字母、数字、`_`、`.`、`/`、`-`，不允许 `..` 路径段）。服务器须自行提供受限 SOCKS5 relay；relay 仅允许 `oauth2.googleapis.com:443` 和 `www.googleapis.com:443`，可再连接管理员配置的上游 HTTP CONNECT 出口。插件不创建隧道或管理上游代理。

该出口只覆盖精确的 Google token 与证书 URL，不影响其他供应商、邮件、验证码或一般 WordPress HTTP。要求 PHP cURL、Unix socket 支持和 libcurl 7.84.0+，通过 `socks5h://localhost/绝对路径` 连接 relay；不能用 `CURLOPT_UNIX_SOCKET_PATH` 叠加 HTTP proxy，因为该选项会忽略代理而直接发送 TLS。插件每次请求临时强制 cURL，清除全局代理认证和绕过规则，保持 TLS 证书／主机名验证、禁止重定向，并在成功或异常后移除所有临时钩子。缺少 socket、能力不支持或未实际使用指定传输时直接失败，不回退直连。停用后恢复普通 WordPress HTTP 路径。

本地回归：`php tests/social-google-proxy.php /path/to/wordpress` 使用真实 WordPress 钩子和 cURL，仅向测试自行创建的 Unix socket 发送 SOCKS5 握手，不访问 Google。协议依据：[libcurl SOCKS Unix socket](https://curl.se/libcurl/c/CURLOPT_PROXY.html)、[7.84.0 发行变更](https://curl.se/ch/7.84.0.html)。

## 可选内容配置 schema 1

`PAGENEST_COMPANION_CONTENT_FILE` 是独立、受限、Web 根之外的 JSON 文件，不修改共享兼容 profile 或 services schema。顶层仅接受 `schema_version: 1` 和 `modules`；可省略模块。每个模块的 `enabled` 必须为布尔值，未知字段拒绝；多个模块声明同一页面 selector 时关闭这些冲突模块。错误只关闭相应模块并显示固定模块名提示，文件或顶层错误关闭全部内容模块。未配置时不注册内容过滤器。读取路径需精确加入服务器许可。配置是数据，不能提供正则、JavaScript、SVG、HTML 或可执行模板。

`pagenest_companion_content_config($dotKey, $default)` 只读已校验配置，供外部维护工具使用；例如 `modules.archives.items`。它不执行同步、初始化或存储迁移，不提供旧插件全局函数。多个配置实例使用明确的 `selector.value` 定位；维护工具不能把数组第一项假定为站点特定入口。

合成的社区参观导览可以配置为：

```json
{
  "schema_version": 1,
  "modules": {
    "guide": {
      "enabled": true,
      "labels": {
        "controls": "Visit guide",
        "overview": "Section overview",
        "expand": "Expand stops",
        "list": "{count} visit stops"
      },
      "pages": [
        {
          "selector": { "meta": "_community_guide", "value": "festival-visit" },
          "targets_meta": "_community_targets",
          "sections": [
            {
              "heading": { "text": "Arrival", "level": 2 },
              "anchor": "arrival",
              "list_heading": { "text": "Visit stops", "level": 3 },
              "list_kind": "ol",
              "default_open": true
            },
            {
              "heading": { "id": "venue-existing", "level": 2 },
              "list_heading": { "text": "Suggested stops", "level": 3 },
              "list_kind": "ul",
              "default_open": false
            }
          ]
        }
      ]
    }
  }
}
```

- `resources`、`guide`、`reading_links` 均使用 `pages` 列表。每页 `selector` 必须包含 ASCII 元数据键 `meta` 和精确值 `value`。`sections` 按配置顺序匹配标题；`heading` 和可选 `list_heading` 各使用 `level` (1–6) 以及精确 `text` 或已有 `id`，两者不能同时提供。可选 `anchor` 仅为缺少 ID 的标题补锚点；已有标题 ID、历史前置空锚点优先并保留，不制造重复 ID。`label` 提供控件文字；`list_kind` 为 `ul` 或 `ol`；`start_list: true` 只将原生起点列表展示为入口网格，`default_open` 决定增强阅读清单的初始状态。
- `resources`、`guide` 要求 `targets_meta`，读取已有的 URL → 整数文章 ID 映射，缺失或非数组时正文投影关闭。仅实际公开、无密码的文章／页面可建立内部链接；映射值 `0` 的外部链接还必须精确匹配该页 `external_links` HTTPS 白名单，包括斜线、查询与片段。未批准的列表条目移除，其他正文中的未批准链接保留文字并移除目的地址。本地片段链接保留。资源目录及分段导览在前台和 REST 维持 private/no-store，前台同时设置 `DONOTCACHEPAGE`。
- `resources` 每页还要求 `release_meta` 和纯文本 `excerpt`。明确字符串 `"1"` 的发布标记允许公开阅读；有编辑权限者可预览未发布正文，但目标链接仍只向公开、无密码内容投影。未经标记的状态变更限制为 draft/trash/auto-draft。`provider` 默认 `none`，可选 `site_tools` 只通过公开 `SiteTools\Registry::indexEntries()` 投影装饰信息。`icons` 是条目 slug → 可信内置名称，支持 `dice`、`text`、`dinosaur`、`tiles`、`fibonacci`、`graduate`、`medal`、`dinosaur-medal`、`tiles-calendar`、`tiles-trophy`、`fibonacci-calendar`、`fibonacci-trophy`、`graduate-trophy`、`image`、`cloud`；没有提供方时使用通用图标，不创造入口。`groups` 是父 slug → `[{"slug":"child","label":"Related entry"}]`，不接受重复、嵌套或自身分组；只关联当前已获准的原生链接。
- `reading_links` 将匹配章节的跳转链接直接输出到原生正文，无 JavaScript 也可使用。可选 `targets_meta` 启用同样的内部目标投影；未提供时不过滤原文链接。`table_scroll: true` 只增加该正文表格的横向滚动，表格视觉沿用主题。
- `archives` 使用 `items` 列表；每项为 `{taxonomy,term_id,intro,groups,start_posts}`，`taxonomy` 只接受 category/post_tag，`groups` 为 `{taxonomy,term_id}` 列表，`start_posts` 为已有整数文章 ID。系列目标需要实际公开无密码文章，阅读入口也须公开无密码。只在第一页、非后台/feed/REST 的首个主循环插入一次；不改变分类、菜单或文章。
- `chapter_display` 使用 `document_meta`、`heading_aliases_meta`、`library_meta`；`hide_first_title` 可选布尔值。标题、重复标题与历史别名只在展示时补齐；已有 ID 和空锚点保留。返回目录使用现有 `pagenest_companion_can_read()`，私人目录要求当前用户权限，锁定密码目录不显示。过滤优先级为 30，后续相对链接为 32、段落身份为 35。间距只处理展示文本，跳过代码、属性、TeX、段落气泡和私人界面，不写回正文或注册表。

各模块可提供纯文本 `labels`：resources 支持 `search`、`placeholder`、`category`、`all`、`reset`、`empty`、`count`、`filtered_count`、`related`、`show_related`、`hide_related`、`matched_related`、`group_hint`；guide 支持 `controls`、`overview`、`expand`、`list`、`closed`、`opened`、`partial`；reading_links 支持 `navigation`；archives 支持 `navigation`、`groups`、`starts`；chapter_display 支持 `navigation`、`return`。缺省使用通用英文文字，`{count}` 仅作数字替换。

`pagenest_companion_resource_entries` filter 接收提供方条目数组和当前页面配置；返回的条目使用 `slug`、`canonical_url`。该接口只装饰已批准链接，不能授权链接或调用工具、成绩、游戏或经验业务。无效或有歧义的提供方条目忽略，授权仍完全依赖服务端目标映射与白名单。

加载在 `plugins_loaded` 完成后检查唯一展示负责人；旧 Site Extensions 仍加载时关闭全部新内容模块并提示管理员。切换程序和配置应共同验收，保留恢复点，不能让两个负责人同时运行。安装包不含旧 Editor.md 预览代码、专用 emoji/math 分支、同步执行器或本站配置；现用 MarkBridge 渲染与 API 保持。

开发验证：`npm run test:content` 使用非学习类合成配置检查独立配置、访问、发布、投影幂等与启动负责人；`npm run test:content-browser` 检查桌面／手机、搜索、分组、键盘折叠、历史锚点、无 JavaScript 和展示间距。这些合成检查不代表生产 WordPress、真实手机或 Safari 已验收。

## 媒体展示配置

可选根字段 `library_taxonomy`（默认空字符串）指定附件层级分类法。启用后仅对具备上传和该分类法分配权限的用户显示媒体库网格文件夹导航；分类法提供者必须支持媒体集合的同名 slug 筛选属性。导航不写分类关系，刷新接口校验 nonce 和权限，最多显示 5000 个目录。

`library_provider` 默认为 `external`，保持原组织插件负责目录。显式设为 `native` 时由 Companion 注册附件层级分类法；未指定名称时使用 `pagenest_media_folder`，接管既有目录则保留原 `library_taxonomy`。不复制或改号，不覆盖已注册的分类法，不自动停用原插件。自有分类法不公开查询、不暴露 REST；管理员管理目录，上传者仅能分配自己有编辑权限的附件。分配页使用 WordPress nonce，先完整校验所选附件与目录，再追加／移除关系；中途失败保留已完成部分并明确提示。筛选与原查询条件取交集，原有媒体权限保持有效。目录归属仍是 WordPress 标准 term relationship，关闭或移除本功能不删除数据。

媒体模块使用 WordPress option `pagenest_companion_media`。迁移工具在 WP-CLI 中先调用 `pagenest_companion_media_validate($input)`，再以 `update_option()` 保存验证后的配置。后台设置只修改启用开关并保留映射；没有配置或配置非法时关闭模块。不要在前台请求中执行迁移。

```json
{
  "schema_version": 1,
  "enabled": false,
  "galleries": {
    "17": {
      "attachment_ids": [11, 12],
      "source": {
        "taxonomy": "media_folder",
        "term_ids": [9],
        "include_children": false
      },
      "layout": "slider",
      "columns": 3,
      "gap": 15,
      "size": "large",
      "target_size": "large",
      "link": "file",
      "orderby": "random",
      "order": "ASC",
      "autoplay": true,
      "interval": 2000,
      "lightbox": true
    }
  }
}
```

`source` 可省略；来源附件与 `attachment_ids` 取并集、去重，并按当前阅读权限过滤。标识必须为正整数，布尔值不接受字符串。`layout` 支持 `slider/grid/masonry`；`orderby` 支持 `random/ids/date/title/id`；`link` 支持 `file/post/none`。旧短代码中的已支持展示参数覆盖映射中的对应默认值。PDF 仅接受已知本地 PDF 附件，不请求任意远端 URL；主题并非依赖项。

独立 PDF.js 6.4.299 legacy 查看器位于 `assets/vendor/pdfjs-6.4.299`。维护者从官方发行下载 ZIP，以 `python3 tools/vendor-pdfjs.py /path/to/archive.zip` 验证固定 SHA256 并导入；保留所有许可证、字体与解码资源，排除示例 PDF 和 source maps。六处打包层面的等值 Unicode 转义记录在 `NOTICE.md`，工具校验解析后的字符串值不变。模块文件与引用统一采用 `.js` 扩展名，避免服务器缺少 `.mjs` MIME 映射时加载失败，ES 模块语义保持。`npm run test:media` 检查 PHP 边界及完整 vendor 文件哈希和安装白名单；vendor 不参与格式化。

## Bounded reading queries

`/{paragraph namespace}/comments-page?post=ID&cursor=ID` returns `items`, `has_more`, and
`next_cursor`. Only decoded public records produce public cursors. Each request scans at most 400
metadata candidates; exhausted budgets return a generic 503 rather than a private cursor or false end.
The existing array routes remain compatible and now use chapter metadata as a database prefilter.
Serialized metadata matches never replace decoded post identity, visibility, ownership or read checks.

Authenticated `my-records` supports `post`, `q`, `kind` (`all`, `public`, `author_only`, `needs_review`)
and an exclusive ID `cursor`. It scans 40 owned candidates and may return an empty visible page with a
next cursor. Clients must keep paginating explicitly. `blocks` offers read-only replacement paragraph
choices; `my-records/validate` checks up to 200 owned IDs without returning private text. Writes reuse
`notes/{id}` with its opaque version; no load-time migration, cron or relationship rewrite occurs.

`POST /pagenest/v1/reading/resolve` accepts at most 100 IDs and returns only currently public,
unprotected, discoverable entries and reliable author heading IDs. It saves no reading history.
`pagenest_companion_reading` is the string `1` only when enabled. The independent guide option is
`pagenest_companion_guide` (`enabled`, `groups: [{title, items: [post IDs]}]`); it never overrides the
server-owned content provider. All new UI messages use `pagenest-companion` gettext, with bundled
Simplified Chinese and deterministic `npm run i18n` / `npm run i18n:check` catalogs.

## 系列目录关系

`chapters.series_directories` 和独立 option `pagenest_companion_series_directories` 使用同一种映射：`{"系列原名":123}`。系列名称沿用文章当前有效的 `series` 值，按原字符串精确匹配，不生成 slug，也不从文档 ID、分类或页面标题猜测。每个名称只能关联一个目录页面；原有章节身份、顺序和文档回退不变。服务器映射逐项优先于后台；后台中被覆盖的值保留，移除服务器项后恢复。内容导览设置不参与这套关系。

`pagenest_companion_series_directory($post_id)` 只返回已发布、无密码的 page 及其完整公开祖先；目录、祖先缺失、非公开、受密码保护或成环时返回空数组，对管理员也相同。无映射不生成新目录。旧章节展示的返回入口在无有效映射时沿用原 library 标记；主题侧栏通过 `pagenest_series_directory` filter 接收目录数据，已存在章节顶部返回入口时不重复显示。SEO 使用同一 resolver，映射不可用时保持原分类路径。

阅读设置页保存整份后台映射，校验 manage_options、专用 nonce 和表单原值摘要。失效表单、遗漏原有关联或任一无效目录都拒绝整份保存，保留旧值。选择器只列出最多 200 个公开页面，可填写其他公开页面 ID；既有关联全部保留在表单中。运行时仍重新验证目录和祖先状态，后续变为私人或草稿的目录不会进入公开导航或 SEO。

旧文章没有系列元数据时，可由服务器显式配置 `chapters.document_series: {"document-a":"manual-series"}` 和 `chapters.series_directories: {"manual-series":123}`。`pagenest_companion_directory_series($post_id)` 优先使用文章现有非空系列字符串，仅在其为空时读取所配置 `document_meta` 的显式绑定。此绑定只服务于目录关系，不改变同系列推荐的旧文档分组或章节排序，不写入文章元数据；未列出的文档不会推断归属。后台显示服务器托管的目录关系及文档绑定数。
