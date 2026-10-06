# PageNest Compatibility

Companion plugin for PageNest, providing comment notifications, Markdown editor compatibility, math rendering, and legacy site integration.

安装包与发行记录见 [GitHub Releases](https://github.com/wzf2000/PageNest-Compatibility/releases)。

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

要求 WordPress 6.0+、PHP 8.0+。运行 `npm run package`，然后上传 `dist/pagenest-compatibility-0.5.1.zip`。打包需要 Git 工作副本与 Python 3。

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
npx playwright install --only-shell chromium
npm run test:frontend
npm run package
npm run package:check
```

`npm test` 运行评论通知和 Prism 资源检测的内存替身，不实际发信。PHP 使用 4 空格和 PER-CS 式括号规则，JS/CSS/JSON/YAML 使用 2 空格，目标 100 列；仓库中的 Markdown 文档由格式命令补齐中英文/数字间空格，同时保留代码和链接。

## CI 与发行

两个仓库分别运行 push、pull request 和可复用的 CI。固定 Node.js 24.15.0、Python 3.12、Playwright 1.55.1；PHP 8.0 和 8.2 分别检查最低支持版本与当前运行版本。格式检查遵循 MarkBridge 的 Prettier、PHP 插件、Markdown 中英文间距和 Black 规则，Python 文件逐个检查以避免多进程启动。生成的哈希资源只校验，不直接格式化；CI 在构建前校验，避免构建掩盖已提交资源漂移。

PHP 测试使用内存中的 WordPress 替身，浏览器测试覆盖桌面与手机宽度，并禁止外部网络请求。它们验证公共功能和实际前端资源，不能代替完整 WordPress 安装上的插件组合验收。测试依赖、fixture、格式工具与 node_modules 不进入安装 ZIP。

手动运行 GitHub Actions 的 `Manual GitHub Release`，仅支持 main；输入无 `v` 的版本号，须与 PHP/主题头、package.json 和 package-lock.json 一致，并对应 CHANGELOG.md 的首个版本节。发行说明取自该节。默认 `publish=false`，生成可下载的安装 ZIP、SHA-256、外部 manifest 和发行说明。选择 `publish=true` 才创建公开发行：先固定源提交并完成同一套 CI，在独立写权限任务中复核下载附件摘要与源身份，创建带完整附件的草稿后公开。已存在的 tag 或 release 会被拒绝，避免覆盖既有 v0.5.1 或任何历史附件。工作流不会部署 WordPress。

打包使用固定 ZIP 时间戳与安装文件白名单，内外 manifest 保存源提交及逐文件摘要。`npm run package:check` 验证包结构、安装文件、checksum 与 proof；打包需要 Git 工作副本和 Python 3；安装文件必须与 HEAD 提交一致，先提交准备发行的修改再打包。

## License

Copyright (c) 2026 PageNest Contributors. Licensed under **GPL-2.0-or-later**; see [LICENSE](LICENSE). External WordPress plugins and remotely loaded libraries retain their own licenses and are not bundled here.
