# All in One AI Chatbot for WordPress

An AI support assistant that answers visitors **from your own website content**: pages, posts, products, documents and Knowledge Articles you write for it. Bring your own AI key, so there is no middleman markup and no monthly fee to a chatbot vendor.

**Version 2.0.0** · WordPress 6.4+ · PHP 8.1 to 8.5 · Works with WooCommerce (optional) · Includes Bangla (বাংলা)

> It does not invent answers. If your content does not cover a question, it says so and offers WhatsApp, email, a lead form or a live person instead.

---

## Contents

- [Quick start](#quick-start)
- [Features](#features)
- [Settings map](#settings-map)
- [Privacy and security](#privacy-and-security)
- [Cost control](#cost-control)
- [For developers](#for-developers)
- [Build and test](#build-and-test)
- [Changelog](#changelog)

---

## Quick start

1. Upload the built zip: **Plugins → Add New → Upload Plugin → Activate**.
2. The **setup wizard** opens. Pick an AI provider, paste the API key (it is checked straight away), name your assistant and choose what it reads.
3. Add FAQs and policies under **AI Chatbot → Knowledge Articles**, or import documents under **Knowledge Sources**.
4. Open **AI Chatbot → Overview**. The checklist shows what is left, and *Test what the assistant finds* lets you check answers without spending anything.

The widget stays hidden until an AI key is saved. Existing content is indexed in the background after activation.

> Installing from GitHub? Do not upload GitHub's "Download ZIP" or clone into `wp-content/plugins`. Build the clean zip with `php bin/build-zip.php` (see [Build and test](#build-and-test)) and upload that.

---

## Features

Every optional feature has its own on/off switch and starts **off**.

### 1. Answers from your own content

- Searches your pages, posts, products and Knowledge Articles (BM25 ranking, no external search service).
- Links to the page it used for each answer.
- Replies in the visitor's language, including Bangla and other non-Latin scripts.
- Updates itself: publish or edit a page and the assistant knows straight away.
- Hide any page from the assistant with one checkbox in the editor.
- Drafts, private and password-protected content are never used.
- Optional semantic search for better matching.
- **Knowledge Articles**: a private post type for FAQs, refund rules, delivery times and opening hours. Never shown on the site.
- *Test what the assistant finds*: preview search results with no AI cost.

### 2. AI providers

- **OpenAI, Claude (Anthropic), Google Gemini, DeepSeek and OpenRouter** (hundreds of models with one key).
- Cheap, fast default models.
- Optional **backup provider** that takes over automatically if the main one fails.
- Per-provider *Test connection* button; API keys stored encrypted.
- Tool calling on every provider (used by the shop assistant).

### 3. Better chat experience

- **Live typing** (streaming): answers appear word by word, with automatic fallback if hosting blocks streaming.
- 👍 / 👎 on every answer, a satisfaction score and an `answer.rated` event.
- A "Checking…" indicator while products or orders are looked up.
- **Voice input** (Chrome, Edge, Safari), including Bangla.
- Chat history restored on reload; the widget runs in a Shadow DOM so it never clashes with your theme.

### 4. Teach it from anything (Knowledge Sources)

- **Documents**: PDF, Word (.docx), OpenDocument, text, Markdown and HTML, up to 20 at a time. Text is read on your own server, including Bangla PDFs; files are not kept.
- **FAQ spreadsheets**: import CSV from Excel or Google Sheets; re-import to update.
- **Websites**: import a help centre, a sitemap or a whole site. Fetched politely in the background, respects robots.txt, with optional daily or weekly re-checks.
- Everything imported becomes an editable Knowledge Article.

### 5. Members and logged-in visitors

- **Members-only knowledge**: share any page, article, document or import with everyone, logged-in users, or chosen roles (e.g. customers, a "Gold member" role). Filtered inside the search query, so guests never get members-only answers.
- Personal greeting for logged-in visitors ("Welcome back, Karim!"), pre-filled lead form, or no lead form at all.
- A conversation started while logged in cannot be reopened after logging out.

### 6. WooCommerce shop assistant

- Finds and recommends products using live data: price, sale price, stock, sizes and colours.
- Product cards with photos and **Add to cart** straight into the real WooCommerce cart.
- **Order tracking**: logged-in customers see their own orders; guests prove ownership with order number plus billing email or phone. Rate limited, and a failed lookup never reveals which detail was wrong.
- Shows customer notes and shipment tracking (WooCommerce Shipment Tracking or any courier plugin through a filter, e.g. Pathao, Steadfast, RedX).
- HPOS compatible.

### 7. Leads

- Lead form **before the chat**, or **only when the assistant cannot help** or the visitor asks for a person.
- You choose the fields (name, email, phone) and an optional GDPR consent checkbox.
- **Leads screen** with statuses, search and CSV export.
- Works with WordPress personal data export and erase tools.

### 8. Alerts and automation

- Instant **email and Telegram** alerts, and chat transcripts by email.
- **Signed webhooks** (HMAC-SHA256) to Zapier, Make, n8n, Google Sheets or any CRM.
- Events: `lead.created`, `handoff.requested`, `question.unanswered`, `conversation.ended`, `message.answered`, `answer.rated`, `live.requested`, `live.started`, `live.ended`.
- Background queue with automatic retries and an **Activity Log** that shows every email, alert and webhook.

### 9. CRM and email marketing

- **HubSpot**: contact created or updated by email, with a note carrying the message, page and chat. Existing lifecycle stages are kept.
- **Mailchimp**: added to your audience (double opt-in by default), tagged, with a note.
- **Brevo**: added to your lists, phone converted to international format (default country code 880 for Bangladesh).
- Sent in the background with retries; per-lead status (sent, skipped, failed and why); **Send again** and **Send unsent leads**.
- Optional: only send leads who gave consent. Developers can add connectors.

### 10. Live chat with your team

- Visitors can ask for a person; your team can take over **any** AI conversation. The AI stays quiet (and free) meanwhile, then learns what the agent said when handed back.
- **Live Chat inbox** in WP Admin: waiting visitors first, unread counts, typing indicators, visitor details, sound and desktop notifications, *Hand back to AI*, *End live chat*.
- Alerts and a counter on every admin screen.
- Agent presence (Available / Away): visitors are only offered live chat while someone is online. After a timeout the AI takes over and offers the lead form.
- Choose which roles (e.g. Shop manager) can answer, without full admin access.
- **Reply from Telegram**: live chats are posted to your bot; replying reaches the visitor. `/ai` hands back, `/end` ends the chat.
- Works on shared hosting: lightweight polling, no websockets.

### 11. Widget design and targeting

- Avatar or logo, button icon choices, optional "Chat with us" text button, brand colour, left or right position.
- **Pop-up greeting** after a delay, once per visit.
- Show on every page, only some pages, or everywhere except some pages (`/product/*` patterns).
- **Business hours**: online/offline status, offline notice, or hide the chat outside hours; the assistant knows when your team is back.
- **Quick Replies**: button menus with sub-menus that answer common questions instantly with no AI call. Buttons can open a link, the lead form, contact options, or ask the AI.
- **Embed in a page** with the *AI Chatbot* block or `[ai_chatbot height="520"]`.
- Works with full-page caching.

### 12. Analytics and reports

- KPIs each compared with the previous period (7 / 30 / 90 days, 12 months): conversations, leads and conversion, answered rate, satisfaction, live chats and wait time, AI cost.
- **Knowledge gaps**: unanswered questions grouped, with **Add the answer** opening a pre-titled Knowledge Article.
- Most asked questions (similar wording grouped, English and Bangla), answers rated 👎, pages where chats start, most used knowledge, busiest-times heatmap.
- **Weekly or monthly report by email** to you and your client, plus *Send a report now*.
- Server-rendered SVG charts: no external scripts, nothing sent anywhere. Daily totals survive conversation deletion (no personal data). CSV export.

### 13. Setup and maintenance (2.0)

- **Setup wizard** after activation: connect AI with a live key check, name and greeting, content, contact options.
- **Site Health checks**: AI key and provider errors, knowledge index, background tasks (WP-Cron), required PHP extensions, each with a plain fix; a copy-paste **Info** section for support requests with no keys or personal data.
- **Translation ready**: every string translatable, `.pot` included, **Bangla (bn_BD)** included for everything visitors see.
- **Clean uninstall**: cron always removed; tick *Delete all plugin data* to also remove tables, settings, Knowledge Articles and per-page settings (every site on multisite).
- Tested on large data: 3,000 posts, 50,000 conversations and 200,000 messages. Searches under 10 ms, Analytics under half a second.

---

## Settings map

| Screen | What it does |
|---|---|
| **Overview** | Setup checklist, index status, satisfaction and cost, search preview |
| **Live Chat** | Inbox for taking over conversations |
| **Analytics** | KPIs, charts, knowledge gaps, reports |
| **Knowledge Sources** | Documents, FAQ CSV, website import |
| **Knowledge Articles** | Your own private answers |
| **Conversations** | Every transcript, with ratings |
| **Leads** | Captured contacts, CRM status, CSV export |
| **Quick Replies** | Button menu builder |
| **Settings** | AI, Widget, Assistant, Knowledge, Leads, Notifications, WooCommerce, Live Chat, Integrations, Limits and Privacy |
| **Activity Log** | Emails, alerts, webhooks, CRM jobs, retries |

---

## Privacy and security

- **Nothing is sent to an AI provider until you enter a key**, and only to the provider(s) you configure. Visitor IP, name and email are never sent to the AI.
- API keys stored encrypted; answers are never inserted as HTML.
- Conversations deleted automatically after a retention period you choose; suggested privacy-policy wording is generated for you.
- Knowledge Articles are readable through the REST API only by users who can edit them (fixed in 2.0.0; earlier versions exposed them, so update).
- Every admin action checks capability and nonce; no unauthenticated AJAX actions; IP taken from `REMOTE_ADDR` (Cloudflare header only if you opt in).
- Code passes the WordPress coding and security standards (PHPCS + WPCS).

---

## Cost control

- You pay your provider directly. A typical answer costs a fraction of a US cent on the default models.
- **Daily budget**, **daily answer cap** and **per-visitor limit** stop abuse and surprise bills.
- Overview shows estimated spend; OpenRouter reports exact cost per answer.
- Chats with a live agent cost nothing.

---

## For developers

Filters and actions:

- `softorio_ai_show_widget`, `softorio_ai_index_content`, `softorio_ai_should_index`, `softorio_ai_post_types`
- `softorio_ai_pricing`, `softorio_ai_provider`, `softorio_ai_provider_endpoint`, `softorio_ai_prompt_parts`
- `softorio_ai_tools` (add tools the AI can call; implement `Softorio\AiAssistant\Tools\Tool`)
- `softorio_ai_find_order`, `softorio_ai_order_tracking`
- `softorio_ai_extract_text` (plug in OCR for scanned PDFs)
- `softorio_ai_crm_fields`, `softorio_ai_crm_clients` (extend `Softorio\AiAssistant\Crm\CrmClient`)
- `softorio_ai_answered`, `softorio_ai_event`, `softorio_ai_live_visitor_message` (actions)

Basics: namespace `Softorio\AiAssistant`, option `softorio_ai_settings`, REST namespace `softorio-ai/v1`, custom capability `softorio_ai_live_chat`.

---

## Build and test

```bash
# Clean installable zip (no tests or dev tools) -> ../dist/all-in-one-ai-chatbot-<version>.zip
php bin/build-zip.php

# Tests (SQLite-based WordPress test site)
WP_PATH=/path/to/wordpress php tests/run.php

# Coding standards
phpcs --standard=phpcs.xml.dist .

# Translations
php bin/make-pot.php
WP_PATH=/path/to/wordpress php bin/make-mo.php
```

---

## Changelog

| Version | Highlights |
|---|---|
| **2.0.0** | Setup wizard, Site Health, translations with Bangla, clean uninstall, **security fix for REST exposure of Knowledge Articles**, large-site performance |
| 1.8.0 | Analytics, knowledge gaps, report emails |
| 1.7.0 | HubSpot, Mailchimp, Brevo |
| 1.6.0 | Live chat and Telegram replies |
| 1.5.0 | Knowledge Sources, members-only knowledge |
| 1.4.0 | Widget design, business hours, Quick Replies, voice input |
| 1.3.0 | Gemini, OpenRouter, streaming, feedback |
| 1.2.0 | WooCommerce shop assistant |
| 1.1.0 | Leads, alerts, webhooks |
| 1.0.0 | First release |

Full details are in [`readme.txt`](readme.txt).

## License

GPL-2.0-or-later.
