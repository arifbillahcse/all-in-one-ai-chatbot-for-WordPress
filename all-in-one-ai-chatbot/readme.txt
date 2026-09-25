=== All in One AI Chatbot ===
Contributors: softorio
Tags: ai, chatbot, customer support, live chat, openai
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI support assistant that answers visitors from your own pages, posts and knowledge articles. Use your own OpenAI, Claude, Gemini, DeepSeek or OpenRouter API key.

== Description ==

All in One AI Chatbot adds a chat widget to your site that answers visitors' questions **using your own content**: your pages, posts, and Knowledge Articles you write just for the assistant, such as refund rules, delivery times, opening hours and how-to steps.

It does not invent answers. When your content does not cover a question, it says so and offers your WhatsApp, email or contact page instead.

**Built for real support work**

* Answers from your own content, and links to the page it used.
* Knowledge Articles: a private section for FAQs and policies. It is never shown on your site, only used by the assistant.
* Content updates itself: publish or edit a page and the assistant knows about it right away.
* Hide any page from the assistant with one checkbox in the editor.
* Replies in the visitor's language, including Bangla and other non-Latin scripts.
* "Talk to a person" button for WhatsApp, email or your contact page.
* Read every conversation in WP Admin to see what visitors ask and what your content is missing.

**WooCommerce shop assistant**

* Finds and recommends products using live data: prices, sale prices, stock, sizes and colours.
* Shows product cards in the chat with photos and an Add to cart button. Simple products go straight into the real WooCommerce cart without leaving the chat.
* Order tracking: logged-in customers see their own orders. Guests prove the order is theirs with the billing email or phone, lookups are rate limited, and a failed lookup never reveals which detail was wrong.
* Shows the shop's customer notes and shipment tracking (WooCommerce Shipment Tracking, or any courier plugin through a filter).
* Recognises logged-in customers and greets them by name.
* Compatible with High-Performance Order Storage (HPOS).

**Leads and alerts**

* Lead capture: before the chat, or only when the assistant can't help or the visitor asks for a person.
* You choose the fields (name, email, phone), with an optional GDPR consent checkbox.
* Leads screen with statuses, search and CSV export.
* Instant alerts by email and Telegram, and chat transcripts by email.
* Webhooks to Zapier, Make, n8n, Google Sheets or any CRM, signed with HMAC-SHA256.
* Works with WordPress's personal data export and erase tools.
* Activity log that shows every email, alert and webhook, with automatic retries.

**You control the cost**

* Bring your own API key from OpenAI, Anthropic (Claude), Google Gemini, DeepSeek or OpenRouter (hundreds of models with one key). You pay the provider directly, with no middleman markup.
* Cheap, fast models by default. A typical answer costs a fraction of a US cent.
* A daily budget, a daily answer cap and a per-visitor limit stop abuse and surprise bills.
* An optional backup provider takes over automatically if your main one fails.

**A better chat experience**

* Live typing: answers appear word by word as the AI writes them. If your hosting blocks streaming, the widget falls back to normal replies on its own.
* 👍 / 👎 on every answer, with a satisfaction score on the overview and ratings in each transcript.
* A "Checking…" indicator while the assistant looks up products or orders.

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
* **Google Gemini**: used when Gemini is selected as the main or backup provider. [Terms of service](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy).
* **OpenRouter**: used when OpenRouter is selected. OpenRouter passes the request to the model vendor you choose. [Terms](https://openrouter.ai/terms), [Privacy policy](https://openrouter.ai/privacy).
* **Telegram**: only if you add a Telegram bot token. Lead and alert details are sent to your own bot chat. [Terms](https://telegram.org/tos), [Privacy policy](https://telegram.org/privacy).
* **Your webhook URLs**: only if you add them. Event data, including lead contact details and chat transcripts, is sent to the URLs you enter.
* **DeepSeek**: used when DeepSeek is selected as the main or backup provider. [Terms of use](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html), [Privacy policy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html).

Suggested wording for your privacy policy is added under Settings → Privacy.

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or install it from the plugin directory, and activate it.
2. Go to **AI Chatbot → Settings**, choose a provider, paste your API key and press **Test connection**.
3. Add your WhatsApp number, email or contact page under "Talk to a person".
4. Go to **AI Chatbot → Knowledge Articles** and add your FAQs, policies and anything else visitors ask about.
5. Open **AI Chatbot → Overview**: the setup checklist shows what is left, and "Test what the assistant finds" lets you check answers without spending anything.

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

Yes. Turn it on under AI Chatbot → Settings → WooCommerce. Logged-in customers can ask about their own orders. Guests need the order number plus the email or phone they used at checkout, or you can allow logged-in customers only.

= Which AI providers work with the shop tools? =

All three: OpenAI, Claude and DeepSeek support tool calling. Each shop question may take 2–3 AI calls (search, then answer), so it costs a little more than a plain question.

== Developer hooks ==

* `softorio_ai_show_widget` (filter): whether the widget shows on the current page.
* `softorio_ai_index_content` (filter): the plain text indexed for a post; append page-builder or custom-field text here.
* `softorio_ai_should_index` (filter): whether a post is indexed at all.
* `softorio_ai_pricing` (filter): token prices used for cost estimates.
* `softorio_ai_provider` (filter): replace or add an AI provider.
* `softorio_ai_answered` (action): fires after each answer, with the question, reply and conversation id.
* `softorio_ai_event` (action): every plugin event (lead.created, handoff.requested, question.unanswered, conversation.ended, message.answered) with its payload.
* `softorio_ai_tools` (filter): add your own tools the AI can call (implement `Softorio\AiAssistant\Tools\Tool`).
* `softorio_ai_prompt_parts` (filter): add instructions to the system prompt.
* `softorio_ai_post_types` (filter): post types the assistant reads.
* `softorio_ai_find_order` (filter): resolve custom or sequential order numbers to an order.
* `softorio_ai_order_tracking` (filter): add shipment tracking from courier plugins (Pathao, Steadfast, RedX and others).
* `softorio_ai_provider_endpoint` (filter): change a provider's API URL (proxies, regional endpoints).

== Changelog ==

= 1.3.0 =
* New: Google Gemini and OpenRouter providers. OpenRouter reports its exact cost per answer.
* New: live typing (streaming) for every provider, with automatic fallback; the backup provider takes over when the main one fails before anything has been shown.
* New: 👍 / 👎 answer feedback, a satisfaction score on the overview, and an answer.rated webhook event.
* Fix: the "no answer" marker is hidden reliably, even when it arrives split across streamed pieces.

= 1.2.0 =
* New: WooCommerce shop assistant, with product search and recommendations, product cards, add to cart from the chat, and order tracking with ownership checks.
* New: AI tool calling for OpenAI, Claude and DeepSeek, with a safety cap on tool rounds.
* New: logged-in visitors are recognised (their name, their orders).
* Declares WooCommerce HPOS compatibility.

= 1.1.0 =
* New: lead capture (before the chat, or when the assistant can't answer), with a consent checkbox.
* New: Leads screen with CSV export, and personal data export and erase.
* New: email and Telegram alerts, chat transcripts, webhooks.
* New: detects questions the assistant could not answer.
* New: tabbed settings and an Activity Log.
* Fix: restoring chat history on sites without pretty permalinks.

= 1.0.0 =
* First release.
