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

## 兼容 profile schema 1

JSON 顶层必须含 `schema_version: 1`，其他节可按需指定，未知键拒绝。默认值见 `config.php` 的 `pagenest_companion_defaults()`。

- `theme`：`legacy_stylesheet`、`setting_prefix`、`menu_locations`、`anchor_prefixes`、`class_aliases`。后三项为旧名到新名映射；设置迁移仅在主题切换时补缺，不覆盖已配置的空值。
- `mail`：`sent_meta_keys`、`lock_option_prefixes` 字符串列表；只读旧标记和锁。
- `paragraphs`：`post_type`、`data_meta`、`uuid_meta`、`document_meta`、`registry_meta`、`library_meta`、`enabled_meta`、`rest_namespace`、`rest_aliases`、`shortcodes`。旧记录无需重写；所有兼容 namespace 使用相同权限和缓存策略。
- `chapters`：`series_meta`、`order_meta`、`document_meta`、`link_map`（Markdown 文件名到现有整数文章 ID）、`series_order`（稳定文档 ID 列表）。没有系列元数据时，已标记文档组成一个系列。
- `likes`：`counter_meta`、`record_meta`、`legacy_provider`。provider 含 `table_suffix`（加 WordPress 表前缀）、`user_column`、`post_column`、`event_column`、`event_value`、`record_column`、`record_prefix`。非空 post/event/record 条件同时成立；record 值为 `record_prefix + user_id + ':' + post_id`。表名和列名仅允许 ASCII 字母、数字、下划线，值通过 SQL 参数绑定。表不可读时拒绝新点赞。

扩展可直接调用 `pagenest_companion_like($user_id, $post_id)` 复用完整检查；必须为当前登录者。`pagenest_like_recorded` 在持久化成功并释放锁后发布，提供用户 ID、文章 ID、最新计数；订阅方自行保证事件幂等。

`experience` 是可选 Reader Experience 扩展配置节，包含 `table_suffix`、`live_option`、`rank_option`、`week_option`、`event_lock`、`weekly_lock`、`rest_namespace`、`rest_aliases`、`shortcodes`、非负整数 `panel_page_id` 和 `weekly_hook`。Companion 仅校验配置，不安装账本、重算余额或定义经验规则。
