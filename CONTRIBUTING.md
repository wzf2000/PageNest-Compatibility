# 参与开发

请先通过 Issue 描述问题、复现步骤或改进建议；提交修改时说明对使用者的影响及验证结果。本地开发与打包需要 Git 工作副本；本地开发工具不属于 WordPress 安装要求。

## 本地环境与检查

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

`npm test` 运行配置、权限、旧记录、章节、点赞、通知、Prism 和发行保护的合约检查，不实际发信。PHP 使用 4 空格和 PER-CS 式括号规则，JS/CSS/JSON/YAML 使用 2 空格，目标 100 列；仓库中的 Markdown 文档由格式命令补齐中英文/数字间空格，同时保留代码和链接。

## 测试边界

快速 PHP 测试使用内存中的 WordPress 替身；浏览器测试覆盖桌面与手机宽度，并禁止外部网络请求。插件的通知测试不实际发信。它们验证公共功能和实际前端资源，不能代替完整 WordPress 安装上的插件组合验收。测试依赖、fixture、格式工具与 node_modules 不进入安装 ZIP。

## 提交与发行

请只提交与本次修改相关的文件，保持现有格式。准备安装包前需提交发行内容，打包以 HEAD 为依据；CI、版本校验和公开发行流程见 [发行指南](docs/RELEASING.md)。

> [使用指南](docs/USAGE.md) · [扩展接口](docs/EXTENDING.md) · [返回项目首页](README.md)

## 真实 WordPress 与并发检查

CI 在 PHP 8.0/8.2 上使用独立 MySQL 8.0 服务、新建 WordPress 6.8.3 和 WP-CLI 2.12.0，合成用户、文章、私人/未知记录及历史点赞。它检查 nonce、读写权限、版本冲突、UUID 幂等、后台设置、事务以及独立进程并发。无需外部站点配置、生产数据库或兄弟仓库。

本地仅使用全新的临时目录及专用合成数据库：

```sh
export COMPANION_TEST_DATABASE=pagenest_companion_ci
export COMPANION_TEST_DB_HOST=127.0.0.1:3306
export COMPANION_TEST_DB_USER=root
export COMPANION_TEST_DB_PASSWORD=synthetic-local-password
python3 tools/wp-integration.py --wp-cli /tmp/wp-cli.phar --lab /tmp/pagenest-companion-ci
```

工具拒绝其他数据库名和非 loopback 数据库主机；本地可使用显式 `localhost:/tmp/…/mysql.sock` Unix socket。测试输出不含登录 cookie 或凭据；临时会话撤销后删除。数据库保留用于调查，销毁由独立测试环境负责。

`npm run identity:check` 总会运行合成 marker 自检。仓库维护者在 Actions 变量或 secret 中设置 `IDENTITY_RULES`（JSON 字符串数组），即可对当前源码、生成资源、测试与文档进行大小写不敏感的字节扫描；失败只报告文件名。规则不写入公共源码。
