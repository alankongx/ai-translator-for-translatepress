# 代码地图（CODEMAP）

改代码前先读这份，改完同步更新。版本：0.6.6（0c8d897）基础上整理，0.7.0 各批改动在第 7 节追加。
行数是 0.6.6 时的，仅作规模参考。

## 1. 目录与分层

- `ai-translator-for-translatepress.php`：主文件。版本常量、按顺序加载类、启用/停用钩子，然后 `AITP_Plugin::instance()->boot()`。
- `uninstall.php`：卸载。`AITP_Schema::uninstall()` 加清 6 个 cron。
- `includes/core/`：纯逻辑，不依赖 WP。16 个文件，是翻译核心、校验、协议、熔断，以及队列/体检/自动修复的纯规则。
- `includes/providers/`：接口适配。3 个文件：基类、Gemini 原生、OpenAI 兼容。
- `includes/wp/`：WP 集成。18 个文件，含 TP 引擎、存储、传输、队列、页面映射、清缓存、体检/修复/回滚/自动修复/测试记录、后台页、命令行、更新器、schema。
- `admin/assets/`：`admin.js`（接口类型/服务联动，四个「从本页立即推进」轮询）、`admin.css`。
- `languages/`：`.pot` 和 zh_CN 的 `.po/.mo/.l10n.php`（543 条，全部已译）。
- `tests/`：`run.php`、`bootstrap.php`、`results.json`、`lib/{harness,fakes}.php`、`fixtures/private`、`unit/` 下 test-already-target / providers / breaker / autofix / protocol / segmenter / translator / queue / validator / audit。
- `dev/`：`build-zip.sh`、`mock-llm/server.js`、`e2e/`（docker 本机 WP+TP 3.3.9）、`tools/`（okay-scan、okay-audit-scan、okay-bad-scan、build-okay-fixtures.py、okay-build-phase4.py）。
- `docs/`：架构与维护.md、开发计划.md、测试报告-第一/二/三期、CODEMAP.md（本文件）。

## 2. 每个文件一行（`includes/` 下 37 个 PHP 文件全覆盖）

### core（16）

| 文件 | 行数 | 职责 | 关键入口 |
|---|---|---|---|
| core/class-aitp-translator.php | 931 | 翻译总流程：退避/缓存→拆段→分批→请求→解析→逐条校验→整批判断→接受或重试→拼回→写失败/待复核/缓存/日志 | `translate()`、`accept_or_retry()`、`suspect_response()`、`echo_counts()`、`payload_extra()` |
| core/class-aitp-validator.php | 628 | 单条校验（硬/软） | `check()`、`hint()`；私有 `untranslated()`、`already_in_target()`、`sentence_like()`、`brand_case_hits()` |
| core/class-aitp-text.php | 373 | 文本工具 | `placeholder_patterns()`、`is_structured_data()`、`numbers()`、`script_counts()`、`sentence_count()`、`term_regex()`、`latin_words()` |
| core/class-aitp-markup.php | 218 | HTML 标签切分与比对 | `tokenize()`、`signatures()`、`is_balanced()`、`text_only()`、`no_translate_texts()` |
| core/class-aitp-protocol.php | 232 | 提示词与 JSON 协议 | `system_prompt()`、`user_payload()`、`json_schema()`、`gemini_schema()`、`parse()` |
| core/class-aitp-segmenter.php | 131 | 长文拆段/拼回 | `split()`、`join()` |
| core/class-aitp-batcher.php | 63 | 分批与输出预算 | `build()`、`max_output_tokens()` |
| core/class-aitp-circuit-breaker.php | 143 | 全站共用熔断器，状态存选项 `aitp_breaker` | `allow()`、`success()`、`failure()`、`reset()` |
| core/class-aitp-rules.php | 236 | 保护词、保护格式、术语表 | `default_patterns()`、`extract_codes()`、`is_size_string()`、`parse_glossary()`、`glossary_for()`、`version()` |
| core/class-aitp-languages.php | 252 | 语言信息（文字系统、是否无空格连写） | `info()`、`all_scripts()`、`joins_without_spaces()`、`base_codes()` |
| core/class-aitp-claims.php | 198 | 「宣传说法」词表 | `lexicon()`、`concepts_in()` |
| core/class-aitp-pair-checker.php | 205 | 体检的 AI 复核 | `check()`、`system_prompt()` |
| core/class-aitp-contracts.php | 165 | 存储/传输接口和内存实现，供单测与 WP 实现 | `AITP_Transport`、`AITP_Quota`、`AITP_Caps_Store` 等 |
| core/class-aitp-queue-logic.php | 227 | 队列纯规则：访问后状态、签名与校验、路径规范化、预算 | `decide()`、`partial_delay()`、`canonical_path()`、`sign()`、`check()`、`budget_block()` |
| core/class-aitp-audit-logic.php | 549 | 体检纯规则 | `check_row()`、`structured_problem()`、`shared_suspects()`、`similar()`、`neighbor_ranges()`、`shift_like()`、`visit_pages()`、`page_key(s)()`、`native_identical()`、`reset_block()`、`is_accepted()`、`cover_pages()`、`restore_block()`、`verdict()`、`parse_import()` |
| core/class-aitp-autofix-logic.php | 146 | 自动修复纯规则（`MAX_PASSES = 3`） | `clean_settings()`、`default_cap()`、`after_pass()`、`judge()`、`visit_stop()`、`is_english()` |

### providers（3）

| 文件 | 行数 | 职责 | 关键入口 |
|---|---|---|---|
| providers/class-aitp-provider.php | 290 | 提供方基类：错误分类、能力降级识别、指纹、脱敏 | `classify_error()`、`detect_unsupported()`、`fingerprint()`、`redact()`、`error_result()` |
| providers/class-aitp-provider-gemini.php | 251 | Gemini 原生接口 | `build_request()`、`parse_response()`、`downgrade()`、`thinking_config()`、`thinking_reserve()` |
| providers/class-aitp-provider-openai.php | 268 | OpenAI 兼容接口（预设：OpenAI、DeepSeek、智谱、OpenRouter、Groq、自定义） | `presets()`、`default_caps()`、`build_request()`、`parse_response()`、`downgrade()`、`thinking_reserve()` |

### wp（18）

| 文件 | 行数 | 职责 | 关键入口 |
|---|---|---|---|
| wp/class-aitp-plugin.php | 251 | 接线：注册 TP 引擎（TP ≥ 3.3.5）、建翻译器、关掉 TP 引号标点改写、记下已注册短代码、每日清理、保留其它引擎设置、注册 WP-CLI | `tp_supported()`、`disable_tp_rewrite()`、`remember_shortcodes()`、`boot()`、`add_engine()`、`add_engine_class()`、`translator()`、`time_budget()`、`activate()`、`deactivate()`、`keep_other_engine_settings()`、`daily_cleanup()` |
| wp/class-aitp-tp-engine.php | 125 | TP 引擎类（继承 `TRP_Machine_Translator`） | `translate_array()`、`get_chunk_size()`、`get_supported_languages()`、`get_api_key()`、`check_api_key_validity()`、`test_request()`（返回空数组） |
| wp/class-aitp-settings.php | 407 | 设置默认值/读取/清洗、Key 加解密、地址校验、构建 provider 与 rules | `get()`、`sanitize()`、`provider()`、`rules()`、`is_configured()`、`api_key()` |
| wp/class-aitp-wp-stores.php | 251 | WP 存储实现：`AITP_WP_Cache`、`AITP_WP_Failures`、`AITP_WP_Review`、`AITP_WP_Log`、`AITP_WP_Option_State`、`AITP_WP_Caps`（选项 `aitp_caps`，设置变更和测试连接时清空）、`AITP_WP_Usage`（选项 `aitp_usage`，按天的 Token 用量）、`AITP_TP_Quota`（TP 日计数器） | 由 `AITP_Plugin::translator()` 注入 |
| wp/class-aitp-wp-transport.php | 234 | HTTP 发送，按并发数分波 | `send()`、`single()`（`wp_remote_post`）、`multi()`（`Requests::request_multiple`） |
| wp/class-aitp-schema.php | 245 | 11 张表的 dbDelta 与卸载（`VERSION='6'`） | `maybe_upgrade()`（plugins_loaded@5）、`install()`、`uninstall()`、`table()` |
| wp/class-aitp-queue.php | 980 | 补翻队列：页面列表、worker、签名访问发送与接收、映射回报 | `sync()`、`run()`、`tick()`、`process()`、`visit()`、`maybe_warm()`（plugins_loaded@1）、`map_strings()`、`requeue*()`、`requeue_urls()`、`next_item()`、`collect()`、`save_report()` |
| wp/class-aitp-pagemap.php | 535 | 页面映射：只读签名访问，记录「页面↔词典行」 | `plan()`、`start()`、`step()`、`tick()`、`map_one()`、`pages()`、`export()`、`pages_of_rows()` |
| wp/class-aitp-cache-purge.php | 305 | 按网址清页面缓存（LiteSpeed/W3TC/WP Super Cache/WP Rocket），长列表走 cron | `urls()`、`tick()`、`purge_now()`、`litespeed_all()` |
| wp/class-aitp-audit.php | 1835 | 体检、发现、候选导入、已验收清单、修复、回滚、写入锁、AI 复核 | `start_scan()`/`step()`/`tick()`、`find()`/`ids_for()`/`counts()`、`import()`、`protect()`/`unprotect()`、`repair()`、`rollback()`、`batches()`、`backup_export()`、`autofix_pick()`、`batch_urls()`、`batch_check()`、`ai_check()`、`write_lock()` |
| wp/class-aitp-autofix.php | 574 | 分批自动修复状态机 | `settings()`、`save_settings()`、`start()`、`stop()`、`tick()`、`step()`、`blocker()` |
| wp/class-aitp-journal.php | 621 | 测试记录：TP 保存时记「之前」状态，撤销，新增行 | `boot()`、`start()`/`stop()`、`translated()`、`on_save()`、`on_gettext_queue()`、`finish_gettext()`、`rolled_back()`、`undo_blocker()`、`undo()`、`summary()`、`new_rows()` |
| wp/class-aitp-admin.php | 904 | 后台主类：菜单、页签分发、设置页、状态与日志、待复核、连接测试、全后台提示、TP 页状态卡 | `render()`、`render_settings()`、`render_status()`、`render_review()`、`render_test_result()`、`notices()`、`tp_status_card()`、`reason_text()`、`code_label()`、`handle_test()` 等 |
| wp/class-aitp-admin-queue.php | 300 | 「补翻队列」页签 | `render()`、`handle()`、`ajax_step()` |
| wp/class-aitp-admin-audit.php | 887 | 「体检与修复」页签 | `render()`（含 `render_autofix/list/purge/batches/import/settings`）、`handle()`、`download_backup()`、`ajax_step()`、`ajax_autofix_step()`、`once_field()`、`form_used()`、`action_record()` |
| wp/class-aitp-admin-pages.php | 384 | 「页面映射与测试记录」页签 | `render()`、`render_map()`、`render_journal()`、`handle()`、`download()`、`ajax_step()` |
| wp/class-aitp-cli.php | 479 | `wp aitp` 五组命令 | `queue()`、`audit()`、`autofix()`、`map()`、`journal()` |
| wp/class-aitp-updater.php | 142 | GitHub releases 自动更新 | `check()`、`info()`、`release()` |


### 其它入口

- `ai-translator-for-translatepress.php`：主文件，加载与钩子。
- `uninstall.php`：卸载。
- `admin/assets/admin.js`、`admin.css`：见第 1 节。

## 3. 主要调用链

**一次页面翻译**（A、E）：TP `process_strings`（`class-translation-render.php` 2051-2284）或 gettext 队列（`class-gettext-manager.php` 571-664，shutdown 优先级 100）→ `TRP_Machine_Translator::translate()`（去重、`should_translate_string` 跳过、加锁认领、占位）→ `AITP_TP_Engine::translate_array()`（`time_budget(get_machine_translation_lock_timeout())`，tp-engine.php:40）→ `AITP_Plugin::translator()->translate()`：退避/缓存 → `Segmenter::split` → 每轮 `Batcher::build` → `Protocol::user_payload` + `Provider::build_request` → `Transport::send` → `Provider::parse_response`（不支持则 `downgrade` + `Caps_Store::set`）→ `Protocol::parse` → 每条 `Validator::check` → 整批判断 → `accept_or_retry` → 拼回 → `Failure_Store` / `Review_Store` / `Cache_Store` / `Log` → 返回键值译文（没返回的键 TP 记状态 0）→ 触发 `aitp_translated_batch` → TP 后处理（`restore_translation_quotes`、`restore_punctuation_patterns`、`trp_sanitize_string`）→ `trp_allow_string_saving`（journal `on_save`）→ 写库 → `release_locks`（续期 50 秒）。

**队列一次推进**（B）：cron `aitp_queue_tick` 或 AJAX `aitp_queue_step` 或 CLI → `AITP_Queue::run()` → `next_item()` → `process()` → `visit()`（`wp_remote_get` 签名访问，URL 带 `aitp_nc`）→ 页面端 `maybe_warm()`（plugins_loaded@1）验签 → TP 渲染 → `aitp_translated_batch` → `collect()` → shutdown `save_report()`（transient）→ 发送端读回报 → `Queue_Logic::decide()` → 更新行 → `Cache_Purge::urls()`。

**页面映射一次访问**（B）：后台或 CLI → `Pagemap::start(plan)` → cron `aitp_map_tick` 或 AJAX `aitp_map_step` → `map_one()` → `Queue::visit(map=true)` → `Queue::map_strings()`（挂 `trp_translateable_information`，只读）→ 写 `aitp_pagemap` / `aitp_pagestr`；修复时 `pages_of_rows()` → `Queue::requeue_urls()`。

**体检**（C）：`start_scan` → `step`/`tick`（cron `aitp_audit_tick`）→ `scan_shared` → `scan_chunk`（→ `Audit_Logic::check_row`、`originals_elsewhere`、`native_identical`）→ `scan_neighbors` → `record` → `finish`；AI 复核走 `ai_check` → `Pair_Checker::check` → provider/transport/quota/breaker → `Protocol::parse` → `Audit_Logic::verdict`。

**修复一批**（C）：`repair` → `write_lock` → `repair_locked` → `rows`/`accepted`/`row_pages`（→ `meta_posts`/`post_page`/`Pagemap::pages_of_rows`）→ `Audit_Logic::reset_block`/`visit_pages` → `save_batch(running)` → 写 `aitp_repair` 备份 → UPDATE TP（`id=? AND status=1 AND BINARY translated=旧值`）→ 发现标 `fixed` → `Failures::clear` → `cover_pages` → `Queue::requeue_urls`/`requeue_lang` → `save_batch(done)` → `Cache_Purge::urls`。

**回滚**（C）：`rollback` → `rollback_locked` → `newer_batches`/`restore_block` → UPDATE TP（含 `original_id`）→ 标记备份行 → 发现重新打开 → `Journal::rolled_back` → `save_batch` → `Cache_Purge::urls`。

**自动修复一轮**（C）：cron `aitp_autofix_tick` 或 AJAX `aitp_autofix_step` → `Autofix::step` → `blocker`/熔断器 → `begin_batch`（→ `Audit::autofix_pick` → `visit_check`（`Queue::visit` 只读）→ `Audit::repair` → `Audit::batch_urls` → `tag_batch`）→ `visit_next`（`Queue::visit` → `Autofix_Logic::visit_stop` → `Cache_Purge::urls`）→ `end_pass`（`batch_check(false)` → `after_pass` → `batch_check(true)` → `judge` → `tag_batch`）。

**测试记录**（C）：引擎 → `Journal::translated($lang)`；TP 保存 → `on_save`（`trp_allow_string_saving`）→ `record` → `insert`；gettext 走 `on_gettext_queue` / `finish_gettext`；撤销 `undo` → `undo_blocker` → `Audit::write_lock` → `mark_legacy_rollbacks` → `undo_one`（UPDATE TP）。

**清缓存**（B）：`Queue::process` 或修复/回滚 → `Cache_Purge::urls()` → ≤5 个立即 `purge_now`，否则存列表（选项 `aitp_purge_list`）后 cron `aitp_purge_tick` 分批；LiteSpeed 有头部大小保护（每请求 ≤30 标签，超过 200 个整站清一次）；其它缓存只触发 `aitp_purge_urls` 动作。后台页打开时 `Cache_Purge::tick(3)`。

**设置保存**（B、D）：设置表单 `options.php`（组 `aitp`）→ `AITP_Settings::sanitize()`（数值上下限、https 地址校验、Key 加密、保护格式/术语表校验）→ 保存时清熔断器（D16 注：不清 `aitp_caps`）；TP 自己保存自动翻译设置时 → `trp_machine_translation_sanitize_settings` → `AITP_Plugin::keep_other_engine_settings()`（PHP_INT_MAX）保住其它引擎的键；连接测试走 `admin_post_aitp_test` → `handle_test()`，结果存 transient `aitp_test_<uid>`。

## 4. 与 TranslatePress 的接触点表（按风险排序；旧版顺序 2.6.9 / 2.9.24 / 3.0 / 3.2.4 / 3.3.4 / 3.3.5+）

| # | TP 钩子/类/表 | 我们的文件 | 用法是否一致 | 旧版 | 失败方式 |
|---|---|---|---|---|---|
| 1 | `get_machine_translation_lock_timeout()` | tp-engine.php:40 | 一致 | ✗✗✗✗✗✓ | 致命错误（B01=E01） |
| 2 | 锁/认领/`release_locks` 续期 | queue-logic `decide()` 依赖其语义 | 不一致：续期覆盖我们没返回的串 | 3.3.5 起 | 页面误判 done（B03=E02） |
| 3 | `should_translate_string` 跳过后原样存 | audit-logic `check_row` | 没考虑 | 各版规则略不同 | 修复无限循环（E03） |
| 4 | `restore_translation_quotes` / `restore_punctuation_patterns` 及关闭过滤器 | plugin（未处理） | 没关 | 3.2.4 起 | 静默改写译文（E04） |
| 5 | `trp_machine_translator_is_available` | queue.php 841-912 | 一致 | ✗✗✓✓✓✓ | 2.x 映射访问真机翻 |
| 6 | `trp_allow_string_saving`（3 参） | queue.php、journal `on_save` | 一致 | ✗✗✓✓✓✓ | 2.x 静默失效（C08） |
| 7 | gettext 机翻队列、`trp_gettext_allow_machine_translation`（5 参） | journal `on_gettext_queue`/`finish_gettext` | 一致；内容保护不足 | 全部 ✓ | 日期/ltr 被翻（A10=E06） |
| 8 | gettext 表 `plural_form` / `original_plural` | audit.php 115-125 | 漏列 | ✓ | 复数误报（C17=E05） |
| 9 | `get_chunk_size()`、`trp_machine_translation_time_budget` | plugin、queue | 一致 | ✗✗✗✓✓✓ | 静默不生效 |
| 10 | 计数器 `increase_counter_with_value` / `get_current_counter` / `count_machine_translated_characters`（选项 `trp_machine_translation_counter`） | wp-stores `AITP_TP_Quota`、admin 状态页 | 一致；状态页绕过日期重置 | 2.6.9 ✗，其余 ✓ | 退回内存配额（E10） |
| 11 | `test_request()`、`get_api_key()`、`check_api_key_validity()` | tp-engine | `test_request` 返回空数组 | ✓ | 测试按钮永远成功（D04=E08） |
| 12 | `trp_machine_translation_engines`、`trp_automatic_translation_engines_classes`、`get_active_engine` | plugin（plugins_loaded 优先级 30） | 一致 | ✓ | 类缺失时退回 `TRP_MTAPI_Machine_Translator`（B15） |
| 13 | `trp_machine_translation_sanitize_settings` | plugin `keep_other_engine_settings`（PHP_INT_MAX） | 一致 | ✓ | 无 |
| 14 | `trp_machine_translation_extra_settings_middle` | admin `tp_status_card()` | 一致 | ✓ | 无 |
| 15 | `trp_translateable_information` + transient `processed_original_string_meta_post_id_for_` | queue.php 937-967 | 一致 | ✓ | 无 |
| 16 | 表名 `get_table_name`、`get_gettext_table_name`、`get_table_name_for_original_meta`、`get_table_name_for_gettext_original_strings` | pagemap、audit.php 63-88 | 一致 | ✓ | 无 |
| 17 | `get_language_names`、`get_url_for_language`、语言表（`translation-languages` / `publish-languages`） | queue `tp_languages()`、pagemap | 未发布语言匿名不可见 | ✓ | B02 |
| 18 | `trp_sanitize_string`（kses） | TP 保存时执行，在我们之后 | — | ✓ | 可能剥标签（E04） |
| 19 | 「优化数据库」「删除条目」清理（`class-regular-delete.php`、`class-upgrade.php`、`class-failed-translations-cleanup.php`） | 无（批次依赖行 id） | — | ✓ | 回滚找不到行（E11） |
| 20 | RTL：`<html lang>`、`custom-language.php`、WP 语言包（`class-settings.php:369` 下载） | 无 | — | ✓ | 阿语 LTR（E07） |
| 21 | 网址（slug）翻译表（付费插件） | 无 | 未验证 | — | 不在修复范围（E14） |
| 22 | 邮件、AJAX/JSON/REST 响应的 `translate_page`；`wp_mail` | 无 | — | ✓ | 走机翻与时间预算（E13） |
| 23 | TP 数据表 `trp_dictionary_*`（状态 0/1/2/3、`original_id`）、`trp_gettext_*`、`trp_original_strings`、`trp_original_meta`、`trp_gettext_original_strings`；`post_parent_id` meta | audit（读写，仅 UPDATE 4 处：修复、回滚、回滚 original_id、撤销）、pagemap、journal | 代码不直接 INSERT/DELETE TP 表 | ✓ | — |
| 24 | 选项 `trp_machine_translation_settings`（含 `machine_translation_limit` 默认 1,000,000、block-crawlers） | settings、admin、D02 建议的快照对象 | — | ✓ | — |

## 5. 数据表与选项键清单

**表**（`$wpdb->prefix . 'aitp_' . 名` ；`AITP_Schema::VERSION = '6'`，对应选项 `aitp_db_version`）

| 表 | 用途与关键列 |
|---|---|
| `cache` | 译文缓存：`ckey`(PK)、`target_locale`、`translation`、`created_at` |
| `failures` | 逐条失败：`fkey`(PK)、`source_locale`、`target_locale`、`reason`、`excerpt`、`attempts`、`next_retry_at` |
| `review` | 待复核：`original_hash`+`target_locale`+`translation_hash` 唯一、`flags`、`saved`、`status` |
| `log` | 请求日志：`level`、`event`、`target_locale`、`data` |
| `queue` | 补翻队列：`ukey`、`source_url`、`url`、`lang`、`object_type/id`、`priority`、`status`、`next_at`、`visits`、`attempts`、`passes`、`note`、`translated`、`chars`、`seen` |
| `audit` | 体检发现：`kind`+`lang`+`row_id` 唯一、`severity`、`codes`、`details`、`source`、`scan`、`ai`、`state`、`batch` |
| `repair` | 修复备份：`batch`、`kind`、`lang`、`row_id`、`finding_id`、`original`、`translated`、`status`、`original_id`、`exact`、`restored` |
| `protect` | 已验收清单：`kind`+`lang`+`row_id`+`translated_hash` 唯一、`label` |
| `pagemap` | 页面映射：`ukey`、`url`、`source_url`、`lang`、`object_type/id`、`http`、`strings`、`found`、`unknown`、`note`、`mapped_at` |
| `pagestr` | 页面↔词典行：`page_id`+`row_id`（PK）、`lang` |
| `journal` | 测试记录：`session`、`kind`、`lang`、`row_id`、`original`、`prev_translated`、`prev_status` 等 |

**选项**（`AITP_Schema::uninstall()` 清单）：`aitp_settings`、`aitp_db_version`、`aitp_breaker`、`aitp_caps`、`aitp_notice`、`aitp_queue_settings`、`aitp_queue_state`、`aitp_queue_lock`、`aitp_audit_state`、`aitp_audit_lock`、`aitp_audit_settings`、`aitp_repair_batches`、`aitp_autofix_settings`、`aitp_autofix_state`、`aitp_autofix_lock`、`aitp_map_state`、`aitp_map_lock`、`aitp_journal_state`、`aitp_purge_list`、`aitp_audit_actions`、`aitp_write_lock`。

**transient**：`aitp_flash_<uid>`、`aitp_test_<uid>`、`aitp_used_<nonce>`（一次性表单）、`aitp_warm_*`（队列签名请求/回报，B 路另称 `save_report` 的 transient）、签名 nonce 防重放 transient；site transient `aitp_github_release`。卸载时按 `_transient_aitp_%` 前缀整体清。

**cron**：`aitp_daily`（本插件每日清理）、`aitp_queue_tick`、`aitp_audit_tick`、`aitp_autofix_tick`、`aitp_map_tick`、`aitp_purge_tick`（后四者用 `AITP_Queue::SCHEDULE`）。

**插件自有钩子**：动作 `aitp_translated_batch`（tp-engine.php:53）、`aitp_purge_urls`（无识别缓存时）；过滤器 `aitp_queue_request`（queue.php:753）、`aitp_github_updates`。

**常量**：`AITP_FILE`、`AITP_ALLOW_INSECURE_ENDPOINT`（允许非 https 接口地址）。B05 建议的 `AITP_LOOPBACK_AUTH` 是建议，代码里没有。

**后台动作**（D）：admin-post `aitp_test`、`aitp_reset_breaker`、`aitp_clear_failures`、`aitp_review`、`aitp_export_review`、`aitp_queue`、`aitp_audit`、`aitp_audit_backup`、`aitp_pages`、`aitp_pages_download`；AJAX `aitp_queue_step`、`aitp_audit_step`、`aitp_autofix_step`、`aitp_map_step`。每个都先查 `manage_options` 再查各自的 nonce。

## 6. 与 HHG 的边界

- 本插件不读写 HHG 的任何选项和表。HHG 与本插件可以同时装，但 TP 一次只用一个引擎。
- OKAY 站点的阿语 `dir="rtl"`、日语页眉 CSS 在原维护方的 HHG 修复版里，不属于本插件。

## 7. 0.7.0 变更记录

（每批完成后在这里追加：改了哪些文件、新增了哪些入口和选项键。）

### 第 1 批 接口与校验（2026-11-14）

- **providers**：`AITP_Provider::classify_error()` 重写（`BILLING_HARD` / `MODERATION` / `MODEL_MISSING`），新增 `output_limit()`、结果里的 `max_out`；`detect_unsupported()` 先认输出上限；`seconds()` 认 HTTP 日期。OpenAI：`may_think()`、智谱 `thinking`、`effort_minimal` → `low`、可见输出不含推理 token、deepseek-reasoner 预留 8192。Gemini：`*-latest` 别名按思考模型预留。
- **core/translator**：轮次循环重写——`split()` 整批对半拆（`MAX_SPLIT_PASSES`）、`give_up()` 单条放弃、`repeated_ids()` 撞车单问、`learn_thinking()`、`fixed_translation()`（日期格式、ltr/rtl）；新依赖 `usage`（`AITP_Usage_Store`）。
- **core/breaker**：按语言暂停 `locales`、`held_locales()`、`allow($locale)` / `success($locale)`，3 种语言同时暂停才全站暂停。
- **core/protocol**：去 `<think>`、`embedded_object()`、数字 id 转字符串。
- **core/validator / text / languages / claims / segmenter**：繁简与日韩文字检查（`wrong_script` / `no_kana`）、非英语源语言、`fold()` 比较、CJK 单位与印度分组、新句号、约 45 种语言和 `alias()`、英语目标的说法表、`$shortcodes` 只保护已注册短代码。
- **wp**：`AITP_Plugin::tp_supported()`（`MIN_TP`）、`disable_tp_rewrite()`、`remember_shortcodes()`；`AITP_WP_Usage`（选项 `aitp_usage`）；`AITP_WP_Caps::clear()`（保存设置、测试连接时）；引擎里 `method_exists` 兜底和语言别名；状态页加「暂停中的语言」和 7 天 Token 用量；12 个新原因标签。
- **新文件**：`tests/unit/test-v070-interfaces.php`、`dev/tools/i18n.php`。
- **新选项键**：`aitp_usage`（卸载时删除）。
