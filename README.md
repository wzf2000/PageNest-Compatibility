# PageNest Compatibility

Companion plugin for PageNest, providing comment notifications, Markdown editor compatibility, math rendering, and legacy site integration.

配合 [PageNest · 栖页](https://github.com/wzf2000/PageNest)使用，也将评论通知与主题外观分离维护。

## 功能与边界

- 审核通过的新留言通知文章作者，回复通知原评论者；防止重复发送、本人通知及与 WordPress 原生通知重复。
- 私密文章校验收件账号阅读权限；不在通知邮件中复制评论正文。密码保护文章不发送本插件通知。
- 保留旧 Markdown 公式保护及每篇文章的编辑器选择，适配 wp-editormd 的 Prism 高亮加载。
- 启用支持相应特性的主题时，通过 cdnjs 加载 MathJax2.7.7；若已有 mbb-math 入队则避免重复。MathJax 不是内置文件，涉及第三方网络请求；版本沿用现有文章兼容需求，本项目不声称升级了公式引擎。
- 保留 2048 登录提示短代码、旧资源范围函数及 Materialis Companion 迁移期间的防冲突处理。无需安装旧 Materialis 主题或 Companion。

**不包含** SMTP 提供商配置、积分/奖励规则、OAuth 账号服务、私人笔记插件或数据迁移。邮件通过 WordPress `wp_mail()` 和站点现有邮件服务发送；不自动补发历史通知，没有定时重试任务。

## 安装与升级

WordPress 6.0+、PHP 8.0+。运行 `npm run package`，通过后台插件上传 `dist/pagenest-compatibility-0.5.0.zip`。该打包步骤只需 Python 3。从 0.4 升级时，先停用旧版插件，再安装并启用新目录 `pagenest-compatibility`，入口为 `pagenest-compatibility.php`；不要同时启用两份插件。旧评论通知记录通过集中兼容映射继续读取，不会因改名重复发送。0.5 之后保持新目录和入口不变。

启用后功能按已有文章元数据和主题特性生效，无需复制数据库。它包含旧站适配钩子，并非任意 Markdown/公式插件组合的通用适配器。新网站应在自己的插件组合下验收。

## 开发与检查

```sh
npm ci --ignore-scripts
python3 -m venv .venv
. .venv/bin/activate
python3 -m pip install -r requirements-dev.txt
npm run format
npm run format:check
npm test
npm run package
```

测试使用内存替身，不实际发信。不要为测试在生产批量提交评论。PHP 采用 4 空格、PER-CS 式括号，其他格式规则见仓库配置。

## License

Copyright (c) 2026 PageNest Contributors. Licensed under **GPL-2.0-or-later**; see [LICENSE](LICENSE). External WordPress plugins and remotely loaded libraries retain their own licenses and are not bundled here.

## 0.5.0 命名迁移

运行代码统一采用 `pagenest` 前缀。旧版标识仅在 `legacy-migration.php` 的兼容映射中保留；GitHub 链接中的仓库所有者不变。主题默认不包含站长 ID，网站名称读取 WordPress 的站点配置。

Markdown 正文会自动补齐中英文/数字间空格，保留代码块、行内代码和链接地址；运行 `npm run format` 和 `npm run format:check` 即可维护。
