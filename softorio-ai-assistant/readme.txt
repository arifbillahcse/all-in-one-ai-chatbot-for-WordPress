=== Softorio AI Assistant ===
Contributors: softorio
Tags: ai, chatbot, customer support, live chat, openai
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI support assistant that answers visitors from your own pages, posts and knowledge articles. Use your own OpenAI, Claude or DeepSeek API key.

== Description ==

Softorio AI Assistant adds a chat widget to your site that answers visitors' questions **using your own content**: your pages, posts, and Knowledge Articles you write just for the assistant, such as refund rules, delivery times, opening hours and how-to steps.

It does not invent answers. When your content does not cover a question, it says so and offers your WhatsApp, email or contact page instead.

**Built for real support work**

* Answers from your own content, and links to the page it used.
* Knowledge Articles: a private section for FAQs and policies. It is never shown on your site, only used by the assistant.
* Content updates itself: publish or edit a page and the assistant knows about it right away.
* Hide any page from the assistant with one checkbox in the editor.
* Replies in the visitor's language, including Bangla and other non-Latin scripts.
* "Talk to a person" button for WhatsApp, email or your contact page.
* Read every conversation in WP Admin to see what visitors ask and what your content is missing.

**You control the cost**

* Bring your own API key from OpenAI, Anthropic (Claude) or DeepSeek. You pay the provider directly, with no middleman markup.
* Cheap, fast models by default. A typical answer costs a fraction of a US cent.
* A daily budget, a daily answer cap and a per-visitor limit stop abuse and surprise bills.
* An optional backup provider takes over automatically if your main one fails.

**Private and safe by default**

* API keys are stored encrypted.
* Drafts, private and password-protected content are never used.
* Conversations are deleted automatically after a period you choose.
* The widget runs in an isolated Shadow DOM, so it does not clash with your theme, and AI answers are never inserted as HTML.
* Works with full-page caching; it does not rely on nonces baked into cached pages.

== External services ==

This plugin sends data to a third-party AI service to generate answers. **Nothing is sent until you enter an API key**, and only to the provider(s) you configure.

When a visitor sends a chat message, the plugin sends the provider: the visitor's message, the recent messages of the same conversation, the passages of your site content that best match the question, the URL of the page the visitor is on, and the instructions you wrote in the settings. The visitor's IP address, name and email are **not** sent.

* **OpenAI**: used when OpenAI is selected as the main or backup provider, when you press "Test connection" for OpenAI, and for semantic search if you turn it on. With semantic search on, the text of your published content is also sent when it is indexed. [Terms of use](https://openai.com/policies/terms-of-use/), [Privacy policy](https://openai.com/policies/privacy-policy/).
* **Anthropic (Claude)**: used when Claude is selected as the main or backup provider. [Commercial terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy).
* **DeepSeek**: used when DeepSeek is selected as the main or backup provider. [Terms of use](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html), [Privacy policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html).

Suggested wording for your privacy policy is added under Settings → Privacy.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or install it from the plugin directory, and activate it.
2. Go to **AI Assistant → Settings**, choose a provider, paste your API key and press **Test connection**.
3. Add your WhatsApp number, email or contact page under "Talk to a person".
4. Go to **AI Assistant → Knowledge Articles** and add your FAQs, policies and anything else visitors ask about.
5. Open **AI Assistant → Overview**: the setup checklist shows what is left, and "Test what the assistant finds" lets you check answers without spending anything.

Your existing content is indexed in the background after activation. Press **Rebuild index now** on the Overview screen to do it immediately.

== Frequently Asked Questions ==

= Which provider should I choose? =

All three work well for support answers. DeepSeek is usually cheapest. OpenAI (gpt-5-mini) and Claude (Haiku) are fast and good at following your instructions in many languages. You can set one as a backup for the other.

= How much will it cost? =

Each answer sends your question plus a few passages of your content, typically 1,000–2,000 tokens, and gets back a short reply. On the default models that is usually well under one US cent per answer. The Overview screen shows estimated spend, and the daily budget stops the assistant once it is reached.

= The assistant says it doesn't know something that is on my site. =

Use "Test what the assistant finds" on the Overview screen with the same question. If your page does not appear, add a Knowledge Article that answers the question in plain words, or turn on semantic search. Content kept outside the normal editor, such as some page builders and custom fields, can be added with the `softorio_ai_index_content` filter.

= Does it work with page caching? =

Yes. The chat endpoint does not depend on anything cached in the page.

= Can I hide the widget on some pages? =

Use the `softorio_ai_show_widget` filter, for example `add_filter( 'softorio_ai_show_widget', fn( $show ) => $show && ! is_page( 'checkout' ) );`.

= Does it support WooCommerce orders? =

Not yet. Product and order tools are planned for a future version.

== Developer hooks ==

* `softorio_ai_show_widget` (filter): whether the widget shows on the current page.
* `softorio_ai_index_content` (filter): the plain text indexed for a post; append page-builder or custom-field text here.
* `softorio_ai_should_index` (filter): whether a post is indexed at all.
* `softorio_ai_pricing` (filter): token prices used for cost estimates.
* `softorio_ai_provider` (filter): replace or add an AI provider.
* `softorio_ai_answered` (action): fires after each answer, with the question, reply and conversation id.

== Changelog ==

= 1.0.0 =
* First release.
