# PageNest Companion

为 WordPress 提供段落评论、章节导航、文章点赞与评论邮件通知。配合 PageNest · 栖页，让阅读外观与互动功能各有清楚的维护入口；其他主题也能使用独立评论抽屉。

Companion plugin for PageNest: paragraph comments, chapter navigation, likes, notifications and Markdown compatibility.

需要 **WordPress 6.0+、PHP 8.0+**。当前源码候选版本 **0.10.0-rc.1**，安装目录保持 `pagenest-compatibility`。

[安装与使用](docs/USAGE.md) · [更新记录](CHANGELOG.md) · [发行说明](docs/RELEASING.md)

![PageNest Companion 功能与权限示意](docs/assets/overview.svg)

## 开始使用

1. 下载已发布的稳定版插件 ZIP，或在隔离环境使用经验证的候选包，在 WordPress 后台 “插件 → 安装插件 → 上传插件” 安装并启用。
2. 打开 “设置 → PageNest Companion”，选择段落评论、章节导航和点赞。
3. 编辑一篇文章，在 “PageNest 阅读功能” 中明确启用段落评论，填写系列名称及章节顺序，然后保存。
4. 用两个不同账号检查评论和点赞；退出登录确认访客只能阅读公开内容。

关闭功能会保留既有记录。新文章默认不启用段落评论，插件不会批量扫描、注册或公开旧文章。

## 阅读与互动

- **段落评论**：选择正文段落后发布公开评论，需要登录并明确确认。读者可编辑或删除自己的内容；遇到并发修改会提示重新读取。兼容接入的私人笔记继续由作者本人读取，未知可见性保持私人。
- **章节导航**：同名系列按章节顺序关联上一篇与下一篇；只有有权阅读、未被密码锁定的文章才显示。旧 Markdown 章节链接可由管理员显式配置转换，正文原稿保持不变。
- **文章点赞**：登录后可给公开可读文章点赞，作者不能给自己点赞。重复请求和历史点赞不会重复计数；经验插件可以订阅事件，停用经验插件不影响点赞。
- **评论邮件**：审核通过的普通 WordPress 留言通知作者，回复通知原评论者，避免本人通知、重复投递和原生通知重复。默认使用站点现有邮件服务；可由服务器配置启用 Brevo API 传输，不提供历史通知补发。
- **编辑兼容**：保留逐篇编辑器选择及 Markdown 公式保护；配合支持的主题适配 Prism 和 MathJax。

## 浏览计数

浏览计数默认关闭；在设置页明确启用后，每次公开文章或页面加载计数一次，显示在 PageNest 文章底栏。可选所有人、访客或登录用户，以及已知机器人排除；`%VIEW_COUNT%` 模板只支持纯文本。读取或重复渲染不计数，计数不参与经验奖励。

仅支持无页面缓存的 PHP 渲染。`WP_CACHE=true` 时停止计数并提示管理员，已有计数保留；没有 AJAX、REST、排行榜、小工具或短代码。启用不扫描或迁移旧文章。其他主题可通过 `pagenest_article_actions` 显示，扩展读取接口见扩展指南。

## 可选站点服务

服务器管理员可在受限、Web 根之外的显式配置中分别启用 Brevo 邮件、Turnstile 原生表单验证、QQ／微博／百度登录，以及 QQ／微博／QQ 空间／本地微信二维码分享。默认全部关闭，不在后台展示或编辑 API key、应用密钥和验证码 secret。阅读功能与服务模块的开关互相独立。

旧邮件或验证码负责人仍加载、旧社交插件仍启用时，新模块不接管对应功能。迁移需先保存旧配置和身份数据，核对唯一映射，再切换负责人；不能按邮箱合并或删除账号。社交绑定／解绑需要登录、nonce 和当前会话近期身份确认；最后一个绑定只有在存在经过验证的恢复邮箱时才能解除。个人资料以安全确认、恢复邮箱和品牌登录卡片集中管理，并显示当前会话的一次性操作结果。未知社交身份会先选择创建新账号或登录已有账号后绑定；新建时填写必需的用户名／邮箱和可选昵称，不自动合并。验证码只保护原生登录、注册、请求重置密码与普通评论，不自动保护 REST、XML-RPC 或 OAuth。

邮件成功只代表 Brevo 接收请求，不等于送达；该 API 不支持 CID 内嵌资源，遇到此类邮件明确失败。可选输出处理默认关闭，保留资源版本参数，不提供页面缓存、数据库自动清理或定时任务。具体配置和限制见[扩展接口](docs/EXTENDING.md)。

## 可选内容展示

服务器可用独立的 `PAGENEST_COMPANION_CONTENT_FILE` JSON 配置启用资源目录、分段内容导览、文章阅读跳转、归档导览和章节展示。配置位于 Web 根之外；默认关闭，单个模块配置错误只停用该模块，其他阅读和站点服务继续运行。资源目录的发布标记、服务端目标检查及 private/no-store 边界保留；章节链接继续检查当前读者及密码权限。

正文、来源、菜单和私人记录不迁移。无 JavaScript 时保留完整原生正文与链接；搜索、关联入口和概览控件是渐进增强。旧 Site Extensions 展示负责人仍加载时，新内容模块关闭并提示管理员，避免两个负责人重复渲染。合成配置与提供方接口见[扩展接口](docs/EXTENDING.md)。

Optional content modules support resource directories, section guides, reading links, archive guides and chapter display through an independent validated server profile. They preserve native content, permissions and stable identities, with progressive controls and a single display owner.

## 旧站升级

先备份文件与数据库，再停用原来拥有同一功能的插件或桥接代码，避免两个组件同时写入。服务器管理员通过 Web 根之外的显式配置接入旧存储、路由和别名；后台操作只保存新的中性设置，旧记录不需要重写。配置错误时功能停止并提示管理员。

插件保护既有私人记录；它不提供积分政策或数据库重算。**Reader Experience** 是可选的独立扩展。

MathJax 2.7.7 按主题特性从 cdnjs 加载，会产生第三方请求，库文件不随包发行。升级后应按实际编辑器和邮件服务验证保存、渲染与投递。

## 文档与开发

[使用指南](docs/USAGE.md) 介绍配置、权限、编辑器和升级；[扩展接口](docs/EXTENDING.md) 记录服务器配置 schema；[贡献指南](CONTRIBUTING.md) 说明格式、真实 WordPress/数据库/并发及浏览器测试；[发行指南](docs/RELEASING.md) 说明校验与安装包。

采用 **GPL-2.0-or-later**，详见 [LICENSE](LICENSE)。版权归 PageNest Contributors（2026）所有；外部插件与远端加载库保留各自许可证。
