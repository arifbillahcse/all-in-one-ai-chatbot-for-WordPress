=== All in One AI Chatbot ===
Contributors: softorio
Tags: ai, chatbot, customer support, live chat, openai
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.8.0
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

**Teach it from anything**

* Upload documents: PDF, Word (.docx), OpenDocument, text, Markdown and HTML, up to 20 at a time. Text is read on your own server, including Bangla PDFs; the files themselves are not kept.
* Import FAQs from a spreadsheet (CSV from Excel or Google Sheets). Re-import after editing to update answers.
* Import web pages: a help centre on another site, a sitemap, or a whole site. Pages are fetched politely in the background, respect robots.txt, and can be re-checked daily or weekly. Answers link back to the page.
* Everything imported becomes a Knowledge Article you can read and edit.

**Live chat with your team**

* Visitors can ask for a person ("Chat with our team"), and your team can take over any AI conversation. While a person answers, the AI stays quiet and costs nothing.
* A Live Chat inbox in WP Admin: waiting visitors first, sound and desktop alerts, typing indicators, visitor details, "Hand back to AI" and "End live chat".
* Alerts on every admin screen and a counter in the menu, so agents working on orders still hear a waiting visitor.
* Give shop managers (or other roles) the Live Chat screen without full admin access.
* Answer from your phone: live chats are posted to Telegram, and replying there reaches the visitor.
* Visitors are only offered a live chat while someone is online and set to Available. If nobody answers within a few minutes, the AI takes over again and offers the lead form.

**Analytics and reports**

* Analytics screen: conversations, leads and conversion, share of questions the AI answered, satisfaction, live chats and average wait, and AI cost, each compared with the period before (7 days, 30 days, 90 days, 12 months).
* Knowledge gaps: the questions the assistant could not answer, grouped, with an "Add the answer" button that opens a new Knowledge Article titled with the question.
* What visitors ask most (similar wordings grouped, English and Bangla), answers rated 👎, pages where chats start, the knowledge used most, and a heatmap of when visitors chat.
* Weekly or monthly report by email to you and your client, with the numbers, top questions and knowledge gaps.
* Charts are drawn in WordPress itself: no external scripts, nothing sent anywhere. Daily totals are kept after old chats are deleted, without personal data. CSV export.

**CRM and email marketing**

* Every new lead goes straight to HubSpot, Mailchimp and/or Brevo: no Zapier needed.
* HubSpot: contact created or updated by email, with the lead's message, page and chat attached as a note. Existing contacts keep their lifecycle stage.
* Mailchimp: added to your audience with double opt-in by default, tagged, with a note.
* Brevo: contact added to your lists, with the phone number in international format for SMS and WhatsApp campaigns.
* Sent in the background with automatic retries. The Leads screen shows what was sent where, and why anything was skipped or failed, with "Send again" and "Send unsent leads" buttons.
* Optional: only send leads who gave consent.

**Members and logged-in visitors**

* Members-only knowledge: mark any page, article, document or import for everyone, logged-in users, or chosen roles (e.g. customers or a "Gold member" role). Visitors who are not logged in never get members-only answers.
* A personal greeting for logged-in visitors ("Welcome back, Karim!"), their details pre-filled in the lead form, or no lead form at all.
* A conversation started while logged in cannot be reopened by the same browser after logging out.

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

**Your brand, your rules**

* Avatar or logo in the chat header and on the chat button, a choice of button icons, and an optional "Chat with us" text button.
* Pop-up greeting next to the chat button after a delay you choose, once per visit, on every page or only the pages you list.
* Show the widget on every page, only on some pages, or everywhere except some pages (with * patterns like /product/*).
* Business hours: an online/offline status in the header, an offline notice, or hide the chat outside hours. The assistant knows when your team is back.
* Quick Replies: a menu of buttons with sub-menus that answer common questions instantly, without an AI call. Buttons can also open a link, the lead form, your contact options, or ask the AI.
* Embed the chat inside any page with the "AI Chatbot" block or the `[ai_chatbot height="520"]` shortcode.
* Voice input: visitors can speak their question (Chrome, Edge and Safari), including in Bangla.

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
* **Web pages you import**: only the addresses you enter under Knowledge Sources → Import from websites (and their robots.txt and sitemaps) are downloaded, and again on the re-check schedule you choose. No data about your visitors is sent.
* **Telegram live-chat replies**: only if you switch them on under Settings → Live Chat. Live-chat messages (visitor messages and the page they are on) are posted to your own Telegram chat, and Telegram sends your replies back to the site's webhook.
* **HubSpot, Mailchimp, Brevo**: only for the services you switch on under Settings → Integrations. Each new lead's name, email, phone and message are sent, and for HubSpot and Mailchimp a note with the page and the chat. [HubSpot privacy policy](https://legal.hubspot.com/privacy-policy), [Mailchimp (Intuit) privacy statement](https://www.intuit.com/privacy/statement/), [Brevo privacy policy](https://www.brevo.com/legal/privacypolicy/).
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

= Which files can it learn from? =

PDF, Word (.docx), OpenDocument (.odt), text, Markdown and HTML, under AI Chatbot → Knowledge Sources. PDFs must contain real text: a scanned PDF is a picture of text and cannot be read (copy its text into a Knowledge Article, or connect an OCR service with the `softorio_ai_extract_text` filter). Password-protected PDFs need an unprotected copy.

= How do I give members extra answers? =

Turn on Settings → Knowledge → Members-only knowledge. Then choose "Who can the assistant share this with?" in the AI Chatbot box of any page or Knowledge Article, or when importing. Logged-in users (or the roles you tick) get those answers; everyone else does not.

= Does live chat work on shared hosting? =

Yes. It uses short, lightweight requests (no websockets or extra services), and only while a live chat is going on. Visitors are offered a live chat only while someone from your team has WP Admin open and is set to Available.

= Can I answer live chats from my phone? =

Yes, with Telegram: set up Telegram under Notifications, switch on "Telegram replies" under Live Chat and press Connect (the site needs HTTPS). Reply to a chat's message in Telegram to answer; send /ai to hand back to the AI or /end to end the chat.

= Does the Analytics screen slow down my site? =

No. It only runs when you open it in WP Admin, with a few small grouped queries. Visitors are never affected. Charts are plain SVG, with no chart library.

= Which CRMs are supported? =

HubSpot, Mailchimp and Brevo directly (Settings → Integrations, each with a "Test connection" button). For anything else (Google Sheets, Zoho, Pipedrive, Salesforce, FluentCRM…) use the webhooks with Zapier, Make or n8n. Developers can add a connector with the `softorio_ai_crm_clients` filter.

= Can I hide the widget on some pages? =

Yes. Go to AI Chatbot → Settings → Widget → Where to show the widget, and list pages like /checkout/ or patterns like /my-account/*. Developers can also use the `softorio_ai_show_widget` filter, for example `add_filter( 'softorio_ai_show_widget', fn( $show ) => $show && ! is_page( 'checkout' ) );`.

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
* `softorio_ai_extract_text` (filter): read uploaded files yourself, e.g. send scanned PDFs to an OCR service.
* `softorio_ai_live_visitor_message` (action): a visitor wrote during a live chat (conversation id, text, message id).
* Events `live.requested`, `live.started` and `live.ended` for webhooks and integrations.
* `softorio_ai_crm_fields` (filter): change the fields sent to a CRM (receives the fields, the lead and the CRM id).
* `softorio_ai_crm_clients` (filter): add your own CRM connector (extend `Softorio\AiAssistant\Crm\CrmClient`).

== Changelog ==

= 1.8.0 =
* New: Analytics screen with KPIs and changes against the previous period, conversations and leads chart, AI cost chart, busiest-times heatmap, pages where chats start and most used knowledge.
* New: knowledge gaps (unanswered questions grouped, "Add the answer", dismiss until asked again) and most asked questions.
* New: answers rated 👎 with their questions.
* New: weekly or monthly report emails with a "Send a report now" button.
* New: daily totals kept beyond the conversation retention period (no personal data); CSV export.

= 1.7.0 =
* New: HubSpot, Mailchimp and Brevo integrations. New leads are added or updated automatically in the background, with retries, notes carrying the chat, tags and lists.
* New: CRM status on every lead (sent, skipped, failed with the reason), "Send again" per lead and "Send unsent leads" for leads captured before a CRM was connected.
* New: phone numbers converted to international format with a default country code (880 for Bangladesh).
* New: option to only send leads who gave consent; privacy policy text mentions CRMs.

= 1.6.0 =
* New: live chat. Visitors can ask for a person; agents can take over any conversation, reply, hand back to the AI or end the chat. The AI stays quiet (and free) while a person answers, and learns what the agent said when it takes over again.
* New: Live Chat inbox with waiting visitors first, unread counts, typing indicators, visitor details, sound and desktop notifications, plus alerts and a menu counter on every admin screen.
* New: agent presence (Available / Away); visitors are offered a live chat only while someone is online, and waiting visitors go back to the AI with a lead-form offer after a timeout you choose.
* New: answer live chats from Telegram by replying to the posted message (/ai and /end commands).
* New: choose which roles (e.g. Shop manager) can answer live chats.
* New: live.requested, live.started and live.ended events for email, Telegram and webhooks.

= 1.5.0 =
* New: Knowledge Sources screen. Upload PDF, Word, OpenDocument, text, Markdown and HTML files; import FAQs from CSV; import web pages, sitemaps or whole sites in the background, with an optional daily or weekly re-check.
* New: members-only knowledge by logged-in status or user role, for pages, Knowledge Articles and imports. Filtering happens in the search itself.
* New: personal greeting, pre-filled lead form and an option to skip the lead form for logged-in visitors; the assistant knows the visitor's account type.
* New: "Source" and "Who can see it" columns on Knowledge Articles; members-only results marked 🔒 in the admin search preview.
* Security: a conversation started while logged in can only be resumed by the same account.

= 1.4.0 =
* New: avatar or logo, chat button icon choices and an optional text button.
* New: pop-up greeting, shown once per visit, with page targeting and an optional phone setting.
* New: show or hide the widget by page, with * patterns.
* New: business hours with online/offline status, an offline notice or hiding the chat, and hours-aware answers.
* New: Quick Replies builder (AI Chatbot → Quick Replies): button menus with sub-menus, links, the lead form, contact options or an AI question.
* New: inline chat block and [ai_chatbot] shortcode.
* New: voice input.

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
