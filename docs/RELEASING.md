# CI 与发行

供维护者准备、核验和发布安装包。普通使用者直接从 仓库页面的 **Releases** 下载 ZIP，安装步骤见 [使用指南](USAGE.md)。

两个仓库分别运行 push、pull request 和可复用的 CI。浏览器 verify job 固定使用 Ubuntu 24.04、Node.js 24.15.0、Python 3.12 和 Playwright 1.55.1；PHP 8.0 和 8.2 分别检查最低支持版本与兼容版本。格式检查遵循 MarkBridge 的 Prettier、PHP 插件、Markdown 中英文间距和 Black 规则，Python 文件逐个检查以避免多进程启动。生成的哈希资源只校验，不直接格式化；CI 在构建前校验，避免构建掩盖已提交资源漂移。

`npm run test:reader-ux-webkit` 复用阅读中心、账号自动位置／阅读偏好、后台反馈／导览目标、自有 PDF 阅读器和站内通知测试，只增加 WebKit 引擎。
通知回归覆盖手机布局、未读状态、登录失效、请求取消与多面板同步；真实 WordPress 通知权限与最近 100 条窗口另由两个 PHP 集成任务验证。
PDF 用例实际加载随包的 PDF.js 与合成双页文件，操作翻页、缩放、窄框菜单并校验下载字节；不以按钮可见代替下载验证。
CI 在 PHP 8.2 的 Ubuntu 24.04 任务执行该门禁，失败时上传 `.runtime/reader-ux-webkit-results/` 的 trace。
浏览器任务同时检出固定提交的公开 PageNest 主题并显式传入路径，不读取站点私有目录；配套主题独立发行。

PHP 测试使用内存中的 WordPress 替身，浏览器测试覆盖桌面与手机宽度，并禁止外部网络请求。它们验证公共功能和实际前端资源，不能代替完整 WordPress 安装上的插件组合验收。测试依赖、fixture、格式工具与 node_modules 不进入安装 ZIP。

手动运行 GitHub Actions 的 `Manual GitHub Release`，仅支持 main；输入无 `v` 的版本号，须与 PHP/主题头、package.json 和 package-lock.json 一致，并对应 CHANGELOG.md 的首个版本节。发行说明取自该节，统一使用中文叙述（产品名、代码和文件名保持原样），避免重复的中英文段落；生成器按本版更新、下载与安装、升级说明、验证与源码组织内容，并链接固定版本的安装 ZIP 和文档。默认 `publish=false`，生成可下载的安装 ZIP、SHA-256、外部 manifest 和发行说明。选择 `publish=true` 才创建公开发行：先固定源提交并完成同一套 CI，在独立写权限任务中复核下载附件摘要与源身份，创建带完整附件的草稿后公开。已存在的 tag 或 release 会被拒绝，避免覆盖既有 v0.5.1 或任何历史附件。工作流不会部署 WordPress。

准备新版本时一并核对 README、文档索引和使用指南中的版本、菜单路径、功能默认值与配置来源；将未发行更新节整理为本次版本说明。历史截图、协议号、依赖版本和引入功能的版本保留原值，不批量替换。文档中的默认值以当前代码为准，不以某个站点的启用状态代替。

打包使用固定 ZIP 时间戳与安装文件白名单，内外 manifest 保存源提交及逐文件摘要。`npm run package:check` 验证包结构、安装文件、checksum 与 proof；打包需要 Git 工作副本和 Python 3；安装文件必须与 HEAD 提交一致，先提交准备发行的修改再打包。

> [贡献与本地检查](../CONTRIBUTING.md) · [返回项目首页](../README.md)

## 问题反馈

在本项目仓库页面打开 **Issues**，描述版本、复现步骤和实际结果；请不要附带凭据、私人正文或完整登录回调。

## 历史验证记录：1.7.0 准备阶段

以下保留 1.7.0 准备阶段的验证边界，“本轮” 指当时的检查，不表示当前版本仍待执行；当前远程结果以对应提交的 Actions 为准。公开发行与站点部署是独立流程，CI 构建本身不等于部署。该轮新增小范围 `npm run test:followups-browser`，
使用实际 PHP 界面渲染与合成 WordPress 替身，覆盖诊断 GET／键盘、媒体质量表单与分享继承提示。
只在 CI 的 PHP 8.2 任务增加 Chromium／Firefox／WebKit 项目，不重复全部旧浏览器用例。

| 层次                   | 范围                                                            | 本轮状态                                                |
| ---------------------- | --------------------------------------------------------------- | ------------------------------------------------------- |
| 本轮真实合成 WordPress | WordPress 7.1.3、PHP 8.5.11、MariaDB 11.4.13                    | 合成权限、保存、隐私导出和界面验证；不代表生产发布      |
| 包接口最低支持         | WordPress 6.0+、PHP 8.0+                                        | 包头与兼容接口声明，不代表逐版实测                      |
| 既有 CI 矩阵           | PHP 8.0／8.2、WordPress 6.8.3／MySQL 8.0 合成集成               | 保留配置；本轮尚未在远程 CI 执行                        |
| 小范围本地 Chromium    | Playwright 1.55.1 Chromium 构建 1193                            | 四项合成界面用例通过                                    |
| 小范围本地 Firefox     | Playwright 1.55.1 Firefox 构建 1490、官方 Ubuntu 24.04 fallback | 四项合成界面用例通过，使用任务隔离库                    |
| WebKit CI              | Playwright 1.55.1 WebKit 构建 2203                              | 独立 CI 用例随 verify 运行；远程执行结果以 Actions 为准 |

本机为 Ubuntu 26，Firefox 使用官方 Ubuntu 24.04 fallback 和任务隔离动态库；
手机验证均为视口模拟。真实 WordPress 集成、合成替身与生产站点是不同证据，不能互相替代。
