# 使用 PageNest Compatibility

## 安装与启用

要求 WordPress 6.0+、PHP 8.0+。

1. 在 [GitHub Releases](RELEASING.md) 下载发行附件中的 `pagenest-compatibility-版本号.zip`，选择安装包，而非自动生成的 Source code ZIP。
2. 在后台打开 “插件 → 安装插件 → 上传插件”，上传 ZIP，安装并启用。
3. 按本站现有主题、编辑器和邮件服务检查所需功能。功能按现有文章元数据和主题特性生效，无需复制数据库。

推荐配合 PageNest · 栖页 使用。评论通知不依赖 PageNest；MathJax、Prism 前端适配和 Materialis Companion 防冲突处理则仅在主题支持 `pagenest-independent-layout` 特性时启用。插件没有 SMTP 配置页面。

## 评论通知与邮件服务

审核通过的新留言可通知文章作者，回复可通知原评论者。未审核留言不会触发通知，插件避免本人通知、重复发送及与 WordPress 原生通知重复。已成功发送的通知按评论和收件地址记录；旧版记录继续参与去重。

通知通过 WordPress `wp_mail()` 和站点已有邮件服务发送。本插件不配置 SMTP 提供商，也没有历史通知补发或定时重试任务。启用前先确认站点邮件服务可用，再用测试文章与不同收件账号验证留言和回复。内存测试不会实际发信，不能作为邮件投递证明。

私密文章校验收件账号的阅读权限；无权限账号不会收到本插件通知。通知不复制评论正文，只引导收件人回到文章查看。密码保护文章不发送本插件通知。WordPress 原生通知仍遵循 WordPress 自身规则。

## 编辑器、公式与代码高亮

插件保留旧 Markdown 公式保护规则及每篇文章的编辑器选择。在文章编辑页的 “使用的编辑器” 框中勾选 “使用块编辑器”，保存后重新打开文章。该选择基于现有 `use_block_editor` 元数据；未勾选时沿用其他编辑器提供方的选择，不强制安装或提供 Markdown 编辑器。

主题声明 `pagenest-independent-layout` 特性时，插件在文章或页面的单篇视图中从 cdnjs 加载 **MathJax 2.7.7**；如果 `mbb-math` 已入队，则不重复加载。MathJax 不随插件打包，会产生第三方网络请求；站点网络或内容安全策略需要允许该资源。它支持 `$…$`、`\(…\)`、`$$…$$` 与 `\[…\]` 分隔符，跳过代码块等区域。

同一主题条件下，Prism 适配根据当前入队的 autoloader 或 core 依赖 URL 推导 `components/` 目录；无法识别时保留提供方配置。仅注册、未入队的资源不触发配置。非标准目录可通过 [扩展接口](EXTENDING.md) 适配。

旧站适配钩子不构成对任意 Markdown/公式插件组合的兼容承诺。新网站和升级后的站点都应按自己的插件组合验证公式、代码高亮与编辑器保存行为。

## 旧版升级与兼容范围

升级前备份文件和数据库。从 0.4 升级时先停用旧版，再安装并启用目录 `pagenest-compatibility` 中的 `pagenest-compatibility.php`，不要同时启用两份插件。0.5 之后保持该目录和入口名称。

兼容配置的 `mail.sent_meta_keys` 和 `mail.lock_option_prefixes` 只读既有通知标记和锁，合并当前标记以避免重复发送。不清空旧记录。

插件保留 2048 登录提示短代码、旧资源范围函数及 Materialis Companion 迁移期间的防冲突处理，不包含积分规则、OAuth 或数据库重算。它保护既有私人笔记并提供公开段落评论。短代码只输出登录提示，不提供成绩记录功能。

> [扩展接口](EXTENDING.md) · [贡献指南](../CONTRIBUTING.md) · [发行指南](RELEASING.md) · [返回项目首页](../README.md)

## 章节、段落评论和点赞

文章设置 `_pagenest_comments_enabled=1` 后，保存动作创建段落注册表；前台仅读取，不自动为未保存段落生成身份。公开发布须明确确认；旧私人接口要求登录、文章阅读权限与作者身份，未知可见性保持私人。编辑和删除须提供当前 opaque `version`，冲突不会覆盖。公开投影不含私人正文、数量、UUID、源指纹和历史引用；匿名只读。没有 PageNest 主题时使用独立抽屉。

章节使用 `series_meta`，按显式 `series_order`、数字 `order_meta`、文档标识排序。相对 Markdown 链接由 `link_map` 明确映射至现有文章，只有可读取、未锁定文章才转换；只改渲染 HTML。点赞要求登录、公开可读文章且不是作者本人，按文章串行锁与 InnoDB 事务写记录和计数。历史 provider 只读事件账本；历史读取或事务能力失败时拒绝写入。经验扩展监听 `pagenest_like_recorded($user_id, $post_id, $count)`，点赞不依赖经验插件。

## 显式兼容配置

在服务器 `wp-config.php` 定义 `PAGENEST_COMPATIBILITY_PROFILE_FILE` 指向 Web 根之外的 JSON 文件，限制文件读取权限。插件不搜索上传目录或 URL，不在加载时复制设置。无法读取、未知字段、格式或标识错误会禁用功能并显示管理员提示。

完整 schema 见 [扩展接口](EXTENDING.md)。默认中性键用于新安装；已有数据须先填写旧存储键、路由、主题别名和历史 provider，不清空或重编号记录。
