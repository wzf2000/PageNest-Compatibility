# Changelog

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
