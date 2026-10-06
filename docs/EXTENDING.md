# 扩展与兼容接口

后台安装、编辑器选择和邮件注意事项见 [使用指南](USAGE.md)。

## 主题特性与加载条件

评论通知独立于主题。MathJax、Prism 前端配置与 Materialis Companion 自定义器防冲突处理仅在 `current_theme_supports('pagenest-independent-layout')` 为真时运行。MathJax 还要求文章或页面的单篇视图，并且 `mbb-math` 未入队。

## Prism 组件路径

`pagenest_prism_languages_path($path, $handle, $scripts)` filter 接收推导出的组件目录、autoloader 脚本 handle 和 `WP_Scripts` 对象。

- 默认从当前入队 autoloader 的标准路径推导 `components/`；非标准 autoloader 路径可从 core 依赖推导。
- 支持旧 wp-editormd 和其他提供方；仅注册但未入队的资源不会触发配置，已选择/输出的资源及活动依赖会参与检测。
- 自定义布局可返回 `HTTP(S)`、协议相对或站内绝对组件 URL。URL 不能含凭据、路径遍历、空白或反斜线；查询与片段会移除，最终目录统一补上斜线。
- 返回空字符串可禁用本插件的路径覆盖，保留 autoloader 自身配置；不会禁用高亮或停止组件加载。无法识别默认路径时也保持提供方配置。

## 编辑器兼容

`jetpack_markdown_preserve_pattern` 追加行内与块级公式保护模式并去重。`use_block_editor_for_post` 在文章元数据 `use_block_editor` 为字符串 `true` 时启用块编辑器，其余情况保留上游结果。编辑页选择框保存时校验 nonce、编辑权限并排除修订和自动保存。

## 旧站接入点

- `[2048_get_login_button]`：登录用户输出为空，访客输出返回当前页面的 WordPress 登录链接和提示；不提供成绩、积分或奖励逻辑。
- `pagenest_active()`：非后台、非 feed 且主题支持 `pagenest-independent-layout` 时为真。
- `pagenest_template_scope()`：首页、文章列表、文章/页面单篇视图、归档或搜索时为真，供已有资源范围适配器调用。
- Materialis Companion 兼容：在支持该主题特性时，移除来自其 `src/Companion.php` 的自定义器闭包回调，避免独立主题缺少 Kirki 类时的冲突；不负责停用插件。

> [使用指南](USAGE.md) · [贡献指南](../CONTRIBUTING.md) · [返回项目首页](../README.md)
