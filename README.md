<p align="center"><img src="docs/assets/hero.svg" alt="PageNest Companion — 连接阅读、互动与内容" width="100%"></p>

<p align="center"><strong>为 WordPress 长文增加段落互动、章节导航与可选站点功能。</strong><br>Reading interactions, content guides and media presentation for WordPress.</p>

<p align="center">
  <img alt="WordPress 6.0+" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b">
  <img alt="PHP 8.0+" src="https://img.shields.io/badge/PHP-8.0%2B-777bb4">
  <a href="LICENSE"><img alt="GPL-2.0-or-later" src="https://img.shields.io/badge/license-GPL--2.0--or--later-52796f"></a>
</p>

<p align="center"><a href="#开始使用">开始使用</a> · <a href="docs/SHOWCASE.md">实际效果</a> · <a href="docs/index.md">文档</a> · <a href="docs/USAGE.md">安装指南</a> · <a href="CHANGELOG.md">更新记录</a></p>

> 本文档对应 **1.7.0**。安装包与发行状态以仓库 **Releases** 为准，请使用对应版本附带的文档。

本版本补齐模块诊断筛选／导出、公开面包屑与分享提示、原生画廊图注／首图、
[媒体质量维护](docs/USAGE.md#媒体质量)和 [WordPress 个人数据导出](docs/USAGE.md#wordpress-个人数据导出)。
真实系列目录关系尚需明确的内容模型，当前不从通用目录或字符串系列名推断。

## 让阅读之外的操作有处可去

读者可以围绕具体段落交流，沿章节顺序继续阅读，为文章点赞。站长可以按需开启搜索、分享信息、媒体展示和社交登录，而不用把这些功能写进主题。

Companion 与 PageNest 配合提供完整的阅读入口；其他主题也能使用独立评论抽屉。内容仍由 WordPress 管理，可选模块需要明确配置，不会自动批量迁移文章。

## 实际效果

![PageNest Companion 原生画廊在合成阅读页中的效果](docs/assets/gallery-reading.png)

_下图来自 1.6.0 发布前的合成 WordPress／PageNest 截图，画廊使用合成图片；不是当前候选新增界面的验收截图。更多[阅读、编辑与管理效果](docs/SHOWCASE.md)。_

## 你能用它做什么

| 目标                     | 提供的功能                                                       | 使用入口                                       |
| ------------------------ | ---------------------------------------------------------------- | ---------------------------------------------- |
| **围绕正文交流**         | 逐篇启用段落评论、本人编辑与冲突保护、文章点赞、普通评论邮件通知 | [阅读与互动](docs/USAGE.md#章节段落评论和点赞) |
| **连续阅读一个系列**     | 同名系列与章节顺序、按阅读权限展示上下篇                         | [阅读设置](docs/USAGE.md#后台阅读设置)         |
| **帮助内容被找到与分享** | 可选搜索建议、分类／系列筛选、SEO 与单篇分享预览                 | [搜索与分享](docs/USAGE.md#内容发现与基础-seo) |
| **展示图片与文件**       | 可选画廊、键盘灯箱、PDF 查看器与媒体文件夹                       | [媒体展示](docs/USAGE.md#可选媒体展示)         |
| **接入站点账号与通知**   | 可选社交登录、Brevo 邮件与 Turnstile 原生表单验证                | [账号与服务](docs/USAGE.md#社交账号与恢复邮箱) |
| **扩展站点能力**         | 可选内容模块、通用工具宿主与管理员页面测速                       | [扩展文档](docs/index.md#开发与可选模块)       |

**1.6.0 已提供：** 需读者同意的本机 “我的阅读”、本人的评论与私人笔记入口、独立内容导览编辑、原生画廊／PDF 创作与只读模块诊断。详见[使用指南](docs/USAGE.md#我的阅读与独立内容导览)。本机阅读记录不保存到账户、不跨设备同步；私人记录继续在服务端校验本人权限。

## 开始使用

需要 **WordPress 6.0+、PHP 8.0+**；安装目录保持 `pagenest-compatibility`。

1. 从 仓库页面的 **Releases** 下载插件安装 ZIP，在后台 **插件 → 安装插件 → 上传插件** 安装并启用。
2. 打开 **设置 → PageNest Companion**，选择所需的段落评论、章节导航和点赞功能。
3. 编辑一篇文章，在 “PageNest 阅读功能” 中明确启用段落评论，填写系列名称及章节顺序，然后保存。
4. 使用不同账号检查互动，再退出登录确认访客只能阅读公开内容。

关闭功能会保留既有记录；新文章默认不启用段落评论。已有相同功能的插件或桥接代码应先核对职责，升级与旧存储接入见[使用指南](docs/USAGE.md#旧版升级与兼容范围)。

## 两个组件，各自维护清楚的职责

![主题、Companion 与独立扩展的职责示意图](docs/assets/overview.svg)

PageNest 负责首页、目录、视觉和布局；Companion 负责互动、功能配置与服务端权限。经验奖励政策、浏览器工具、游戏与私人服务由独立可选实现提供，不随本插件安装包分发。

可选服务由管理员逐项启用，旧负责人仍运行时不自动接管。社交账号按应用身份关联，不按邮箱合并；配置和真实供应商验证见手册。MathJax 2.7.7 按主题特性从 cdnjs 加载，会产生第三方请求，库文件不随包发行。

## 文档与参与

[文档导航](docs/index.md)按日常使用、站点配置和开发扩展组织说明：

- [使用指南](docs/USAGE.md)：安装、阅读互动、权限、媒体、搜索、账号与升级。
- [实际效果](docs/SHOWCASE.md)：真实合成站的阅读、编辑与管理截图。
- [扩展接口](docs/EXTENDING.md)：显式服务器配置、内容模块及兼容契约。
- [工具宿主](docs/tools.md)与[页面测速](docs/performance.md)：独立可选模块的配置、实现边界与验收要求。
- [贡献指南](CONTRIBUTING.md)与[发行指南](docs/RELEASING.md)：本地检查、真实 WordPress 测试和安装包。

采用 **[GPL-2.0-or-later](LICENSE)**，版权归 PageNest Contributors（2026）所有。外部插件与远端加载库保留各自许可证。
