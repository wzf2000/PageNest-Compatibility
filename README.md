# PageNest Companion

为 WordPress 提供段落评论、章节导航、文章点赞与评论邮件通知。配合 PageNest · 栖页，让阅读外观与互动功能各有清楚的维护入口；其他主题也能使用独立评论抽屉。

Companion plugin for PageNest: paragraph comments, chapter navigation, likes, notifications and Markdown compatibility.

需要 **WordPress 6.0+、PHP 8.0+**。当前版本 **0.6.0**，安装目录保持 `pagenest-compatibility`。

[安装与使用](docs/USAGE.md) · [更新记录](CHANGELOG.md) · [发行说明](docs/RELEASING.md)

![PageNest Companion 功能与权限示意](docs/assets/overview.svg)

## 开始使用

1. 下载发行附件 `pagenest-compatibility-0.6.0.zip`，在 WordPress 后台 “插件 → 安装插件 → 上传插件” 安装并启用。
2. 打开 “设置 → PageNest Companion”，选择段落评论、章节导航和点赞。
3. 编辑一篇文章，在 “PageNest 阅读功能” 中明确启用段落评论，填写系列名称及章节顺序，然后保存。
4. 用两个不同账号检查评论和点赞；退出登录确认访客只能阅读公开内容。

关闭功能会保留既有记录。新文章默认不启用段落评论，插件不会批量扫描、注册或公开旧文章。

## 阅读与互动

- **段落评论**：选择正文段落后发布公开评论，需要登录并明确确认。读者可编辑或删除自己的内容；遇到并发修改会提示重新读取。兼容接入的私人笔记继续由作者本人读取，未知可见性保持私人。
- **章节导航**：同名系列按章节顺序关联上一篇与下一篇；只有有权阅读、未被密码锁定的文章才显示。旧 Markdown 章节链接可由管理员显式配置转换，正文原稿保持不变。
- **文章点赞**：登录后可给公开可读文章点赞，作者不能给自己点赞。重复请求和历史点赞不会重复计数；经验插件可以订阅事件，停用经验插件不影响点赞。
- **评论邮件**：审核通过的普通 WordPress 留言通知作者，回复通知原评论者，避免本人通知、重复投递和原生通知重复。使用站点现有邮件服务，不提供 SMTP 设置或历史通知补发。
- **编辑兼容**：保留逐篇编辑器选择及 Markdown 公式保护；配合支持的主题适配 Prism 和 MathJax。

## 旧站升级

先备份文件与数据库，再停用原来拥有同一功能的插件或桥接代码，避免两个组件同时写入。服务器管理员通过 Web 根之外的显式配置接入旧存储、路由和别名；后台操作只保存新的中性设置，旧记录不需要重写。配置错误时功能停止并提示管理员。

插件保护既有私人记录；它不提供积分政策、社交登录或数据库重算。**Reader Experience** 是可选的独立扩展。

MathJax 2.7.7 按主题特性从 cdnjs 加载，会产生第三方请求，库文件不随包发行。升级后应按实际编辑器和邮件服务验证保存、渲染与投递。

## 文档与开发

[使用指南](docs/USAGE.md) 介绍配置、权限、编辑器和升级；[扩展接口](docs/EXTENDING.md) 记录服务器配置 schema；[贡献指南](CONTRIBUTING.md) 说明格式、真实 WordPress/数据库/并发及浏览器测试；[发行指南](docs/RELEASING.md) 说明校验与安装包。

采用 **GPL-2.0-or-later**，详见 [LICENSE](LICENSE)。版权归 PageNest Contributors（2026）所有；外部插件与远端加载库保留各自许可证。
