# AI Translator for TranslatePress

TranslatePress 的 AI 翻译引擎，支持 **Google Gemini 原生接口**和**任意 OpenAI 兼容接口**（OpenAI、DeepSeek、智谱、OpenRouter、中转站）。

核心原则：**宁可不翻，不能翻错。** 每条译文按编号对应，整批先判断是否错位，再逐条校验（数字、链接、HTML、品牌词、型号、尺码、是否真的翻译了、是否被截短……），不过关的不保存、之后自动重试。

**补翻队列**：在后台一页一页访问各页面的译文版本，让没人访问过的页面也提前翻好；有速度上限、每日字符上限、给访客预留 TranslatePress 额度，接口出问题时自动等待。

**旧坏译文体检与修复**：用同一套校验器扫描 TranslatePress 里已有的机器译文（错位、英文、截短、结构化数据损坏、占位符丢失），可导入外部候选清单、可选 AI 复核；人工勾选后先备份再改回「未翻译」交给补翻队列重翻，按批次逐字节回滚；人工译文从不动。人工核过的译文可以导入「已验收清单」，永不被修复；TranslatePress 用的不是本引擎时，补翻队列和修复都会停下。

- 使用说明（英文）：见 [readme.txt](readme.txt)
- 架构与维护：见 [docs/架构与维护.md](docs/架构与维护.md)
- 测试报告：见 [第一期](docs/测试报告-第一期.md)、[第二期](docs/测试报告-第二期.md)、[第三期](docs/测试报告-第三期.md)

## 下载

在 [Releases](https://github.com/alankongx/ai-translator-for-translatepress/releases) 下载附件 `ai-translator-for-translatepress.zip`。之后的新版本会在 WordPress 后台自动提示更新。

**不要用仓库页面的「Code → Download ZIP」安装**：那个包的文件夹名会变成 `ai-translator-for-translatepress-main`，还带着测试和开发文件，不是安装包。还没有 Release 时，用 `dev/build-zip.sh` 自己打包（生成在 `dist/`）。

## 使用顺序

适用于新站，也适用于**之前用过 HHG for TranslatePress 或其他 AI 引擎**、留下了没翻译的页面或错译的网站。

### 一、安装和切换引擎

1. **先做整站备份**（主机面板或备份插件），至少要包含数据库。
2. 「插件 → 安装插件 → 上传插件」，上传 zip 并启用。需要 TranslatePress（免费版即可）已启用。
3. 「设置 → AI 翻译 → 设置」：选接口类型，填 API 密钥和模型，保存后点「运行连接测试」，确认能连通。品牌名、型号写进「保护词」。
4. 「TranslatePress → 设置 → 自动翻译」：打开自动翻译，引擎选「AI 翻译（Gemini / OpenAI 兼容）」，保存。
5. 原来用 HHG 的：到插件列表里**停用** HHG，**先不要删除**，万一要退回可以马上重新启用。以后稳定运行一段时间再删。
   - 引擎没换过来之前，本插件的补翻队列不会跑、修复也会拒绝，后台会显示红色提示——不会出现「装了新插件、实际还在用 HHG 翻译」。
   - HHG 和本插件不要长期同时启用。

### 二、补齐没翻译的页面

6. 「设置 → AI 翻译 → 补翻队列」：点「生成页面列表并开始」。队列在后台一页一页访问各语言页面，TranslatePress 照常把没翻的文字交给引擎翻译。
   - 先用默认速度（每分钟 4 页）和每日字符上限，观察一两天再调。
   - 站点关了 WP-Cron 时，可以把这个页面开着点「从本页立即推进」，或用 `wp aitp queue run`。
   - 用了 CDN 或服务器整页缓存的，要让带 `aitp_nc` 参数的请求不走缓存。

### 三、查出并修复旧的错译

7. 「设置 → AI 翻译 → 体检与修复」：点「开始体检」。体检只读，不改任何数据，也不花 AI 费用。
8. 有人工核过、确认没问题的译文清单，先用「加入已验收清单」导入（按编号 + 原文 + 译文；编号对不上时按原文找）。这些行永远不会被修复。
9. 有外部整理的错译候选清单，可以用「导入候选清单」导入，只有和网站当前内容完全一致的才会加入。
10. **先小批试**：筛选一种语言、「错误」这一档，勾几十条，点「改回未翻译（先备份）」。被改回的页面会自动进补翻队列重翻。
11. 等重翻完，打开前台看这些页面，确认没问题，再按批扩大（「筛选结果全部改回」每次最多 500 条）。
    - 「疑似错位」「待看意思」两档不一定是错的，要人看过再修；可以对它们用「AI 复查」（设置里打开，计入每日字符上限）。
    - 修复后会自动清 LiteSpeed、W3 Total Cache、WP Super Cache、WP Rocket 里的相关页面；**CDN 要自己清**。
12. 哪一批有问题，到「修复记录」里点「回滚」，这一批原样恢复；其间被人手工改过的行保留不动。每批也可以「下载备份」。
13. 手工小批试过、确认没问题后，错误多的网站可以打开**「分批自动修复」**（同一页上，默认关闭）：选语言、每批条数（默认 200，最多 500）和每天字符上限（默认是 TranslatePress 每日上限的一半），点「开始自动修复」。
    - 它一次只做一种语言的一批：先备份、改回未翻译，只访问显示这些文字的页面直到重新翻好，再用体检规则检查新译文；这一批合格才做下一批。不需要开补翻队列。
    - 自己会停下的情况：一批重翻后仍有超过 5% 是错的或没翻、有英文被存成译文、AI 接口出错、页面打不开、TranslatePress 不再用本引擎、某一批被回滚，或者有人点了「停止」。到了每日上限就等到第二天继续。
    - 只修「错误」这一档的页面文字；「疑似错位」「待看意思」、已验收清单、人工翻译、结构化数据和界面文字一律不碰。一批做完仍然错的行标成「自动修复后仍然错误」，留给人看，不会反复重试。
    - 每一批都在「修复记录」里标着「自动修复」，可以单独回滚。

### 不会做的事

- 不动 TranslatePress 里标为「人工翻译」的译文；不清空翻译表，不删任何行。
- 不会自己动手修：体检查出来的问题，要么人工勾选修复，要么由人打开「分批自动修复」并点开始；它出问题会自己停，不会一口气改完。
- 不能保证语义完全准确：规则和 AI 复查只拦已知类型的错误，重要页面仍建议人工抽查。

## English

A translation engine for TranslatePress using Google Gemini or any OpenAI-compatible API. Translations are mapped by ID, whole answers are checked for misalignment, and every string is validated before TranslatePress saves it; strings that fail stay untranslated and are retried later. Old machine translations can be checked, repaired through the backfill queue with a backup, and rolled back exactly. See [readme.txt](readme.txt).

## 开发

```bash
php tests/run.php                 # 单元测试
cd dev/e2e && docker compose up -d && node scenarios.mjs   # 本机真实链路（详见 docs）
dev/build-zip.sh                  # 打包到 dist/
```

License: GPL-2.0-or-later
