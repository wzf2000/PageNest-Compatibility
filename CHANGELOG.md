# Changelog

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
