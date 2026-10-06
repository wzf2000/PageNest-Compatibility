# CI 与发行

供维护者准备、核验和发布安装包。普通使用者直接从 [GitHub Releases](RELEASING.md) 下载 ZIP，安装步骤见 [使用指南](USAGE.md)。

两个仓库分别运行 push、pull request 和可复用的 CI。固定 Node.js 24.15.0、Python 3.12、Playwright 1.55.1；PHP 8.0 和 8.2 分别检查最低支持版本与当前运行版本。格式检查遵循 MarkBridge 的 Prettier、PHP 插件、Markdown 中英文间距和 Black 规则，Python 文件逐个检查以避免多进程启动。生成的哈希资源只校验，不直接格式化；CI 在构建前校验，避免构建掩盖已提交资源漂移。

PHP 测试使用内存中的 WordPress 替身，浏览器测试覆盖桌面与手机宽度，并禁止外部网络请求。它们验证公共功能和实际前端资源，不能代替完整 WordPress 安装上的插件组合验收。测试依赖、fixture、格式工具与 node_modules 不进入安装 ZIP。

手动运行 GitHub Actions 的 `Manual GitHub Release`，仅支持 main；输入无 `v` 的版本号，须与 PHP/主题头、package.json 和 package-lock.json 一致，并对应 CHANGELOG.md 的首个版本节。发行说明取自该节。默认 `publish=false`，生成可下载的安装 ZIP、SHA-256、外部 manifest 和发行说明。选择 `publish=true` 才创建公开发行：先固定源提交并完成同一套 CI，在独立写权限任务中复核下载附件摘要与源身份，创建带完整附件的草稿后公开。已存在的 tag 或 release 会被拒绝，避免覆盖既有 v0.5.1 或任何历史附件。工作流不会部署 WordPress。

打包使用固定 ZIP 时间戳与安装文件白名单，内外 manifest 保存源提交及逐文件摘要。`npm run package:check` 验证包结构、安装文件、checksum 与 proof；打包需要 Git 工作副本和 Python 3；安装文件必须与 HEAD 提交一致，先提交准备发行的修改再打包。

> [贡献与本地检查](../CONTRIBUTING.md) · [返回项目首页](../README.md)
