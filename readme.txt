=== AI Translator for TranslatePress ===
Contributors: alankongx
Tags: translatepress, translation, gemini, openai, multilingual
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
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

If another engine plugin (for example "HHG for TranslatePress") is active, switch the engine first and then deactivate the other plugin.

== Frequently Asked Questions ==

= Why are some strings still in English? =

They failed validation or were not reached within the page view's time budget. They are retried on later page views. Settings → AI Translator → Status & log shows the reasons.

= Does it replace existing translations? =

No. It only translates strings TranslatePress sends to an engine, which are strings without a translation. Manual translations are never sent.

= Updates =

Updates come from the GitHub releases of this plugin. To turn update checks off: `add_filter( 'aitp_github_updates', '__return_false' );`

== Changelog ==

= 0.2.0 =
* Backfill queue: translates pages nobody has visited yet, with speed and character limits, visitor reserve, pause on provider problems, page-cache bypass, signed internal visits, progress page and WP-CLI commands.

= 0.1.0 =
* First release: Gemini and OpenAI-compatible engines, ID-based mapping, whole-answer misalignment check, validation, long-text splitting, cache, back-off, circuit breaker, review list, status page, Simplified Chinese interface.
