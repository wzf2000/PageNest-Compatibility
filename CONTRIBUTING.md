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

`npm test` 运行评论通知和 Prism 资源检测的内存替身，不实际发信。PHP 使用 4 空格和 PER-CS 式括号规则，JS/CSS/JSON/YAML 使用 2 空格，目标 100 列；仓库中的 Markdown 文档由格式命令补齐中英文/数字间空格，同时保留代码和链接。

## 测试边界

PHP 测试使用内存中的 WordPress 替身；浏览器测试覆盖桌面与手机宽度，并禁止外部网络请求。插件的通知测试不实际发信。它们验证公共功能和实际前端资源，不能代替完整 WordPress 安装上的插件组合验收。测试依赖、fixture、格式工具与 node_modules 不进入安装 ZIP。

## 提交与发行

请只提交与本次修改相关的文件，保持现有格式。准备安装包前需提交发行内容，打包以 HEAD 为依据；CI、版本校验和公开发行流程见 [发行指南](docs/RELEASING.md)。

> [使用指南](docs/USAGE.md) · [扩展接口](docs/EXTENDING.md) · [返回项目首页](README.md)
