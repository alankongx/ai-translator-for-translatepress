=== AI Translator for TranslatePress ===
Contributors: alankongx
Tags: translatepress, translation, gemini, openai, multilingual
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translation engine for TranslatePress using Google Gemini or any OpenAI-compatible API. Every translation is mapped by ID and validated before it is saved.

== Description ==

AI Translator adds an engine to TranslatePress → Automatic Translation. It sends page text to Google Gemini (native API) or to any OpenAI-compatible API (OpenAI, DeepSeek, Zhipu, OpenRouter, relays) and hands translations back to TranslatePress only after they pass validation.

The guiding rule: **a wrong translation is worse than none.** A string that cannot be translated correctly stays untranslated (TranslatePress keeps showing the original) and is retried on a later page view.

= How translations are kept on the right string =

* Every string gets an ID; the model must answer with the same IDs in JSON (structured output where the API supports it). Answers are matched by ID, never by position.
* An answer is judged as a whole first. If an entry is missing, two different strings received the same text, or many entries fail validation at once, the answer is treated as misaligned and nothing from it is saved; the strings are retried in smaller batches.
* Answers cut off by the output limit, unreadable JSON, duplicate or invented IDs are never used.

= Validation before saving =

* Length relative to the source (catches shortened or swapped text), missing sentences.
* Numbers, links, e-mail addresses, placeholders, HTML tags and shortcodes must survive unchanged.
* Protected terms (brand names), model and material codes (TR90, UV400), technical standards and size labels must stay as written.
* Untranslated text: identical English, English left inside the translation, headlines and questions left in English.
* Wrong script (e.g. Arabic letters in a Spanish translation).
* Optional checks that may change the meaning — added claims (guaranteed, certified, in stock …) and glossary rules — are retried once, then saved with a flag in a review list (or not saved, in strict mode).

= Built for real sites =

* Long texts are split at sentence or block boundaries and rejoined; one failed part means the whole text is retried.
* Language variants are named in full in the prompt (Spanish (Mexico), Portuguese (Brazil) …).
* Uses TranslatePress' own daily character limit: characters are reserved before sending, released when a request was never answered.
* Time budget per page view, parallel requests, per-string back-off after repeated failures, cache.
* A circuit breaker stops requests on invalid keys, billing problems, rate limits, repeated server errors or repeated bad answers, and resumes by itself.
* Human translations (TranslatePress status "manually translated") are never touched: the engine only receives strings TranslatePress has not translated yet.
* JSON-LD structured data is never sent.

= Backfill queue =

Without visitors, pages are never translated. The backfill queue (Settings → AI Translator → Backfill queue) visits the translated version of your pages in the background, one page at a time, so TranslatePress translates them before a visitor arrives.

* The queue only visits pages; TranslatePress translates them through its normal pipeline, so every check above applies and nothing is written to TranslatePress directly.
* The page list is built from published content (home page, pages, posts, products, categories) plus extra addresses; edited content is queued again automatically.
* Limits: pages per minute, a daily character limit of its own, and a reserve of the TranslatePress daily limit kept for visitors. The queue waits while the provider has a problem (invalid key, rate limit, repeated bad answers).
* Page caches are bypassed for these visits, and a visit answered by a cache is detected instead of being counted as done.
* Internal visits are signed (HMAC, valid 5 minutes, single use). A forged or replayed signature is treated as an ordinary page view.
* Runs through WP-Cron, from the open admin page, or with WP-CLI: `wp aitp queue sync|start|pause|status|run|retry|clear`.

= Check and repair old translations =

Translations saved earlier by another engine may be misaligned, left in English or cut short. Settings → AI Translator → Audit & repair finds and repairs them.

* Check: scans every machine translation in the TranslatePress tables (page text and interface strings) with the same validation as new translations, plus a cross-row check for one translation saved on unrelated texts and for runs of misplaced rows. No AI cost; runs in chunks through WP-Cron, from the admin page or with WP-CLI.
* Review: filter by language, problem and severity, compare original and translation side by side, select the rows to repair. A candidate list from another tool can be imported; a row counts only when its ID, original and old translation still match.
* Optional AI check (off by default): asks the model whether selected or suspicious pairs belong together. Counted against the daily character limit.
* Repair: the selected rows are backed up to the plugin's own table (downloadable per batch), then set back to untranslated — only if they are still machine translated with the same text as when checked, and only if they are on a known page (a page TranslatePress links the string to, or a mapped page that shows it). One such page per row is queued and translated again with full validation; rows on no known page are left as they are unless you tick "also reset rows on no known page", which queues every page of that language. Page caches of known cache plugins are purged; a CDN has to be purged by hand.
* Page map: TranslatePress links a string to a page only when it is inside the post content, so headings, menus and theme or page-builder text belong to no page. Mapping opens selected pages once in read-only mode (machine translation off, TranslatePress saves nothing for that request) and records which page-text rows each page shows, matched by the complete original text. Repairs and the auto repair use it to visit only those pages; selected pages can be downloaded as a candidate list for a page-by-page comparison.
* Test journal (for staging tests): while on, every translation this engine hands to TranslatePress is recorded with the exact state of the row before. "Undo translations of the test" puts rows that still hold exactly that translation back; rows TranslatePress added during the test are listed and downloadable, never deleted.
* Rollback per batch restores the backed-up text exactly; rows translated by hand in between are kept.
* Accepted list: translations people have already reviewed (for example rows fixed in an earlier clean-up) can be imported by ID, original and text. The check still lists them, but they are never counted as open and never repaired while their text is unchanged.
* Repair is refused, and the backfill queue waits, while TranslatePress uses another engine or automatic translation is off — otherwise another engine would translate the reset rows.
* Batch auto repair (off by default): works through the "wrong" findings of page text one batch at a time (20–500 rows, one language at a time). Each batch is backed up and reset, only the pages that show those strings are visited until they are translated again, the new translations are checked, and the next batch starts only if the batch came out clean. It stops by itself when more than 5% of a batch is still wrong or untranslated, when English is stored as a translation, on provider errors or failed page loads, when TranslatePress stops using this engine, or when a batch is rolled back. It has its own daily character cap (default: half the TranslatePress limit). "Probably misplaced" and "check meaning" findings, the accepted list, manual translations, structured data and interface text are never touched; rows still wrong afterwards are left for a person. Every batch can be rolled back.
* Human translations (status "manually translated") are never listed, changed or restored over. No table is emptied, no row deleted, at most 500 rows per action.
* WP-CLI: `wp aitp audit scan|status|list|repair|rollback|import|ai-check|dismiss`, `wp aitp autofix status|settings|start|stop|run`, `wp aitp map start|run|stop|status|list|export|clear`, `wp aitp journal start|stop|status|undo|new-rows`.

= Security =

* The API key is stored encrypted (libsodium or AES-256-GCM, key derived from the site's secret keys) and is never shown again, put in a URL, logged or sent to the browser. It can also be set in wp-config.php with `define( 'AITP_API_KEY', '…' );`.
* Requests are made from the server with TLS verification and without following redirects. Custom API addresses must use HTTPS.
* All admin actions require the `manage_options` capability and a nonce. There is no public endpoint.

= Requirements =

* TranslatePress (free or paid) with automatic translation available.
* An API key for Google Gemini or an OpenAI-compatible service.

== Installation ==

1. Upload the zip under Plugins → Add New → Upload Plugin and activate it.
2. Open Settings → AI Translator, choose the API type, paste the API key, enter the model and save.
3. Press "Run connection test".
4. In TranslatePress → Settings → Automatic Translation, turn automatic translation on and choose "AI Translator (Gemini / OpenAI-compatible)" as the engine.
5. Add your brand names under Protected terms.

= Switching from another engine (for example HHG for TranslatePress) =

Sites that used another AI engine often have pages that were never translated and some wrong translations. Recommended order:

1. Make a full backup (at least the database).
2. Install and configure AI Translator (steps above), run the connection test.
3. Choose "AI Translator" as the engine in TranslatePress → Automatic Translation.
4. Deactivate the other engine plugin. Do not delete it yet, so you can switch back quickly; delete it once everything runs well. Until the engine is switched, the backfill queue does not run and repair is refused, and the admin shows a notice.
5. Settings → AI Translator → Backfill queue → "Build page list and start" to translate pages nobody has visited yet.
6. Settings → AI Translator → Audit & repair → "Start check". The check is read-only and costs nothing.
7. If you have translations people already reviewed, add them to the accepted list first; they are never repaired.
8. Map the pages you care about (Page map & test journal), so repairs know which pages show which strings. Then repair a small batch first (one language, "error" findings, a few dozen rows), let the queue translate them again, check the pages, then continue in batches of at most 500. Purge your CDN yourself.
9. If a batch looks wrong, roll it back under "Repairs"; the old text is restored exactly.

== Frequently Asked Questions ==

= Why are some strings still in English? =

They failed validation or were not reached within the page view's time budget. They are retried on later page views. Settings → AI Translator → Status & log shows the reasons.

= Does it replace existing translations? =

Not by itself. The engine only translates strings TranslatePress sends to it, which are strings without a translation. Old machine translations change only when you select them under Audit & repair and press Repair; they are backed up first and can be rolled back. Manual translations are never sent or changed.

= Updates =

Updates come from the GitHub releases of this plugin. To turn update checks off: `add_filter( 'aitp_github_updates', '__return_false' );`

== Changelog ==

= 0.6.3 =
* Rollback counts in the repair list are read from the backup rows, so a rollback of a batch found from its backup rows, a rollback finished in several runs and a repeated rollback each count every row once (a record lost before showed twice its rows). Rows kept as they are stay counted apart. The notice after a rollback adds the total of the batch when earlier runs restored rows too.
* "Download backup" also lists the pages the batch queued and the "Only use these pages" list it was given.

= 0.6.2 =
* Repairs can no longer leave a batch without a record: the batch is recorded before the first row changes, and repairs and rollbacks keep running when the browser goes away. Batches whose record is missing or unfinished are found from their backup rows ("batch record incomplete") and can be downloaded and rolled back; a cut-off rollback finishes when run again.
* Page caches are cleared in the background, 50 addresses at a time, after the batch is recorded; LiteSpeed adds no admin notice per address; errors of cache plugins are logged and do not stop anything.
* Repairs visit pages from the page map first (pages only linked by TranslatePress are used for rows on no mapped page), and can be limited to a list of pages ("Only use these pages", `--pages=`).
* The check no longer calls a row untranslated when its original is already in the target language (it exists in no other language), for example language switcher labels.

= 0.6.1 =
* Fix: internal visits of pages with non-ASCII addresses (Japanese, Arabic, …) failed their signature check, because the HTTP library rewrites percent-encodings before sending. Both sides now sign the same normalised address (RFC 3986): page map and backfill queue work on such pages.
* A request that carries the internal-visit header but fails the check (format, expired, signature, replay) no longer becomes an ordinary page view: nothing is machine translated or saved, the page is not cached, and the reason is reported ("internal visit signature not accepted").

= 0.6.0 =
* Page map (new tab "Page map & test journal", `wp aitp map`): read-only visits record which page-text rows each page shows, including text outside the post content; candidate list per page for page-by-page comparisons.
* Repairs and the auto repair reset only rows on a known page and queue or visit only one such page per row; rows on no known page are left as they are unless you opt in (then every page of that language is queued, as before). A rollback no longer queues pages.
* Test journal (`wp aitp journal`): records what this engine translates during a test and undoes it exactly; rows TranslatePress added are listed, never deleted.

= 0.5.0 =
* Batch auto repair (off by default) on the Audit & repair page and `wp aitp autofix`: batch by batch, checked after each batch, stops by itself on failures; every batch can be rolled back.

= 0.4.0 =
* Accepted list for reviewed translations (admin page and `wp aitp audit protect|unprotect|protected`): listed by the check, never repaired.
* The backfill queue and repair stop while TranslatePress uses another engine (for example HHG) or automatic translation is off.
* Clearer notice when HHG is still the selected engine. Languages in imported lists are matched case-insensitively.

= 0.3.0 =
* Audit & repair: checks old machine translations (misaligned, English, cut short, broken structured data, lost placeholders), candidate list import, optional AI check, backed-up repair through the backfill queue, exact rollback per batch, WP-CLI commands.

= 0.2.0 =
* Backfill queue: translates pages nobody has visited yet, with speed and character limits, visitor reserve, pause on provider problems, page-cache bypass, signed internal visits, progress page and WP-CLI commands.

= 0.1.0 =
* First release: Gemini and OpenAI-compatible engines, ID-based mapping, whole-answer misalignment check, validation, long-text splitting, cache, back-off, circuit breaker, review list, status page, Simplified Chinese interface.
