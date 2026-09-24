/**
 * All in One AI Chatbot — chat widget.
 *
 * Plain JavaScript, no dependencies, rendered inside a Shadow DOM so the
 * theme's CSS cannot break the widget and the widget's CSS cannot leak into
 * the theme.
 *
 * Model output is never inserted as HTML. Answers are shaped by site content
 * the visitor did not write, so treating them as markup would turn a prompt
 * injection into stored XSS. Every node is built with createElement and
 * textContent.
 */
( function () {
	'use strict';

	var config = window.softorioAiConfig;

	if ( ! config || ! config.restUrl || window.softorioAiLoaded ) {
		return;
	}

	window.softorioAiLoaded = true;

	var i18n = config.i18n || {};
	var KEY_VISITOR = 'softorioAi.visitor';
	var KEY_CONVERSATION = 'softorioAi.conversation';
	var KEY_LOG = 'softorioAi.log';
	var KEY_OPEN = 'softorioAi.open';
	var KEY_LEAD = 'softorioAi.lead';
	var KEY_SKIPPED = 'softorioAi.leadSkipped';
	var leadCfg = config.leads || null;
	var gate = null;
	var LOG_LIMIT = 60;

	var root, hostEl, launcher, panel, log, input, sendButton, suggestionsBox, contactBox;
	var busy = false;
	var messages = [];

	// ── Storage (every access guarded: private mode and blocked storage throw) ──

	function store( kind ) {
		try {
			var s = window[ kind ];
			var probe = '__softorio_ai__';
			s.setItem( probe, probe );
			s.removeItem( probe );
			return s;
		} catch ( e ) {
			return null;
		}
	}

	var local = store( 'localStorage' );
	var session = store( 'sessionStorage' );

	function read( s, key ) {
		try {
			return s ? s.getItem( key ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function write( s, key, value ) {
		try {
			if ( s ) {
				if ( value === null ) {
					s.removeItem( key );
				} else {
					s.setItem( key, value );
				}
			}
		} catch ( e ) {}
	}

	/**
	 * Endpoint URL with query parameters.
	 *
	 * The REST base is either pretty (/wp-json/…/) or, on sites without pretty
	 * permalinks, a query string (?rest_route=/…/) — so parameters must join
	 * with & when a ? is already there.
	 */
	function endpoint( path, params ) {
		var url = config.restUrl + path;
		var query = Object.keys( params || {} ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} ).join( '&' );
		return query ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query : url;
	}

	function randomToken() {
		var bytes = new Uint8Array( 16 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		return Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function visitorToken() {
		var token = read( local, KEY_VISITOR );
		if ( ! token || ! /^[A-Za-z0-9]{16,64}$/.test( token ) ) {
			token = randomToken();
			write( local, KEY_VISITOR, token );
		}
		return token;
	}

	function conversationId() {
		var id = read( local, KEY_CONVERSATION );
		return id && /^[a-f0-9]{32}$/.test( id ) ? id : '';
	}

	function saveLog() {
		write( local, KEY_LOG, JSON.stringify( messages.slice( -LOG_LIMIT ) ) );
	}

	function loadLog() {
		try {
			var parsed = JSON.parse( read( local, KEY_LOG ) || '[]' );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	// ── DOM helpers ─────────────────────────────────────────────────────────────

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function icon( paths ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		paths.forEach( function ( d ) {
			var p = document.createElementNS( ns, 'path' );
			p.setAttribute( 'd', d );
			svg.appendChild( p );
		} );
		return svg;
	}

	var ICON_CHAT = [ 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' ];
	var ICON_CLOSE = [ 'M18 6 6 18', 'M6 6l12 12' ];
	var ICON_SEND = [ 'M22 2 11 13', 'M22 2 15 22 11 13 2 9 22 2z' ];
	var ICON_NEW = [ 'M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z' ];

	function safeHref( url ) {
		return /^https?:\/\//i.test( url ) ? url : null;
	}

	function link( href, text ) {
		var a = el( 'a', null, text );
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener noreferrer nofollow';
		return a;
	}

	/** Inline formatting: **bold**, [text](url) and bare URLs, as real nodes. */
	function appendInline( parent, text ) {
		var pattern = /(\*\*[^*]+\*\*)|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<>"')\]]+)/g;
		var last = 0;
		var match;

		while ( ( match = pattern.exec( text ) ) !== null ) {
			if ( match.index > last ) {
				parent.appendChild( document.createTextNode( text.slice( last, match.index ) ) );
			}

			if ( match[ 1 ] ) {
				parent.appendChild( el( 'strong', null, match[ 1 ].slice( 2, -2 ) ) );
			} else if ( match[ 2 ] && safeHref( match[ 3 ] ) ) {
				parent.appendChild( link( match[ 3 ], match[ 2 ] ) );
			} else if ( match[ 4 ] ) {
				var url = match[ 4 ].replace( /[.,;:!?]+$/, '' );
				parent.appendChild( link( url, url ) );
				if ( url.length < match[ 4 ].length ) {
					parent.appendChild( document.createTextNode( match[ 4 ].slice( url.length ) ) );
				}
			} else {
				parent.appendChild( document.createTextNode( match[ 0 ] ) );
			}

			last = pattern.lastIndex;
		}

		if ( last < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	/** Paragraphs and "- " / "1. " lists. */
	function renderText( container, text ) {
		var list = null;
		var listType = '';

		String( text ).split( '\n' ).forEach( function ( line ) {
			var bullet = /^\s*[-*•]\s+(.*)$/.exec( line );
			var numbered = /^\s*\d+[.)]\s+(.*)$/.exec( line );
			var item = bullet || numbered;
			var type = bullet ? 'ul' : 'ol';

			if ( item ) {
				if ( ! list || listType !== type ) {
					list = el( type );
					listType = type;
					container.appendChild( list );
				}
				var li = el( 'li' );
				appendInline( li, item[ 1 ] );
				list.appendChild( li );
				return;
			}

			list = null;

			if ( line.trim() === '' ) {
				return;
			}

			var p = el( 'p' );
			appendInline( p, line.replace( /^#+\s+/, '' ) );
			container.appendChild( p );
		} );
	}

	// ── Messages ────────────────────────────────────────────────────────────────

	function scrollDown() {
		log.scrollTop = log.scrollHeight;
	}

	function renderMessage( role, text, sources ) {
		var row = el( 'div', 'sai-msg sai-' + ( role === 'user' ? 'user' : role === 'error' ? 'error' : 'bot' ) );
		var bubble = el( 'div', 'sai-bubble' );

		if ( role === 'user' ) {
			bubble.textContent = text;
		} else {
			renderText( bubble, text );
		}

		if ( sources && sources.length ) {
			var box = el( 'div', 'sai-sources' );
			box.appendChild( el( 'span', 'sai-sources-label', i18n.sources ) );
			sources.forEach( function ( source ) {
				var href = safeHref( source.url || '' );
				if ( href ) {
					box.appendChild( link( href, source.title || href ) );
				}
			} );
			bubble.appendChild( box );
		}

		row.appendChild( bubble );
		log.appendChild( row );
		scrollDown();
	}

	function addMessage( role, text, sources, persist ) {
		renderMessage( role, text, sources );
		if ( persist ) {
			messages.push( { role: role, content: text, sources: sources || [] } );
			saveLog();
		}
		updateSuggestions();
	}

	var typingRow = null;

	function showTyping( on ) {
		if ( on && ! typingRow ) {
			typingRow = el( 'div', 'sai-msg sai-bot' );
			var bubble = el( 'div', 'sai-bubble sai-typing' );
			bubble.setAttribute( 'aria-label', i18n.typing );
			bubble.appendChild( el( 'span' ) );
			bubble.appendChild( el( 'span' ) );
			bubble.appendChild( el( 'span' ) );
			typingRow.appendChild( bubble );
			log.appendChild( typingRow );
			scrollDown();
		} else if ( ! on && typingRow ) {
			typingRow.remove();
			typingRow = null;
		}
	}

	function setBusy( state ) {
		busy = state;
		sendButton.disabled = state;
		input.setAttribute( 'aria-busy', state ? 'true' : 'false' );
		showTyping( state );
	}

	function updateSuggestions() {
		var hasUserMessage = messages.some( function ( m ) {
			return m.role === 'user';
		} );
		suggestionsBox.hidden = hasUserMessage || ! suggestionsBox.childNodes.length || ( !! gate && leadCfg.mode === 'required' );
	}

	// ── Network ─────────────────────────────────────────────────────────────────

	function send( text ) {
		text = String( text || '' ).trim();

		if ( ! text || busy ) {
			return;
		}

		// The pre-chat form is waiting: "required" means it must be filled
		// in first; with "optional", chatting anyway counts as skipping it.
		if ( gate ) {
			if ( leadCfg.mode === 'required' ) {
				var first = gate.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
				return;
			}
			write( session, KEY_SKIPPED, '1' );
			gate.remove();
			releaseGate();
		}

		addMessage( 'user', text, null, true );
		input.value = '';
		autoGrow();
		setBusy( true );

		fetch( endpoint( 'chat' ), {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				message: text,
				conversation_id: conversationId(),
				visitor_token: visitorToken(),
				page_url: window.location.href.split( '#' )[ 0 ]
			} )
		} )
			.then( function ( response ) {
				return response.json().then(
					function ( body ) {
						return { ok: response.ok, body: body || {} };
					},
					function () {
						return { ok: false, body: {} };
					}
				);
			} )
			.then( function ( result ) {
				setBusy( false );

				if ( result.ok && result.body.reply ) {
					if ( result.body.conversation_id ) {
						write( local, KEY_CONVERSATION, result.body.conversation_id );
					}
					addMessage( 'bot', result.body.reply, result.body.sources, true );
					if ( result.body.offer_lead && leadCfg && ! leadDone() ) {
						showLeadForm( 'fallback' );
					}
					return;
				}

				addMessage( 'error', result.body.message || i18n.error, null, false );
				if ( result.body.code === 'daily_limit' || result.body.code === 'generation_failed' ) {
					contactBox.hidden = ! contactBox.childNodes.length;
				}
			} )
			.catch( function () {
				setBusy( false );
				addMessage( 'error', navigator.onLine === false ? i18n.offline : i18n.error, null, false );
			} )
			.then( function () {
				input.focus();
			} );
	}

	// ── Lead form ───────────────────────────────────────────────────────────────

	function leadDone() {
		return read( local, KEY_LEAD ) === '1';
	}

	function hasUserMessage() {
		return messages.some( function ( m ) {
			return m.role === 'user';
		} );
	}

	/** Lock or unlock the message box while the pre-chat form is required. */
	function lockInput( locked ) {
		input.disabled = locked;
		sendButton.disabled = locked;
		input.placeholder = locked ? i18n.formFirst : i18n.placeholder;
	}

	/** Show the pre-chat form if the owner wants details before chatting. */
	function maybeGate() {
		if ( ! leadCfg || ( leadCfg.mode !== 'optional' && leadCfg.mode !== 'required' ) ) {
			return;
		}
		if ( leadDone() || hasUserMessage() || gate || ( leadCfg.mode === 'optional' && read( session, KEY_SKIPPED ) === '1' ) ) {
			return;
		}
		gate = showLeadForm( 'pre_chat' );
		lockInput( true );
		updateSuggestions();
	}

	function releaseGate() {
		if ( gate ) {
			gate = null;
			lockInput( false );
			updateSuggestions();
		}
	}

	function formField( form, name, rule, type, autocomplete ) {
		var wrap = el( 'label', 'sai-field' );
		wrap.appendChild( el( 'span', 'sai-field-label', i18n[ name ] + ( rule === 'optional' ? ' (' + i18n.optional + ')' : '' ) ) );
		var field = el( type === 'textarea' ? 'textarea' : 'input', 'sai-field-input' );
		if ( type !== 'textarea' ) {
			field.type = type;
		} else {
			field.rows = 3;
		}
		field.name = name;
		field.required = rule === 'required';
		if ( autocomplete ) {
			field.autocomplete = autocomplete;
		}
		wrap.appendChild( field );
		wrap.appendChild( el( 'span', 'sai-field-error' ) );
		form.appendChild( wrap );
		return field;
	}

	/**
	 * Render the lead form as a card in the conversation.
	 *
	 * @param {string} source pre_chat | fallback | handoff
	 * @return {Element} the card row
	 */
	function showLeadForm( source ) {
		var row = el( 'div', 'sai-msg sai-bot sai-lead-row' );
		var form = el( 'form', 'sai-lead' );
		form.noValidate = true;

		form.appendChild( el( 'strong', 'sai-lead-title', leadCfg.title || i18n.leaveDetails ) );
		if ( leadCfg.intro ) {
			form.appendChild( el( 'p', 'sai-lead-intro', leadCfg.intro ) );
		}

		var fields = {};
		var rules = leadCfg.fields || {};

		if ( rules.name && rules.name !== 'hidden' ) {
			fields.name = formField( form, 'name', rules.name, 'text', 'name' );
		}
		if ( rules.email && rules.email !== 'hidden' ) {
			fields.email = formField( form, 'email', rules.email, 'email', 'email' );
		}
		if ( rules.phone && rules.phone !== 'hidden' ) {
			fields.phone = formField( form, 'phone', rules.phone, 'tel', 'tel' );
		}
		if ( source === 'handoff' ) {
			fields.message = formField( form, 'message', 'optional', 'textarea', '' );
		}

		var consent = null;
		if ( leadCfg.consent ) {
			var consentWrap = el( 'label', 'sai-consent' );
			consent = el( 'input' );
			consent.type = 'checkbox';
			consent.name = 'consent';
			consentWrap.appendChild( consent );
			consentWrap.appendChild( document.createTextNode( ' ' + leadCfg.consent + ' ' ) );
			if ( leadCfg.privacyUrl && safeHref( leadCfg.privacyUrl ) ) {
				consentWrap.appendChild( link( leadCfg.privacyUrl, i18n.privacy ) );
			}
			consentWrap.appendChild( el( 'span', 'sai-field-error' ) );
			form.appendChild( consentWrap );
		}

		var error = el( 'div', 'sai-lead-error' );
		error.setAttribute( 'role', 'alert' );
		form.appendChild( error );

		var actions = el( 'div', 'sai-lead-actions' );
		var submit = el( 'button', 'sai-lead-submit', i18n.submit );
		submit.type = 'submit';
		actions.appendChild( submit );

		if ( source === 'pre_chat' && leadCfg.mode === 'optional' ) {
			var skip = el( 'button', 'sai-lead-skip', i18n.skip );
			skip.type = 'button';
			skip.addEventListener( 'click', function () {
				write( session, KEY_SKIPPED, '1' );
				row.remove();
				releaseGate();
				input.focus();
			} );
			actions.appendChild( skip );
		}
		form.appendChild( actions );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submitLead( source, form, fields, consent, row, submit, error );
		} );

		row.appendChild( form );
		log.appendChild( row );
		scrollDown();

		var first = form.querySelector( 'input, textarea' );
		if ( first && ! panel.hidden ) {
			first.focus();
		}

		return row;
	}

	function submitLead( source, form, fields, consent, row, submit, error ) {
		form.querySelectorAll( '.sai-field-error' ).forEach( function ( node ) {
			node.textContent = '';
		} );
		error.textContent = '';
		submit.disabled = true;
		submit.textContent = i18n.sending;

		var body = {
			source: source,
			visitor_token: visitorToken(),
			conversation_id: conversationId(),
			page_url: window.location.href.split( '#' )[ 0 ],
			consent: consent && consent.checked ? 1 : 0
		};
		Object.keys( fields ).forEach( function ( key ) {
			body[ key ] = fields[ key ].value.trim();
		} );

		fetch( endpoint( 'lead' ), {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} )
			.then( function ( response ) {
				return response.json().then(
					function ( data ) {
						return { ok: response.ok, body: data || {} };
					},
					function () {
						return { ok: false, body: {} };
					}
				);
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					write( local, KEY_LEAD, '1' );
					row.remove();
					addMessage( 'bot', result.body.message || leadCfg.thanks, null, true );
					releaseGate();
					input.focus();
					return;
				}

				submit.disabled = false;
				submit.textContent = i18n.submit;

				var fieldErrors = result.body.fields || {};
				var shown = false;
				Object.keys( fieldErrors ).forEach( function ( key ) {
					var target = key === 'consent' ? consent : fields[ key ];
					if ( target ) {
						target.closest( 'label' ).querySelector( '.sai-field-error' ).textContent = fieldErrors[ key ];
						shown = true;
					}
				} );
				if ( ! shown ) {
					error.textContent = result.body.message || i18n.error;
				}
			} )
			.catch( function () {
				submit.disabled = false;
				submit.textContent = i18n.submit;
				error.textContent = navigator.onLine === false ? i18n.offline : i18n.error;
			} );
	}

	function restore() {
		messages = loadLog();

		if ( messages.length ) {
			messages.forEach( function ( m ) {
				renderMessage( m.role, m.content, m.sources );
			} );
			updateSuggestions();
			return;
		}

		var id = conversationId();

		if ( ! id ) {
			return;
		}

		// The local log is gone (cleared storage, another tab wrote over it)
		// but the conversation still exists server-side.
		fetch( endpoint( 'history', { conversation_id: id, visitor_token: visitorToken() } ), {
			credentials: 'omit'
		} )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( body ) {
				if ( ! body || ! Array.isArray( body.messages ) ) {
					write( local, KEY_CONVERSATION, null );
					return;
				}
				body.messages.forEach( function ( m ) {
					var role = m.role === 'user' ? 'user' : 'bot';
					messages.push( { role: role, content: m.content, sources: m.sources || [] } );
					renderMessage( role, m.content, m.sources );
				} );
				saveLog();
				updateSuggestions();

				// A returning visitor with an existing chat is not asked again.
				if ( gate && hasUserMessage() ) {
					gate.remove();
					releaseGate();
				}
			} )
			.catch( function () {} );
	}

	function newChat() {
		write( local, KEY_CONVERSATION, null );
		write( local, KEY_LOG, null );
		messages = [];
		while ( log.firstChild ) {
			log.removeChild( log.firstChild );
		}
		gate = null;
		lockInput( false );
		greet();
		updateSuggestions();
		maybeGate();
		input.focus();
	}

	function greet() {
		if ( config.greeting ) {
			renderMessage( 'bot', config.greeting, null );
		}
	}

	// ── Open / close ────────────────────────────────────────────────────────────

	function open() {
		panel.hidden = false;
		launcher.setAttribute( 'aria-expanded', 'true' );
		launcher.setAttribute( 'aria-label', i18n.close );
		hostEl.classList.add( 'sai-is-open' );
		write( session, KEY_OPEN, '1' );
		scrollDown();
		input.focus();
	}

	function close() {
		panel.hidden = true;
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-label', i18n.open );
		hostEl.classList.remove( 'sai-is-open' );
		write( session, KEY_OPEN, null );
		launcher.focus();
	}

	function autoGrow() {
		input.style.height = 'auto';
		input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
	}

	// ── Build ───────────────────────────────────────────────────────────────────

	function build() {
		var host = hostEl = el( 'div' );
		host.id = 'all-in-one-ai-chatbot';
		host.style.setProperty( '--sai-color', config.color || '#2563eb' );
		document.body.appendChild( host );

		root = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : host;

		var css = el( 'link' );
		css.rel = 'stylesheet';
		css.href = config.cssUrl;
		root.appendChild( css );

		var wrap = el( 'div', 'sai-wrap sai-' + ( config.position === 'left' ? 'left' : 'right' ) );
		root.appendChild( wrap );

		// Panel.
		panel = el( 'section', 'sai-panel' );
		panel.hidden = true;
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', config.title || 'Chat' );

		var header = el( 'header', 'sai-header' );
		var heading = el( 'div', 'sai-heading' );
		heading.appendChild( el( 'strong', 'sai-title', config.title || '' ) );
		if ( config.subtitle ) {
			heading.appendChild( el( 'span', 'sai-subtitle', config.subtitle ) );
		}
		header.appendChild( heading );

		var newButton = el( 'button', 'sai-icon-btn' );
		newButton.type = 'button';
		newButton.title = i18n.newChat;
		newButton.setAttribute( 'aria-label', i18n.newChat );
		newButton.appendChild( icon( ICON_NEW ) );
		newButton.addEventListener( 'click', newChat );
		header.appendChild( newButton );

		var closeButton = el( 'button', 'sai-icon-btn' );
		closeButton.type = 'button';
		closeButton.setAttribute( 'aria-label', i18n.close );
		closeButton.appendChild( icon( ICON_CLOSE ) );
		closeButton.addEventListener( 'click', close );
		header.appendChild( closeButton );

		panel.appendChild( header );

		log = el( 'div', 'sai-log' );
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );
		panel.appendChild( log );

		suggestionsBox = el( 'div', 'sai-suggestions' );
		( config.suggestions || [] ).forEach( function ( text ) {
			var chip = el( 'button', 'sai-chip', text );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				send( text );
			} );
			suggestionsBox.appendChild( chip );
		} );
		panel.appendChild( suggestionsBox );

		contactBox = el( 'div', 'sai-contact' );
		var contact = config.contact || {};
		[
			[ contact.whatsapp, i18n.whatsapp ],
			[ contact.email, i18n.email ],
			[ contact.url, i18n.contactPage ]
		].forEach( function ( item ) {
			if ( item[ 0 ] && ( /^https?:\/\//i.test( item[ 0 ] ) || /^mailto:/i.test( item[ 0 ] ) ) ) {
				var a = el( 'a', 'sai-contact-link', item[ 1 ] );
				a.href = item[ 0 ];
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				contactBox.appendChild( a );
			}
		} );

		if ( leadCfg ) {
			var leave = el( 'button', 'sai-contact-link sai-leave', i18n.leaveDetails );
			leave.type = 'button';
			leave.addEventListener( 'click', function () {
				contactBox.hidden = true;
				showLeadForm( 'handoff' );
			} );
			contactBox.appendChild( leave );
		}

		if ( contactBox.childNodes.length ) {
			var human = el( 'button', 'sai-human', i18n.human );
			human.type = 'button';
			human.addEventListener( 'click', function () {
				contactBox.hidden = ! contactBox.hidden;
			} );
			panel.appendChild( human );
		}
		contactBox.hidden = true;
		panel.appendChild( contactBox );

		var form = el( 'form', 'sai-form' );
		input = el( 'textarea', 'sai-input' );
		input.rows = 1;
		input.maxLength = config.maxLength || 1000;
		input.placeholder = i18n.placeholder;
		input.setAttribute( 'aria-label', i18n.placeholder );
		input.addEventListener( 'input', autoGrow );
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				send( input.value );
			}
		} );
		form.appendChild( input );

		sendButton = el( 'button', 'sai-send' );
		sendButton.type = 'submit';
		sendButton.setAttribute( 'aria-label', i18n.send );
		sendButton.appendChild( icon( ICON_SEND ) );
		form.appendChild( sendButton );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			send( input.value );
		} );
		panel.appendChild( form );

		panel.appendChild( el( 'div', 'sai-disclaimer', i18n.disclaimer ) );

		panel.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				close();
			}
		} );

		wrap.appendChild( panel );

		// Launcher.
		launcher = el( 'button', 'sai-launcher' );
		launcher.type = 'button';
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-label', i18n.open );
		launcher.appendChild( icon( ICON_CHAT ) );
		launcher.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				open();
			} else {
				close();
			}
		} );
		wrap.appendChild( launcher );

		greet();
		restore();
		maybeGate();

		if ( read( session, KEY_OPEN ) === '1' ) {
			open();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
} )();
