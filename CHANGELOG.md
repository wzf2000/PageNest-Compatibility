# Changelog

## 1.2.0-rc.1

- Experimental, default-off generic tools host with trusted implementation providers; optional tools ship separately.

## 1.1.1

- 媒体库网格补回左侧文件夹导航，支持层级展开、搜索、全部文件与未分类快捷入口、目录直接归属数量和管理入口。
- 目录树、卡片与筛选下拉框同步，保留媒体搜索和排序；窄屏改为上方可滚动导航，不挤压图片网格。
- Restore accessible folder navigation without adding a third-party dependency or changing media relationships.

## 1.1.0

- 新增可选自有媒体文件夹管理，可独立创建、改名、调整层级和删除目录，支持附件多目录归属；无需媒体组织插件，不移动文件或改变链接。
- 媒体网格、列表与插入媒体窗口可按目录筛选；批量分配只增加或移除所选归属，保留其他目录。写入校验操作凭据和逐附件编辑权限。
- 原目录可通过显式配置保留分类法、目录 ID 和既有关系；原管理插件仍启用时不抢占所有权，关闭本功能也不删除数据。
- Add opt-in native media folders with permission-checked additive assignments and preserved taxonomy identities.

## 1.0.0

- 首个稳定主版本，汇总自公开版 0.6.1 以来的可选站点服务、内容与媒体模块，保留段落评论、私人记录保护、作者操作、章节与点赞接口。
- 提供默认关闭的浏览计数、Brevo 邮件、原生表单 Turnstile、QQ／百度／微博／GitHub／Google／微信网站应用登录与本地分享；身份按供应商／应用隔离，不按邮箱合并。
- 社交管理包含近期身份确认、验证恢复邮箱、首次注册资料、管理员配置与会话绑定提示；配置及凭据仅由授权管理员管理。
- 配置驱动的资源目录、通用内容导览、阅读跳转、归档与章节展示保留正文、来源、历史锚点和权限；无 JavaScript 时原生内容仍可用。
- 画廊／PDF 兼容保留旧正文标记，提供平滑轮播、键盘／触摸灯箱、本地 PDF 查看器及可选媒体文件夹浏览；改善控件、说明区与文件链接样式。媒体上传和目录编辑仍由 WordPress／媒体组织插件负责。
- 从 0.6.1 或后续候选原位升级，目录仍为 pagenest-compatibility；新模块不自动启用，不自动迁移、删除旧数据或卸载旧插件。先备份并在隔离环境验证旧功能负责人和显式配置。
- Existing identities, private records and content remain intact. Optional services, content and media modules require explicit configuration; view counting does not support full-page caches.

## 0.11.0-rc.1

- 新增默认关闭的画廊／PDF 兼容模块，保留旧标记与正文，独立提供轮播、灯箱及本地 PDF 查看器。
- 新增可选媒体网格文件夹卡片与路径导航，完善连续缓动轮播、图标控件、触摸灯箱和中文标签。
- 媒体组织仍由专用插件负责；新模块不迁移文件夹、不删除旧数据，需隔离验证后再切换。

## 0.10.0-rc.1

- 新增独立配置驱动的资源目录、内容导览、文章阅读跳转、归档导览和章节展示模块，保留原生正文、锚点、权限和私人记录。
- 移除失效的 Editor.md footer 补丁；新安装包不携带旧编辑器预览或专用公式资源。
- Add optional content presentation modules with server-side access checks and progressive controls.

## 0.9.0-rc.4

- 个人资料采用安全确认、恢复邮箱和带品牌图标的登录方式卡片，已连接的当前应用不再重复显示连接按钮。
- 历史连接单独标明不可用于当前登录，保留安全解绑；前台与后台支持移动布局、键盘操作和独立表单。
- 身份确认、连接、解绑与邮箱验证新增短期、会话绑定、一次性服务端反馈，不以 URL 参数决定成功状态。
- Improve social account management cards and accessible, session-bound operation feedback.

## 0.9.0-rc.3

- 新增默认关闭、仅服务器可配置的 Google Unix SOCKS 出口，仅覆盖固定令牌与证书接口。
- 保持 TLS 和身份校验，代理不可用时明确失败，不影响其他提供者或邮件请求。
- Add an opt-in Google-only Unix SOCKS transport with strict TLS verification and scoped request hooks.

## 0.9.0-rc.2

- 新增管理员社交登录设置，逐家管理应用参数、密钥和启用状态，支持恢复服务器默认配置。
- 密钥不回填，留空保留，变更应用须提供新密钥；配置写入串行化并在锁内刷新缓存。
- 后台覆盖仅影响社交登录，服务器配置错误不能被覆盖掩盖；提供者默认不自动启用。
- Add administrator-only social provider configuration with explicit inheritance, secret handling and fail-closed validation.

## 0.9.0-rc.1

- 新增默认关闭的 GitHub、Google 与微信开放平台网站应用登录；沿用注册资料、绑定、恢复与删除清理规则，不按邮箱或 UnionID 合并。
- GitHub 使用 PKCE 与数字身份；Google 使用 PKCE、nonce 与 RS256 签名／声明校验；微信严格匹配应用内 OpenID。历史 GitHub 不自动认领。
- 新入口采用品牌图标＋文字；Google 使用本地原版官方资源。
- Add opt-in GitHub, Google OIDC and WeChat website login with scoped identities and offline protocol tests. No provider is enabled automatically.

## 0.8.0-rc.3

- 第三方登录入口采用本地品牌 SVG 图标与文字按钮，统一触控尺寸、键盘焦点与移动布局。

- 首次第三方注册增加资料表单：用户名／邮箱必填、昵称选填，错误可修改重试，不按邮箱合并或自动验证。
- WordPress 用户删除后清理其社交归属和解绑记录，保留身份锁与孤立记录兜底。
- Add explicit social registration details and post-deletion identity cleanup.

## 0.8.0-rc.2

- 删除 WordPress 用户后，登录／绑定在身份锁内核实原用户确已不存在，再释放残留归属记录，允许明确重新注册或绑定；存活账号冲突、解绑标记和旧身份规则保持。
- Recover orphaned social identity owners after user deletion; database lookup failures remain fail-closed.

## 0.8.0-rc.1

- 绑定、解绑及恢复邮箱验证要求当前会话十分钟内重新确认身份，支持密码与已绑定供应商；密码确认及发信限流。
- 最后解绑只接受其他有效且归属一致的供应商，或已通过一次性链接验证的当前邮箱；历史邮箱不自动信任，邮箱变更撤销验证。
- 未知社交身份先选择创建新账号或登录已有账号后重新绑定；选择限时、浏览器绑定、一次消费，不按邮箱合并账号。
- Add session-bound reauthentication, explicit first-login registration and verified recovery email protection. This is a release candidate; no automatic migration or provider activation.

## 0.7.1

- Brevo 地址省略空显示名，兼容只有邮箱的收件人、抄送、密送与 Reply-To。
- 登录页为已登录用户提供资料管理与退出切换提示；OAuth 和分享链接显式编码参数，保留嵌套回调与返回地址。
- Omit empty optional display names to avoid provider rejection of ordinary email addresses.

## 0.7.0

- 新增独立、默认关闭的 Brevo 邮件、原生四表单 Turnstile、QQ／微博／百度 OAuth 与本地文章分享模块；只从显式服务器配置读取凭据，不建立缓存或定时任务。
- 社交身份以提供者、应用和 subject 唯一映射；迁移保留原用户和旧字段，绑定需 nonce，回调 state 绑定浏览器且一次使用，最后可用凭据不能解绑。
- 沿用 WordPress 邮件构建、过滤与成功／失败事件；不补发历史通知，Brevo API 不支持的 CID 附件明确失败。
- 输出处理独立且默认关闭，保持资源版本参数，不迁入自动数据库清理或供应商许可证服务。
- Add opt-in mail, native-form verification and scoped social identity services without replacing user IDs, enabling caches or scheduling cleanup.

## 0.6.2

- 新增默认关闭的轻量浏览计数、统计人群与纯文本模板；无页面缓存时统计公开文章和页面，缓存启用时停计并提示。
- 计数锁保护并发和旧值惰性承接；显式旧负责人配置避免并存重复，历史读取不触发迁移或经验奖励。
- Add opt-in view counters with plain-text display, explicit legacy fallback and per-post database locking. Page caches are unsupported; counters never award experience.

## 0.6.1

- 展开段落评论时保持正文宽度、标题换行和阅读位置不变；面板利用右侧空余空间，按视口宽度调整，最大 350px。
- 补充桌面、手机及短窗口布局回归，覆盖目录恢复、草稿和选区保留。

## 0.6.0

- 后台总开关与逐篇段落评论、系列和章节顺序设置，保留旧记录与显式空值。
- 独立 MySQL/WordPress 权限、nonce、历史点赞及并发 CI；外部标识扫描规则。

- 展示由主题负责；章节、段落评论、点赞和显式旧站配置由配套插件负责。
- 外部兼容配置保留既有记录，不在加载时执行数据迁移。

## 0.5.1

- 独立评论通知、旧 Markdown 编辑器与公式兼容。
- 根据活跃 Prism 资源配置组件路径，避免与已有 mbb-math 重复加载 MathJax。
