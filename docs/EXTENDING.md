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
- `chapters`：`series_meta`、`order_meta`、`document_meta`、`link_map`（Markdown 文件名到现有整数文章 ID）、`series_order`（稳定文档 ID 列表）。没有系列元数据时，已标记文档组成一个系列。
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

`PAGENEST_COMPANION_SERVICES_FILE` 指向独立、受限、Web 根之外的 JSON 文件，格式为 `{"schema_version":1,"services":{}}`；它不向原共享兼容 profile 增加字段，避免破坏其他组件的严格 schema。没有常量时，邮件、验证码、社交与输出模块均关闭。配置无效只停用这些服务，既有阅读功能继续可用。新增路径必须精确加入服务器读取许可，不扩大整个目录的读取权限。各模块必须显式 `enabled: true`；开关必须为布尔值，未知字段拒绝。凭据只能放受限、Web 根外的配置文件，不写公共源码、安装 ZIP、普通后台设置、浏览器或错误日志。配置变更应原子替换，先校验完整服务 profile，并保存私有原版。

- `mail`：`enabled`、`provider`（仅 `brevo`）、`api_key`、`from_email`、`from_name`、`reply_to_email`、`reply_to_name`。启用要求有效强制 From 和 API key；Reply-To 追加并沿用 API 的首个地址规则。WordPress 原生 `wp_mail` 构建、过滤和事件保留；成功表示 API 接收，失败不自动重试，避免超时后的重复投递。HTML／纯文本和普通附件可用，CID 内嵌资源明确失败，不承诺旧服务未提供的投递能力。
- `turnstile`：`enabled`、`site_key`、`secret_key`、`hostname`、`scopes`（`login`、`register`、`reset`、`comments` 布尔值）、`theme`、`language`、`size`、`appearance`。服务端检查 success、精确 hostname、action 和 300 秒期限；不缓存成功结果。浏览器到 Cloudflare 加载 widget，服务端调用 Siteverify；网络失败拒绝提交并提供重试。原生四表单覆盖不包括段落评论 REST、REST/XML-RPC 认证或 OAuth 回调。
- `social`：`enabled`、`allow_registration`、`avatar_enabled`、`share`、`providers`、`legacy_apps`。`share` 有 `enabled`、`posts`、`pages` 与 `providers`（`qq`、`weibo`、`qqzone`、`wechat` 列表）。`providers` 仅 `qq`、`sina`、`baidu`，每个包含 `enabled`、`client_id`、`client_secret`、`redirect_uri`，可选 `legacy_app_id`。回调必须 HTTPS 同源，迁移保持供应商已登记的精确 URL；QQ 身份按应用 OpenID，不根据 unionid 或邮箱合并。`legacy_apps` 显式映射旧提供者到应用 scope；无实现的历史提供者仅保留数据，不假定能登录。
- `output`：`enabled`、`html_whitespace`，默认均 false。可选处理只移除匿名公开 HTML 文档外壳缩进，保护正文和代码／公式／脚本；保留资源 URL 版本，无缓存、字体远程下载、数据库清理、测速或定时任务。

社交映射保存在 `_pagenest_social_bindings` 和以提供者／应用／subject 摘要命名的唯一 option 中。原 `open_type_*`、头像及旧 token 不删除或批量视作有效令牌。迁移为显式管理员维护动作，不在插件加载时运行；先 dry run、检查重复与原用户归属，再应用。解绑墓碑优先于旧字段，重复迁移不得复活已解除身份。迁移后出现新注册或绑定时，回退前必须带冲突守卫同步新增映射，不能仅恢复旧程序，更不能覆盖整库丢弃新的内容。

OAuth state 随机、限时、一次使用，绑定浏览器 cookie、提供者、应用、当前用户和登录／绑定操作；返回地址只允许同源。绑定和解绑要求登录、POST 与 nonce；未知异常不展示上游响应或身份。没有可恢复邮箱的账号必须保留至少一个当前可用提供者。新注册还遵守 WordPress 注册开关，创建普通 subscriber，不按提供者资料合并已有账号。社交按钮不会触发经验补发。

微信二维码使用 Kazuhiko Arase 的 MIT 授权 [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator/tree/83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8)，许可证随包保留；固定源 `js/dist/qrcode.js` 的 SHA256 为 `79ec86f82856005b1c887905cfccfcfbec3821ca61c7fd5a952faa5f778f791c`，经本仓库格式化后的资源由 manifest 标识。二维码本地生成，不向第三方二维码服务提交文章链接。其他分享按钮仅在点击后打开对应供应商网页，不自动发布微博。服务端请求强制验证 TLS，并限定官方提供者地址；供应商真实授权与实际邮件送达须单独验收，模拟 HTTP 不等于外部成功。

维护测试：`php tests/services-contracts.php /path/to/wordpress` 使用实际核心与 PHPMailer、模拟 HTTP，不加载生产数据库或发信；`php tests/social-contracts.php` 检查身份与回调契约；`npm run test:service-browser` 检查验证码可达性。所有维护测试文件和依赖排除出安装包。

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
