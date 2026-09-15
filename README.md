# PageNest Compatibility

Companion plugin for PageNest, providing comment notifications, Markdown editor compatibility, math rendering, and legacy site integration.

配合[PageNest · 栖页](https://github.com/wzf2000/PageNest)使用，也将评论通知与主题外观分离维护。

## 功能与边界

- 审核通过的新留言通知文章作者，回复通知原评论者；防止重复发送、本人通知及与WordPress原生通知重复。
- 私密文章校验收件账号阅读权限；不在通知邮件中复制评论正文。密码保护文章不发送本插件通知。
- 保留旧Markdown公式保护及每篇文章的编辑器选择，适配wp-editormd的Prism高亮加载。
- 启用支持相应特性的主题时，通过cdnjs加载MathJax2.7.7；若已有mbb-math入队则避免重复。MathJax不是内置文件，涉及第三方网络请求；版本沿用现有文章兼容需求，本项目不声称升级了公式引擎。
- 保留2048登录提示短代码、旧资源范围函数及Materialis Companion迁移期间的防冲突处理。无需安装旧Materialis主题或Companion。

**不包含**SMTP提供商配置、积分/奖励规则、OAuth账号服务、私人笔记插件或数据迁移。邮件通过WordPress `wp_mail()` 和站点现有邮件服务发送；不自动补发历史通知，没有定时重试任务。

## 安装与升级

WordPress6.0+、PHP8.0+。运行 `npm run package`，通过后台插件上传 `dist/wzf-theme-bridge-0.4.0.zip`。该打包步骤只需Python3。已有站点保留目录 `wzf-theme-bridge` 和入口 `wzf-theme-bridge.php`，不要并排安装第二份同功能插件。

启用后功能按已有文章元数据和主题特性生效，无需复制数据库。它包含旧站适配钩子，并非任意Markdown/公式插件组合的通用适配器。新网站应在自己的插件组合下验收。

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

测试使用内存替身，不实际发信。不要为测试在生产批量提交评论。PHP采用4空格、PER-CS式括号，其他格式规则见仓库配置。

## License

Copyright (c) 2026 wzf2000. Licensed under **GPL-2.0-or-later**; see [LICENSE](LICENSE). External WordPress plugins and remotely loaded libraries retain their own licenses and are not bundled here.
