# AI Translator for TranslatePress

TranslatePress 的 AI 翻译引擎，支持 **Google Gemini 原生接口**和**任意 OpenAI 兼容接口**（OpenAI、DeepSeek、智谱、OpenRouter、中转站）。

核心原则：**宁可不翻，不能翻错。** 每条译文按编号对应，整批先判断是否错位，再逐条校验（数字、链接、HTML、品牌词、型号、尺码、是否真的翻译了、是否被截短……），不过关的不保存、之后自动重试。

**补翻队列**：在后台一页一页访问各页面的译文版本，让没人访问过的页面也提前翻好；有速度上限、每日字符上限、给访客预留 TranslatePress 额度，接口出问题时自动等待。

**旧坏译文体检与修复**：用同一套校验器扫描 TranslatePress 里已有的机器译文（错位、英文、截短、结构化数据损坏、占位符丢失），可导入外部候选清单、可选 AI 复核；人工勾选后先备份再改回「未翻译」交给补翻队列重翻，按批次逐字节回滚；人工译文从不动。

- 下载安装：在 [Releases](https://github.com/alankongx/ai-translator-for-translatepress/releases) 下载 `ai-translator-for-translatepress.zip`，在 WordPress 后台「插件 → 安装插件 → 上传插件」安装。之后的新版本会在后台自动提示更新。
- 使用说明：见 [readme.txt](readme.txt)
- 架构与维护：见 [docs/架构与维护.md](docs/架构与维护.md)
- 测试报告：见 [第一期](docs/测试报告-第一期.md)、[第二期](docs/测试报告-第二期.md)、[第三期](docs/测试报告-第三期.md)

## English

A translation engine for TranslatePress using Google Gemini or any OpenAI-compatible API. Translations are mapped by ID, whole answers are checked for misalignment, and every string is validated before TranslatePress saves it; strings that fail stay untranslated and are retried later. Old machine translations can be checked, repaired through the backfill queue with a backup, and rolled back exactly. See [readme.txt](readme.txt).

## 开发

```bash
php tests/run.php                 # 单元测试
cd dev/e2e && docker compose up -d && node scenarios.mjs   # 本机真实链路（详见 docs）
dev/build-zip.sh                  # 打包到 dist/
```

License: GPL-2.0-or-later
