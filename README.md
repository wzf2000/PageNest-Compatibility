# PageNest Compatibility

Companion plugin for PageNest, providing comment notifications, Markdown editor compatibility, math rendering, and legacy site integration.

配合 [PageNest · 栖页](https://github.com/wzf2000/PageNest) 使用，并把评论通知和旧站兼容逻辑从主题外观中分离。

## 功能与边界

- 审核通过的新留言通知文章作者，回复通知原评论者；避免重复发送、本人通知和与 WordPress 原生通知重复。
- 私密文章校验收件账号的阅读权限；不在通知邮件中复制评论正文。密码保护文章不发送本插件通知。
- 保留旧 Markdown 公式保护及每篇文章的编辑器选择，按当前入队 Prism 资源的 URL 推导高亮组件目录，兼容旧 wp-editormd 和其他提供方。
- 启用支持相应特性的主题时，通过 cdnjs 加载 MathJax 2.7.7；若已有 `mbb-math` 入队则避免重复。MathJax 不随插件打包，会产生第三方网络请求。
- 保留 2048 登录提示短代码、旧资源范围函数及 Materialis Companion 迁移期间的防冲突处理。

插件不包含 SMTP 提供商配置、积分规则、OAuth、私人笔记或数据迁移。邮件通过 WordPress `wp_mail()` 和站点已有邮件服务发送；没有历史通知补发或定时重试任务。

Prism 默认从入队 autoloader 或其 core 依赖推导 `components/`，无法识别时保留提供方的配置。自定义资源布局可通过 `pagenest_prism_languages_path` filter 返回 `HTTP(S)`、协议相对或站内绝对组件 URL；回调接收路径、脚本 handle 和 `WP_Scripts`，返回空字符串可禁用覆盖。仅注册但未入队的资源不会触发配置。

## 安装与升级

要求 WordPress 6.0+、PHP 8.0+。运行 `npm run package`，然后上传 `dist/pagenest-compatibility-0.5.1.zip`。打包只需要 Python 3。

从 0.4 升级时，先停用旧版，再安装并启用目录 `pagenest-compatibility` 中的 `pagenest-compatibility.php`；不要同时启用两份插件。`legacy-migration.php` 集中处理旧通知记录的兼容读取，防止改名后重复发送。0.5 之后保持该目录和入口名称。

功能按现有文章元数据和主题特性生效，无需复制数据库。旧站适配钩子不构成对任意 Markdown/公式插件组合的兼容承诺，新网站应按自己的插件组合验收。

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

`npm test` 运行评论通知和 Prism 资源检测的内存替身，不实际发信。PHP 使用 4 空格和 PER-CS 式括号规则；仓库中的 Markdown 文档由格式命令补齐中英文/数字间空格，同时保留代码和链接。

## License

Copyright (c) 2026 PageNest Contributors. Licensed under **GPL-2.0-or-later**; see [LICENSE](LICENSE). External WordPress plugins and remotely loaded libraries retain their own licenses and are not bundled here.
