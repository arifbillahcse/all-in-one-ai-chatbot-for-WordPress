/**
 * Softorio AI Assistant — chat widget.
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
		suggestionsBox.hidden = hasUserMessage || ! suggestionsBox.childNodes.length;
	}

	// ── Network ─────────────────────────────────────────────────────────────────

	function send( text ) {
		text = String( text || '' ).trim();

		if ( ! text || busy ) {
			return;
		}

		addMessage( 'user', text, null, true );
		input.value = '';
		autoGrow();
		setBusy( true );

		fetch( config.restUrl + 'chat', {
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
		fetch( config.restUrl + 'history?conversation_id=' + encodeURIComponent( id ) + '&visitor_token=' + encodeURIComponent( visitorToken() ), {
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
		greet();
		updateSuggestions();
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
		host.id = 'softorio-ai-assistant';
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
