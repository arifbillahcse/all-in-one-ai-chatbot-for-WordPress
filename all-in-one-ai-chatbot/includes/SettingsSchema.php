<?php
/**
 * Declarative description of every setting.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * One list that drives defaults, the settings screen and input validation.
 *
 * Adding a setting means adding one entry here: the admin form renders it,
 * the sanitizer validates it by its type, and Settings::defaults() picks up
 * its default. Nothing else has to be kept in step by hand.
 *
 * Field keys:
 *   tab, section  where it appears
 *   type          text|textarea|number|float|checkbox|select|radio|color|
 *                 email|url|secret|model|phone|post_types
 *   label, desc   shown in the admin
 *   default       value before the owner saves anything
 *   options       for select/radio: value => label
 *   min, max      for number/float; max also caps text length
 *   provider      for secret: which provider "Test connection" checks
 */
final class SettingsSchema {

	/**
	 * Admin tabs, in display order.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function tabs(): array {
		$tabs = array(
			'general'   => __( 'Assistant', 'all-in-one-ai-chatbot' ),
			'ai'        => __( 'AI Provider', 'all-in-one-ai-chatbot' ),
			'knowledge' => __( 'Knowledge', 'all-in-one-ai-chatbot' ),
			'widget'    => __( 'Widget', 'all-in-one-ai-chatbot' ),
			'leads'     => __( 'Leads', 'all-in-one-ai-chatbot' ),
			'notify'    => __( 'Notifications', 'all-in-one-ai-chatbot' ),
			'integrations' => __( 'Integrations', 'all-in-one-ai-chatbot' ),
			'limits'    => __( 'Limits & Privacy', 'all-in-one-ai-chatbot' ),
		);

		/**
		 * Filter the settings tabs. Feature modules add their own here.
		 *
		 * @param array<string, string> $tabs slug => label.
		 */
		return (array) apply_filters( 'softorio_ai_settings_tabs', $tabs );
	}

	/**
	 * Section headings and intros, keyed "tab.section".
	 *
	 * @return array<string, array{title: string, desc?: string}>
	 */
	public static function sections(): array {
		$sections = array(
			'general.assistant' => array( 'title' => __( 'Assistant', 'all-in-one-ai-chatbot' ) ),
			'general.handoff'   => array(
				'title' => __( 'Talk to a person', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'Shown in the widget and offered by the assistant when it cannot help.', 'all-in-one-ai-chatbot' ),
			),
			'ai.routing'        => array(
				'title' => __( 'Providers', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'The assistant uses your own account with an AI provider. You pay the provider directly for what the assistant uses. API keys are stored encrypted.', 'all-in-one-ai-chatbot' ),
			),
			'ai.openai'         => array( 'title' => 'OpenAI' ),
			'ai.claude'         => array( 'title' => 'Anthropic Claude' ),
			'ai.deepseek'       => array( 'title' => 'DeepSeek' ),
			'ai.gemini'         => array(
				'title' => 'Google Gemini',
				'desc'  => __( 'Fast and low-cost, with a free tier for testing. Get a key in Google AI Studio.', 'all-in-one-ai-chatbot' ),
			),
			'ai.openrouter'     => array(
				'title' => 'OpenRouter',
				'desc'  => __( 'One key for hundreds of models from many vendors. Enter the model as vendor/model, e.g. openai/gpt-5-mini, anthropic/claude-haiku-4.5 or deepseek/deepseek-chat. Costs are reported by OpenRouter itself.', 'all-in-one-ai-chatbot' ),
			),
			'ai.answers'        => array( 'title' => __( 'Answers', 'all-in-one-ai-chatbot' ) ),
			'knowledge.sources' => array( 'title' => __( 'Content the assistant reads', 'all-in-one-ai-chatbot' ) ),
			'knowledge.search'  => array( 'title' => __( 'Search', 'all-in-one-ai-chatbot' ) ),
			'widget.look'       => array( 'title' => __( 'Appearance', 'all-in-one-ai-chatbot' ) ),
			'widget.behaviour'  => array( 'title' => __( 'Behaviour', 'all-in-one-ai-chatbot' ) ),
			'leads.form'        => array(
				'title' => __( 'Lead capture', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'Collect visitors\' contact details in the chat. Leads appear under AI Chatbot → Leads and can trigger emails, Telegram messages and webhooks.', 'all-in-one-ai-chatbot' ),
			),
			'leads.text'        => array( 'title' => __( 'Form text', 'all-in-one-ai-chatbot' ) ),
			'leads.consent'     => array(
				'title' => __( 'Consent (GDPR)', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'Needed for visitors from the EU and UK. The exact text a visitor agreed to is stored with their lead.', 'all-in-one-ai-chatbot' ),
			),
			'notify.email'      => array( 'title' => __( 'Email', 'all-in-one-ai-chatbot' ) ),
			'notify.telegram'   => array(
				'title' => __( 'Telegram', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'Get alerts on your phone. Create a bot with @BotFather, paste its token, send your bot any message, then use "Find my chat ID".', 'all-in-one-ai-chatbot' ),
			),
			'integrations.webhooks' => array(
				'title' => __( 'Webhooks', 'all-in-one-ai-chatbot' ),
				'desc'  => __( 'Send events to Zapier, Make, n8n, Google Sheets, your CRM or any URL. Each request is JSON, signed with the secret below in the X-AICB-Signature header (HMAC-SHA256 of the body).', 'all-in-one-ai-chatbot' ),
			),
			'limits.abuse'      => array( 'title' => __( 'Usage limits', 'all-in-one-ai-chatbot' ) ),
			'limits.privacy'    => array( 'title' => __( 'Privacy and data', 'all-in-one-ai-chatbot' ) ),
		);

		/**
		 * Filter settings sections.
		 *
		 * @param array $sections "tab.section" => {title, desc}.
		 */
		return (array) apply_filters( 'softorio_ai_settings_sections', $sections );
	}

	/**
	 * Every field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		// Modules add fields through the filter while plugins load, so the
		// list is only final — and only cached — once init has run.
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$reasoning = array(
			'minimal' => 'minimal',
			'low'     => 'low',
			'medium'  => 'medium',
			''        => __( 'Model default', 'all-in-one-ai-chatbot' ),
		);

		$providers = array();
		foreach ( Settings::providers() as $id => $p ) {
			$providers[ $id ] = $p['label'];
		}

		$fields = array(
			// ── Assistant ──────────────────────────────────────────────────
			'enabled'              => array(
				'tab'     => 'general',
				'section' => 'assistant',
				'type'    => 'checkbox',
				'label'   => __( 'Status', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Show the chat widget on the website', 'all-in-one-ai-chatbot' ),
				'default' => true,
			),
			'assistant_name'       => array(
				'tab'     => 'general',
				'section' => 'assistant',
				'type'    => 'text',
				'label'   => __( 'Assistant name', 'all-in-one-ai-chatbot' ),
				'default' => __( 'Support Assistant', 'all-in-one-ai-chatbot' ),
				'max'     => 60,
			),
			'company_name'         => array(
				'tab'         => 'general',
				'section'     => 'assistant',
				'type'        => 'text',
				'label'       => __( 'Business name', 'all-in-one-ai-chatbot' ),
				'default'     => '',
				'max'         => 120,
				'placeholder' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'greeting'             => array(
				'tab'     => 'general',
				'section' => 'assistant',
				'type'    => 'textarea',
				'rows'    => 2,
				'label'   => __( 'Welcome message', 'all-in-one-ai-chatbot' ),
				'default' => __( 'Hi! 👋 How can I help you today?', 'all-in-one-ai-chatbot' ),
				'max'     => 500,
			),
			'instructions'         => array(
				'tab'         => 'general',
				'section'     => 'assistant',
				'type'        => 'textarea',
				'rows'        => 5,
				'label'       => __( 'Extra instructions', 'all-in-one-ai-chatbot' ),
				'desc'        => __( 'Tone, language and rules for the assistant. Put facts and answers in Knowledge Articles instead, so they can be searched.', 'all-in-one-ai-chatbot' ),
				'placeholder' => __( 'e.g. Always answer in Bangla unless the visitor writes in English. Never discuss competitors.', 'all-in-one-ai-chatbot' ),
				'default'     => '',
				'max'         => 4000,
			),
			'whatsapp'             => array(
				'tab'         => 'general',
				'section'     => 'handoff',
				'type'        => 'phone',
				'label'       => __( 'WhatsApp number', 'all-in-one-ai-chatbot' ),
				'placeholder' => '+8801XXXXXXXXX',
				'default'     => '',
			),
			'contact_email'        => array(
				'tab'     => 'general',
				'section' => 'handoff',
				'type'    => 'email',
				'label'   => __( 'Support email', 'all-in-one-ai-chatbot' ),
				'default' => '',
			),
			'contact_url'          => array(
				'tab'     => 'general',
				'section' => 'handoff',
				'type'    => 'url',
				'label'   => __( 'Contact page URL', 'all-in-one-ai-chatbot' ),
				'default' => '',
			),

			// ── AI provider ────────────────────────────────────────────────
			'provider'             => array(
				'tab'     => 'ai',
				'section' => 'routing',
				'type'    => 'select',
				'label'   => __( 'Main provider', 'all-in-one-ai-chatbot' ),
				'options' => $providers,
				'default' => 'openai',
			),
			'fallback_provider'    => array(
				'tab'     => 'ai',
				'section' => 'routing',
				'type'    => 'select',
				'label'   => __( 'Backup provider', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Used automatically if the main provider fails. Needs its own API key.', 'all-in-one-ai-chatbot' ),
				'options' => array( '' => __( 'None', 'all-in-one-ai-chatbot' ) ) + $providers,
				'default' => '',
			),
			'max_tokens'           => array(
				'tab'     => 'ai',
				'section' => 'answers',
				'type'    => 'number',
				'label'   => __( 'Max answer length (tokens)', 'all-in-one-ai-chatbot' ),
				'default' => 1024,
				'min'     => 128,
				'max'     => 8000,
			),
			'streaming'            => array(
				'tab'     => 'ai',
				'section' => 'answers',
				'type'    => 'checkbox',
				'label'   => __( 'Live typing', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Show answers word by word as the AI writes them (streaming). If your hosting blocks streaming, the widget automatically falls back to normal replies.', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'history_turns'        => array(
				'tab'     => 'ai',
				'section' => 'answers',
				'type'    => 'number',
				'label'   => __( 'Conversation memory (exchanges)', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'How many earlier questions and answers the AI sees. More helps follow-ups but costs more.', 'all-in-one-ai-chatbot' ),
				'default' => 6,
				'min'     => 0,
				'max'     => 20,
			),

			// ── Knowledge ──────────────────────────────────────────────────
			'post_types'           => array(
				'tab'     => 'knowledge',
				'section' => 'sources',
				'type'    => 'post_types',
				'label'   => __( 'Content types', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Knowledge Articles are always included. Only published, non-password-protected content is used. Hide a single page with the "AI Chatbot" box in the editor.', 'all-in-one-ai-chatbot' ),
				'default' => array( 'post', 'page', PostTypes::DOC ),
			),
			'results'              => array(
				'tab'     => 'knowledge',
				'section' => 'search',
				'type'    => 'number',
				'label'   => __( 'Passages per answer', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'More passages give the AI more to work with but cost more per answer. 4–6 suits most sites.', 'all-in-one-ai-chatbot' ),
				'default' => 5,
				'min'     => 1,
				'max'     => 10,
			),
			'semantic_search'      => array(
				'tab'     => 'knowledge',
				'section' => 'search',
				'type'    => 'checkbox',
				'label'   => __( 'Semantic search', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Match meaning, not only words — e.g. "money back" finds your refund policy. Uses OpenAI embeddings, so it needs an OpenAI API key even if another provider writes the answers.', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),

			// ── Widget ─────────────────────────────────────────────────────
			'color'                => array(
				'tab'     => 'widget',
				'section' => 'look',
				'type'    => 'color',
				'label'   => __( 'Colour', 'all-in-one-ai-chatbot' ),
				'default' => '#2563eb',
			),
			'position'             => array(
				'tab'     => 'widget',
				'section' => 'look',
				'type'    => 'radio',
				'label'   => __( 'Position', 'all-in-one-ai-chatbot' ),
				'options' => array(
					'right' => __( 'Bottom right', 'all-in-one-ai-chatbot' ),
					'left'  => __( 'Bottom left', 'all-in-one-ai-chatbot' ),
				),
				'default' => 'right',
			),
			'suggestions'          => array(
				'tab'         => 'widget',
				'section'     => 'behaviour',
				'type'        => 'textarea',
				'rows'        => 3,
				'label'       => __( 'Suggested questions', 'all-in-one-ai-chatbot' ),
				'desc'        => __( 'One per line, up to four. Shown as buttons before the first message.', 'all-in-one-ai-chatbot' ),
				'placeholder' => __( "What are your delivery charges?\nHow do I return an item?", 'all-in-one-ai-chatbot' ),
				'default'     => '',
				'max'         => 600,
			),
			'show_sources'         => array(
				'tab'     => 'widget',
				'section' => 'behaviour',
				'type'    => 'checkbox',
				'label'   => __( 'Related pages', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Show links to related pages under answers', 'all-in-one-ai-chatbot' ),
				'default' => true,
			),
			'feedback'             => array(
				'tab'     => 'widget',
				'section' => 'behaviour',
				'type'    => 'checkbox',
				'label'   => __( 'Answer feedback', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Show 👍 / 👎 buttons under answers. Ratings appear in conversations and on the overview.', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'hide_for_admins'      => array(
				'tab'     => 'widget',
				'section' => 'behaviour',
				'type'    => 'checkbox',
				'label'   => __( 'Administrators', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Hide the widget from administrators', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),

			// ── Limits and privacy ─────────────────────────────────────────
			'visitor_hourly_limit' => array(
				'tab'     => 'limits',
				'section' => 'abuse',
				'type'    => 'number',
				'label'   => __( 'Messages per visitor per hour', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Per network address. 0 = no limit (not recommended).', 'all-in-one-ai-chatbot' ),
				'default' => 30,
				'min'     => 0,
				'max'     => 1000,
			),
			'daily_message_cap'    => array(
				'tab'     => 'limits',
				'section' => 'abuse',
				'type'    => 'number',
				'label'   => __( 'Answers per day (whole site)', 'all-in-one-ai-chatbot' ),
				'desc'    => __( '0 = no limit.', 'all-in-one-ai-chatbot' ),
				'default' => 500,
				'min'     => 0,
				'max'     => 100000,
			),
			'daily_budget'         => array(
				'tab'     => 'limits',
				'section' => 'abuse',
				'type'    => 'float',
				'label'   => __( 'Daily budget (USD)', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'The assistant pauses for the rest of the day once estimated spend reaches this. Estimates only — your provider\'s bill is authoritative. 0 = no limit.', 'all-in-one-ai-chatbot' ),
				'default' => 2.0,
				'min'     => 0,
				'max'     => 1000,
			),
			'max_message_length'   => array(
				'tab'     => 'limits',
				'section' => 'abuse',
				'type'    => 'number',
				'label'   => __( 'Longest visitor message (characters)', 'all-in-one-ai-chatbot' ),
				'default' => 1000,
				'min'     => 100,
				'max'     => 4000,
			),
			'trust_cloudflare'     => array(
				'tab'     => 'limits',
				'section' => 'abuse',
				'type'    => 'checkbox',
				'label'   => __( 'Cloudflare', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'This site is behind Cloudflare. Only tick this if it is true: on a site not behind Cloudflare it would let anyone bypass the per-visitor limit.', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'retention_days'       => array(
				'tab'     => 'limits',
				'section' => 'privacy',
				'type'    => 'number',
				'label'   => __( 'Keep conversations for (days)', 'all-in-one-ai-chatbot' ),
				'desc'    => __( '0 = keep forever.', 'all-in-one-ai-chatbot' ),
				'default' => 90,
				'min'     => 0,
				'max'     => 3650,
			),
			'log_retention_days'   => array(
				'tab'     => 'limits',
				'section' => 'privacy',
				'type'    => 'number',
				'label'   => __( 'Keep activity log for (days)', 'all-in-one-ai-chatbot' ),
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
			),
			'delete_on_uninstall'  => array(
				'tab'     => 'limits',
				'section' => 'privacy',
				'type'    => 'checkbox',
				'label'   => __( 'Uninstall', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Delete all plugin data, including Knowledge Articles, conversations and leads, when the plugin is deleted', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
		);

		$visibility = array(
			'required' => __( 'Required', 'all-in-one-ai-chatbot' ),
			'optional' => __( 'Optional', 'all-in-one-ai-chatbot' ),
			'hidden'   => __( 'Hidden', 'all-in-one-ai-chatbot' ),
		);
		$events     = Support\Events::catalogue();

		$fields += array(
			// ── Leads ──────────────────────────────────────────────────────
			'leads_mode'           => array(
				'tab'     => 'leads',
				'section' => 'form',
				'type'    => 'select',
				'label'   => __( 'Ask for contact details', 'all-in-one-ai-chatbot' ),
				'options' => array(
					'off'      => __( 'Never', 'all-in-one-ai-chatbot' ),
					'fallback' => __( 'Only when the assistant cannot help or the visitor asks for a person', 'all-in-one-ai-chatbot' ),
					'optional' => __( 'Before the chat (visitor can skip)', 'all-in-one-ai-chatbot' ),
					'required' => __( 'Before the chat (required)', 'all-in-one-ai-chatbot' ),
				),
				'default' => 'off',
			),
			'lead_name'            => array(
				'tab'     => 'leads',
				'section' => 'form',
				'type'    => 'select',
				'label'   => __( 'Name field', 'all-in-one-ai-chatbot' ),
				'options' => $visibility,
				'default' => 'required',
			),
			'lead_email'           => array(
				'tab'     => 'leads',
				'section' => 'form',
				'type'    => 'select',
				'label'   => __( 'Email field', 'all-in-one-ai-chatbot' ),
				'options' => $visibility,
				'default' => 'required',
			),
			'lead_phone'           => array(
				'tab'     => 'leads',
				'section' => 'form',
				'type'    => 'select',
				'label'   => __( 'Phone field', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'At least one of email or phone is always required, so you can reach the visitor.', 'all-in-one-ai-chatbot' ),
				'options' => $visibility,
				'default' => 'optional',
			),
			'lead_title'           => array(
				'tab'     => 'leads',
				'section' => 'text',
				'type'    => 'text',
				'label'   => __( 'Form title', 'all-in-one-ai-chatbot' ),
				'default' => __( 'Leave your details', 'all-in-one-ai-chatbot' ),
				'max'     => 80,
			),
			'lead_intro'           => array(
				'tab'     => 'leads',
				'section' => 'text',
				'type'    => 'textarea',
				'rows'    => 2,
				'label'   => __( 'Form intro', 'all-in-one-ai-chatbot' ),
				'default' => __( 'We will get back to you as soon as possible.', 'all-in-one-ai-chatbot' ),
				'max'     => 300,
			),
			'lead_thanks'          => array(
				'tab'     => 'leads',
				'section' => 'text',
				'type'    => 'textarea',
				'rows'    => 2,
				'label'   => __( 'Thank-you message', 'all-in-one-ai-chatbot' ),
				'default' => __( 'Thanks! We have received your details and will contact you soon.', 'all-in-one-ai-chatbot' ),
				'max'     => 300,
			),
			'consent_required'     => array(
				'tab'     => 'leads',
				'section' => 'consent',
				'type'    => 'checkbox',
				'label'   => __( 'Consent checkbox', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Visitors must tick a consent box before sending their details', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'consent_text'         => array(
				'tab'     => 'leads',
				'section' => 'consent',
				'type'    => 'textarea',
				'rows'    => 2,
				'label'   => __( 'Consent text', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'A link to your privacy policy page is added automatically when one is set in Settings → Privacy.', 'all-in-one-ai-chatbot' ),
				'default' => __( 'I agree that my details are stored so the team can contact me.', 'all-in-one-ai-chatbot' ),
				'max'     => 500,
			),

			// ── Notifications ──────────────────────────────────────────────
			'notify_email'         => array(
				'tab'         => 'notify',
				'section'     => 'email',
				'type'        => 'email',
				'label'       => __( 'Send alerts to', 'all-in-one-ai-chatbot' ),
				'desc'        => __( 'Leave blank to use the site admin email.', 'all-in-one-ai-chatbot' ),
				'placeholder' => (string) get_option( 'admin_email' ),
				'default'     => '',
				'test'        => 'email',
			),
			'notify_email_events'  => array(
				'tab'     => 'notify',
				'section' => 'email',
				'type'    => 'multicheck',
				'label'   => __( 'Email me when', 'all-in-one-ai-chatbot' ),
				'options' => array_diff_key( $events, array( Support\Events::MESSAGE_ANSWERED => true, Support\Events::ANSWER_RATED => true ) ),
				'default' => array( Support\Events::LEAD_CREATED, Support\Events::HANDOFF_REQUESTED ),
			),
			'transcript_to_visitor' => array(
				'tab'     => 'notify',
				'section' => 'email',
				'type'    => 'checkbox',
				'label'   => __( 'Visitor transcript', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Email visitors a copy of their chat when it ends (only visitors who left an email address)', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'telegram_key'         => array(
				'tab'     => 'notify',
				'section' => 'telegram',
				'type'    => 'secret',
				'label'   => __( 'Bot token', 'all-in-one-ai-chatbot' ),
				'default' => '',
			),
			'telegram_chat_id'     => array(
				'tab'     => 'notify',
				'section' => 'telegram',
				'type'    => 'text',
				'label'   => __( 'Chat ID', 'all-in-one-ai-chatbot' ),
				'default' => '',
				'max'     => 40,
				'test'    => 'telegram',
			),
			'telegram_events'      => array(
				'tab'     => 'notify',
				'section' => 'telegram',
				'type'    => 'multicheck',
				'label'   => __( 'Message me when', 'all-in-one-ai-chatbot' ),
				'options' => array_diff_key( $events, array( Support\Events::MESSAGE_ANSWERED => true, Support\Events::ANSWER_RATED => true ) ),
				'default' => array( Support\Events::LEAD_CREATED, Support\Events::HANDOFF_REQUESTED ),
			),

			// ── Integrations ───────────────────────────────────────────────
			'webhook_urls'         => array(
				'tab'         => 'integrations',
				'section'     => 'webhooks',
				'type'        => 'urls',
				'rows'        => 3,
				'label'       => __( 'Webhook URLs', 'all-in-one-ai-chatbot' ),
				'desc'        => __( 'One per line, up to 5. Leave empty to switch webhooks off.', 'all-in-one-ai-chatbot' ),
				'placeholder' => 'https://hooks.zapier.com/hooks/catch/…',
				'default'     => '',
				'test'        => 'webhook',
			),
			'webhook_events'       => array(
				'tab'     => 'integrations',
				'section' => 'webhooks',
				'type'    => 'multicheck',
				'label'   => __( 'Send these events', 'all-in-one-ai-chatbot' ),
				'options' => $events,
				'default' => array( Support\Events::LEAD_CREATED ),
			),
			'webhook_secret'       => array(
				'tab'     => 'integrations',
				'section' => 'webhooks',
				'type'    => 'generated',
				'label'   => __( 'Signing secret', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Use it to check a request really came from this site. Tick "Generate a new secret" and save to replace it.', 'all-in-one-ai-chatbot' ),
				'default' => '',
			),
		);

		// One key/model block per provider.
		foreach ( Settings::providers() as $id => $p ) {
			$fields[ $id . '_key' ]   = array(
				'tab'      => 'ai',
				'section'  => $id,
				'type'     => 'secret',
				'label'    => __( 'API key', 'all-in-one-ai-chatbot' ),
				'provider' => $id,
				'key_url'  => $p['key_url'],
				'default'  => '',
			);
			$fields[ $id . '_model' ] = array(
				'tab'     => 'ai',
				'section' => $id,
				'type'    => 'model',
				'label'   => __( 'Model', 'all-in-one-ai-chatbot' ),
				'default' => $p['default_model'],
			);
		}

		$fields['gemini_reasoning'] = array(
			'tab'     => 'ai',
			'section' => 'gemini',
			'type'    => 'select',
			'label'   => __( 'Thinking', 'all-in-one-ai-chatbot' ),
			'desc'    => __( 'Gemini 2.5 models think before answering, which is billed as output. "low" keeps support answers fast and cheap; "none" switches it off where the model allows.', 'all-in-one-ai-chatbot' ),
			'options' => array(
				'low'    => 'low',
				'none'   => 'none',
				'medium' => 'medium',
				''       => __( 'Model default', 'all-in-one-ai-chatbot' ),
			),
			'default' => 'low',
		);

		$fields['openai_reasoning'] = array(
			'tab'     => 'ai',
			'section' => 'openai',
			'type'    => 'select',
			'label'   => __( 'Reasoning effort', 'all-in-one-ai-chatbot' ),
			'desc'    => __( 'For GPT-5 models only. "minimal" is fastest and cheapest, and is plenty for support answers.', 'all-in-one-ai-chatbot' ),
			'options' => $reasoning,
			'default' => 'minimal',
		);

		/**
		 * Filter the settings fields. Feature modules add their own here.
		 *
		 * @param array<string, array<string, mixed>> $fields key => definition.
		 */
		$fields = (array) apply_filters( 'softorio_ai_settings_fields', $fields );

		if ( did_action( 'init' ) ) {
			$cache = $fields;
		}

		return $fields;
	}

	/**
	 * Defaults for every field.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? null;
		}

		return $defaults;
	}

	/**
	 * Validate one submitted value by its field type.
	 *
	 * @param array<string, mixed> $field Definition.
	 * @param mixed                $value Submitted value (null when absent).
	 * @param mixed                $old   Currently saved value.
	 * @param array<string, mixed> $input Whole submission, for companion inputs.
	 * @param string               $key   Field key.
	 * @return mixed
	 */
	public static function sanitize_value( array $field, mixed $value, mixed $old, array $input, string $key ): mixed {
		$default = $field['default'] ?? null;
		$max     = isset( $field['max'] ) ? (int) $field['max'] : null;
		$scalar  = is_scalar( $value ) ? (string) $value : '';

		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value, $old, $input );
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $value );

			case 'number':
				$n = (int) $scalar;
				return min( $field['max'] ?? PHP_INT_MAX, max( $field['min'] ?? PHP_INT_MIN, $n ) );

			case 'float':
				$f = (float) $scalar;
				return round( min( (float) ( $field['max'] ?? PHP_FLOAT_MAX ), max( (float) ( $field['min'] ?? -PHP_FLOAT_MAX ), $f ) ), 2 );

			case 'select':
			case 'radio':
				return array_key_exists( $scalar, (array) $field['options'] ) ? $scalar : $default;

			case 'color':
				$color = sanitize_hex_color( $scalar );
				return $color ? $color : $default;

			case 'email':
				return sanitize_email( $scalar );

			case 'url':
				return esc_url_raw( trim( $scalar ) );

			case 'phone':
				return mb_substr( (string) preg_replace( '/[^0-9+\s\-]/', '', $scalar ), 0, 30 );

			case 'model':
				$model = (string) preg_replace( '/[^A-Za-z0-9._:\-\/]/', '', $scalar );
				return '' !== $model ? mb_substr( $model, 0, 100 ) : $default;

			case 'secret':
				if ( ! empty( $input[ $key . '_clear' ] ) ) {
					return '';
				}
				$plain = trim( sanitize_text_field( $scalar ) );
				// Secrets are never echoed back to the browser, so an empty
				// field means "unchanged", not "delete".
				return '' === $plain ? (string) $old : Support\Crypto::encrypt( $plain );

			case 'multicheck':
				$chosen = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return array_values( array_intersect( array_keys( (array) $field['options'] ), $chosen ) );

			case 'urls':
				$urls = array();
				foreach ( preg_split( '/\R/', $scalar ) ?: array() as $line ) {
					$line = trim( $line );

					// esc_url_raw() "repairs" junk into a URL (it would turn
					// "not a url" into http://not%20a%20url), so require a
					// real absolute http(s) URL first.
					if ( false === filter_var( $line, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $line, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
						continue;
					}

					$urls[] = esc_url_raw( $line, array( 'http', 'https' ) );
				}
				return implode( "\n", array_slice( array_values( array_unique( $urls ) ), 0, 5 ) );

			case 'generated':
				if ( ! empty( $input[ $key . '_regenerate' ] ) || '' === (string) $old ) {
					return wp_generate_password( 40, false );
				}
				return (string) $old;

			case 'post_types':
				$types = array_filter( array_map( 'sanitize_key', is_array( $value ) ? $value : array() ), 'post_type_exists' );
				return array_values( array_unique( array_merge( array_values( $types ), array( PostTypes::DOC ) ) ) );

			case 'textarea':
				$text = sanitize_textarea_field( $scalar );
				return null !== $max ? mb_substr( $text, 0, $max ) : $text;

			case 'text':
			default:
				$text = sanitize_text_field( $scalar );
				return null !== $max ? mb_substr( $text, 0, $max ) : $text;
		}
	}
}
