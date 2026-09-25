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

	var KEY_POPUP = 'softorioAi.popup';
	var KEY_LIVE = 'softorioAi.live';
	var live = { mode: 'ai', after: 0, agent: null, timer: null, available: null, checkedAt: 0, unread: 0, typingSent: 0 };
	var liveBar = null;
	var liveButton = null;
	var liveBadge = null;
	var inline = false;
	var quiet = false;
	var popupEl = null;
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

	/**
	 * Call the plugin's REST API.
	 *
	 * Logged-in visitors send their cookie with a REST nonce, so the server
	 * knows who they are (their orders, their name). If the nonce has expired
	 * — a tab left open for a day — the call is retried anonymously rather
	 * than failing.
	 */
	function api( path, options, params ) {
		options = options || {};
		var headers = { 'Content-Type': 'application/json' };
		var signedIn = !! config.nonce && ! options.anonymous;

		if ( signedIn ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		return fetch( endpoint( path, params ), {
			method: options.method || 'GET',
			credentials: signedIn ? 'same-origin' : 'omit',
			headers: headers,
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().then(
				function ( body ) {
					return { ok: response.ok, status: response.status, body: body || {} };
				},
				function () {
					return { ok: false, status: response.status, body: {} };
				}
			);
		} ).then( function ( result ) {
			if ( signedIn && result.status === 403 && result.body.code === 'rest_cookie_invalid_nonce' ) {
				config.nonce = '';
				return api( path, options, params );
			}
			return result;
		} );
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
	var LAUNCHER_ICONS = {
		chat: [ 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' ],
		help: [ 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3', 'M12 17h.01' ],
		sparkle: [ 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z', 'M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8z' ]
	};
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

	function renderMessage( role, text, sources, cards, meta ) {
		if ( role === 'system' ) {
			log.appendChild( el( 'div', 'sai-system', text ) );
			scrollDown();
			return;
		}

		var row = el( 'div', 'sai-msg sai-' + ( role === 'user' ? 'user' : role === 'error' ? 'error' : 'bot' ) + ( role === 'agent' ? ' sai-agent' : '' ) );
		var bubble = el( 'div', 'sai-bubble' );

		if ( role === 'agent' && meta && meta.agent && meta.agent.name ) {
			var who = el( 'div', 'sai-agent-name' );
			if ( meta.agent.avatar && safeHref( meta.agent.avatar ) ) {
				var face = el( 'img', 'sai-agent-avatar' );
				face.src = meta.agent.avatar;
				face.alt = '';
				face.onerror = function () {
					face.remove();
				};
				who.appendChild( face );
			}
			who.appendChild( document.createTextNode( meta.agent.name ) );
			row.appendChild( who );
		}

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

		if ( role === 'bot' && config.feedback && meta && meta.id ) {
			row.appendChild( feedbackButtons( meta ) );
		}

		if ( cards && cards.length ) {
			renderCards( cards );
		}

		scrollDown();
	}

	function addMessage( role, text, sources, persist, cards, meta ) {
		var entry = { role: role, content: text, sources: sources || [], cards: cards || [], id: meta && meta.id ? meta.id : 0, rating: 0 };
		if ( meta && meta.agent ) {
			entry.agent = meta.agent;
		}
		renderMessage( role, text, sources, cards, entry );
		if ( persist ) {
			messages.push( entry );
			saveLog();
		}
		updateSuggestions();
	}

	/** 👍 / 👎 under an answer; clicking the active one again clears it. */
	function feedbackButtons( entry ) {
		var box = el( 'div', 'sai-feedback' );
		var buttons = {};

		[ [ 1, '👍', i18n.helpful ], [ -1, '👎', i18n.notHelpful ] ].forEach( function ( item ) {
			var button = el( 'button', 'sai-rate', item[ 1 ] );
			button.type = 'button';
			button.title = item[ 2 ];
			button.setAttribute( 'aria-label', item[ 2 ] );
			button.setAttribute( 'aria-pressed', entry.rating === item[ 0 ] ? 'true' : 'false' );
			button.addEventListener( 'click', function () {
				var rating = entry.rating === item[ 0 ] ? 0 : item[ 0 ];
				entry.rating = rating;
				Object.keys( buttons ).forEach( function ( key ) {
					buttons[ key ].setAttribute( 'aria-pressed', String( Number( key ) === rating ) );
				} );
				saveLog();
				api( 'feedback', {
					method: 'POST',
					body: { conversation_id: conversationId(), visitor_token: visitorToken(), message_id: entry.id, rating: rating }
				} ).catch( function () {} );
			} );
			buttons[ item[ 0 ] ] = button;
			box.appendChild( button );
		} );

		return box;
	}

	// ── Cards (products, orders) ────────────────────────────────────────────────

	function renderCards( cards ) {
		var strip = el( 'div', 'sai-cards' );

		cards.forEach( function ( card ) {
			if ( card.type === 'product' ) {
				strip.appendChild( productCard( card ) );
			} else if ( card.type === 'order' ) {
				strip.appendChild( orderCard( card ) );
			}
		} );

		if ( strip.childNodes.length ) {
			log.appendChild( strip );
		}
	}

	function productCard( card ) {
		var box = el( 'div', 'sai-card sai-product' );
		var href = safeHref( card.url || '' );

		if ( card.image && safeHref( card.image ) ) {
			var img = el( 'img', 'sai-card-img' );
			img.src = card.image;
			img.alt = card.name || '';
			img.loading = 'lazy';
			box.appendChild( img );
		}

		var body = el( 'div', 'sai-card-body' );
		body.appendChild( el( 'div', 'sai-card-title', card.name ) );
		if ( card.price ) {
			var price = el( 'div', 'sai-card-price', card.price );
			if ( card.was ) {
				price.appendChild( document.createTextNode( ' ' ) );
				price.appendChild( el( 's', 'sai-card-was', card.was ) );
			}
			body.appendChild( price );
		}
		if ( card.stock ) {
			body.appendChild( el( 'div', 'sai-card-stock' + ( card.in_stock ? '' : ' is-out' ), card.stock ) );
		}

		var actions = el( 'div', 'sai-card-actions' );
		if ( href ) {
			var view = link( href, i18n.view );
			view.className = 'sai-card-btn sai-card-btn-ghost';
			view.target = '_self';
			actions.appendChild( view );
		}
		if ( card.cart ) {
			actions.appendChild( cartButton( card ) );
		}
		body.appendChild( actions );
		box.appendChild( body );

		return box;
	}

	function cartButton( card ) {
		if ( card.cart.mode === 'link' || ! config.woo ) {
			var go = link( safeHref( card.cart.url || card.url || '' ) || '#', card.cart.label );
			go.className = 'sai-card-btn';
			go.target = '_self';
			return go;
		}

		var button = el( 'button', 'sai-card-btn', card.cart.label );
		button.type = 'button';
		button.addEventListener( 'click', function () {
			addToCart( card, button );
		} );
		return button;
	}

	/**
	 * Add to the real WooCommerce cart through its own AJAX endpoint, so the
	 * shopper stays in the chat. Themes listening for "added_to_cart" update
	 * their mini-cart as if the shop's own button had been used.
	 */
	function addToCart( card, button ) {
		button.disabled = true;
		button.textContent = i18n.adding;

		var form = new FormData();
		form.append( 'product_id', card.id );
		form.append( 'quantity', 1 );

		fetch( config.woo.addToCart, { method: 'POST', credentials: 'same-origin', body: form } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( ! data || data.error ) {
					// WooCommerce refused (options needed, sold out): let the
					// product page explain.
					window.location.href = ( data && data.product_url ) || card.url;
					return;
				}

				button.textContent = i18n.added;
				var cart = link( config.woo.cartUrl, i18n.viewCart );
				cart.className = 'sai-card-btn sai-card-btn-ghost';
				cart.target = '_self';
				button.parentNode.appendChild( cart );

				if ( window.jQuery ) {
					window.jQuery( document.body ).trigger( 'added_to_cart', [ data.fragments, data.cart_hash ] );
				}
			} )
			.catch( function () {
				window.location.href = card.url;
			} );
	}

	function orderCard( card ) {
		var box = el( 'div', 'sai-card sai-order' );
		var head = el( 'div', 'sai-order-head' );
		head.appendChild( el( 'strong', null, i18n.order + ' #' + card.number ) );
		head.appendChild( el( 'span', 'sai-order-status sai-status-' + String( card.status_key || '' ).replace( /[^a-z-]/g, '' ), card.status ) );
		box.appendChild( head );

		var meta = [ card.date, card.total, card.items ? card.items + ' ' + i18n.itemsCount : '' ].filter( Boolean ).join( ' · ' );
		box.appendChild( el( 'div', 'sai-order-meta', meta ) );

		var actions = el( 'div', 'sai-card-actions' );
		if ( card.tracking_url && safeHref( card.tracking_url ) ) {
			var track = link( card.tracking_url, i18n.track );
			track.className = 'sai-card-btn';
			actions.appendChild( track );
		}
		if ( card.url && safeHref( card.url ) ) {
			var view = link( card.url, i18n.viewOrder );
			view.className = 'sai-card-btn sai-card-btn-ghost';
			view.target = '_self';
			actions.appendChild( view );
		}
		if ( actions.childNodes.length ) {
			box.appendChild( actions );
		}

		return box;
	}

	var typingRow = null;

	function showTyping( on, status ) {
		if ( on && typingRow && status ) {
			var label = typingRow.querySelector( '.sai-typing-status' );
			if ( label ) {
				label.textContent = status;
			}
		}
		if ( on && ! typingRow ) {
			typingRow = el( 'div', 'sai-msg sai-bot' );
			var bubble = el( 'div', 'sai-bubble sai-typing' );
			bubble.setAttribute( 'aria-label', status || i18n.typing );
			bubble.appendChild( el( 'span', 'sai-dot' ) );
			bubble.appendChild( el( 'span', 'sai-dot' ) );
			bubble.appendChild( el( 'span', 'sai-dot' ) );
			bubble.appendChild( el( 'em', 'sai-typing-status', status || '' ) );
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

	function send( text, shown ) {
		text = String( text || '' ).trim();

		if ( ! text || busy ) {
			return;
		}

		if ( ! passGate() ) {
			return;
		}

		addMessage( 'user', shown || text, null, true );
		input.value = '';
		autoGrow();
		setBusy( true );

		var payload = {
			message: text,
			conversation_id: conversationId(),
			visitor_token: visitorToken(),
			page_url: window.location.href.split( '#' )[ 0 ]
		};

		// A person is answering: nothing to stream.
		if ( config.streaming && live.mode === 'ai' && window.ReadableStream && window.TextDecoder ) {
			sendStreaming( payload, function () {
				sendPlain( payload );
			} );
		} else {
			sendPlain( payload );
		}
	}

	/**
	 * The pre-chat form is waiting: "required" means it must be filled in
	 * first; with "optional", chatting anyway counts as skipping it.
	 *
	 * @return {boolean} whether the visitor may go ahead
	 */
	function passGate() {
		if ( ! gate ) {
			return true;
		}
		if ( leadCfg.mode === 'required' ) {
			var first = gate.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
			return false;
		}
		write( session, KEY_SKIPPED, '1' );
		gate.remove();
		releaseGate();
		return true;
	}

	// ── Quick replies (no AI, no cost) ──────────────────────────────────────────

	function flowChips( nodes, container, onPick ) {
		nodes.forEach( function ( node ) {
			var chip = el( 'button', 'sai-chip', node.label );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				onPick( node );
			} );
			container.appendChild( chip );
		} );
	}

	/** A sub-menu inside the conversation, with a way back to the top. */
	function showFlowMenu( nodes ) {
		var row = el( 'div', 'sai-flow-menu' );
		flowChips( nodes, row, function ( node ) {
			row.remove();
			runFlow( node );
		} );
		var back = el( 'button', 'sai-chip sai-chip-muted', i18n.mainMenu );
		back.type = 'button';
		back.addEventListener( 'click', function () {
			row.remove();
			showFlowMenu( config.flows );
		} );
		if ( nodes !== config.flows ) {
			row.appendChild( back );
		}
		log.appendChild( row );
		scrollDown();
	}

	function runFlow( node ) {
		if ( busy || ! passGate() ) {
			return;
		}

		if ( node.action === 'ask_ai' ) {
			send( node.prompt || node.label, node.label );
			return;
		}

		addMessage( 'user', node.label, null, true );
		if ( node.reply ) {
			addMessage( 'bot', node.reply, null, true );
		}

		if ( node.action === 'reply' && node.children && node.children.length ) {
			showFlowMenu( node.children );
		} else if ( node.action === 'link' && safeHref( node.url ) ) {
			var sameSite = node.url.indexOf( window.location.origin ) === 0;
			if ( sameSite ) {
				window.location.href = node.url;
			} else {
				window.open( node.url, '_blank', 'noopener' );
			}
		} else if ( node.action === 'lead' && leadCfg ) {
			showLeadForm( 'handoff' );
		} else if ( node.action === 'human' || node.action === 'lead' ) {
			contactBox.hidden = ! contactBox.childNodes.length;
			refreshLiveButton();
			scrollDown();
		}
	}

	// ── Business hours (decided in the browser: pages are cached) ───────────────

	function hoursState() {
		var h = config.hours;
		if ( ! h || ! h.days ) {
			return null;
		}

		var now = new Date();
		var minutes = now.getUTCHours() * 60 + now.getUTCMinutes() + ( h.offset || 0 );
		var day = ( now.getUTCDay() + Math.floor( minutes / 1440 ) + 7 ) % 7;
		minutes = ( ( minutes % 1440 ) + 1440 ) % 1440;

		var open = ( h.days[ day ] || [] ).some( function ( r ) {
			return minutes >= r[ 0 ] && minutes < r[ 1 ];
		} );

		var back = '';
		if ( ! open ) {
			search:
			for ( var i = 0; i <= 7; i++ ) {
				var d = ( day + i ) % 7;
				var ranges = h.days[ d ] || [];
				for ( var j = 0; j < ranges.length; j++ ) {
					if ( i > 0 || ranges[ j ][ 0 ] > minutes ) {
						var at = ( '0' + Math.floor( ranges[ j ][ 0 ] / 60 ) ).slice( -2 ) + ':' + ( '0' + ( ranges[ j ][ 0 ] % 60 ) ).slice( -2 );
						back = ( i === 0 ? '' : ( h.dayNames || [] )[ d ] + ' ' ) + at;
						break search;
					}
				}
			}
		}

		return { mode: h.mode, open: open, back: back, message: h.message };
	}

	// ── Voice input (browser speech recognition) ────────────────────────────────

	function voiceButton() {
		var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if ( ! config.voice || ! Recognition ) {
			return null;
		}

		var button = el( 'button', 'sai-mic' );
		button.type = 'button';
		button.setAttribute( 'aria-label', i18n.speak );
		button.title = i18n.speak;
		button.appendChild( icon( [ 'M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z', 'M19 10v2a7 7 0 0 1-14 0v-2', 'M12 19v3' ] ) );

		var active = null;

		button.addEventListener( 'click', function () {
			if ( active ) {
				active.stop();
				return;
			}

			var recognition = new Recognition();
			recognition.lang = config.voice.lang || navigator.language || 'en-US';
			recognition.interimResults = true;
			recognition.maxAlternatives = 1;

			var finalText = '';
			active = recognition;
			button.classList.add( 'is-listening' );
			input.placeholder = i18n.listening;

			recognition.onresult = function ( event ) {
				var interim = '';
				for ( var i = event.resultIndex; i < event.results.length; i++ ) {
					if ( event.results[ i ].isFinal ) {
						finalText += event.results[ i ][ 0 ].transcript;
					} else {
						interim += event.results[ i ][ 0 ].transcript;
					}
				}
				input.value = ( finalText + interim ).trim();
				autoGrow();
			};
			recognition.onend = function () {
				// Browsers fire "end" after "error" too; finish only once.
				if ( active !== recognition ) {
					return;
				}
				active = null;
				button.classList.remove( 'is-listening' );
				input.placeholder = i18n.placeholder;
				if ( finalText.trim() ) {
					send( finalText );
				}
			};
			recognition.onerror = function () {
				recognition.onend();
			};
			recognition.start();
		} );

		return button;
	}

	/** The regular request: one JSON answer when it is complete. */
	function sendPlain( payload ) {
		api( 'chat', { method: 'POST', body: payload } )
			.then( onResult )
			.catch( onFailure );
	}

	function onResult( result ) {
		setBusy( false );

		if ( result.ok && result.body.live ) {
			// Stored for the team; their reply arrives through polling.
			if ( result.body.conversation_id ) {
				write( local, KEY_CONVERSATION, result.body.conversation_id );
			}
			if ( result.body.mode && result.body.mode !== live.mode ) {
				setLiveMode( result.body.mode, live.agent );
			}
			liveSchedule();
			input.focus();
			return;
		}

		if ( result.ok && result.body.mode === 'ai' && live.mode !== 'ai' ) {
			setLiveMode( 'ai', null );
		}

		if ( result.ok && result.body.reply && result.body.unanswered && config.live ) {
			liveCheck().then( function ( available ) {
				if ( available ) {
					offerLive();
				}
			} );
		}

		if ( result.ok && result.body.reply ) {
			if ( result.body.conversation_id ) {
				write( local, KEY_CONVERSATION, result.body.conversation_id );
			}
			addMessage( 'bot', result.body.reply, result.body.sources, true, result.body.cards, { id: result.body.message_id } );
			if ( result.body.offer_lead && leadCfg && ! leadDone() ) {
				showLeadForm( 'fallback' );
			}
		} else {
			addMessage( 'error', result.body.message || i18n.error, null, false );
			if ( result.body.code === 'daily_limit' || result.body.code === 'generation_failed' ) {
				contactBox.hidden = ! contactBox.childNodes.length;
			}
		}

		input.focus();
	}

	function onFailure() {
		setBusy( false );
		addMessage( 'error', navigator.onLine === false ? i18n.offline : i18n.error, null, false );
		input.focus();
	}

	/**
	 * Streamed request: words appear as the AI writes them.
	 *
	 * Anything that stops the stream from starting — a host or security
	 * plugin blocking the endpoint, a proxy that turns it into a normal
	 * response, an old browser — hands over to the regular request, so the
	 * visitor always gets an answer.
	 */
	function sendStreaming( payload, fallback ) {
		var headers = { 'Content-Type': 'application/json', Accept: 'text/event-stream' };
		var signedIn = !! config.nonce;
		if ( signedIn ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		var row = null;
		var bubble = null;
		var text = '';
		var started = false;
		var finished = false;
		var pending = false;

		function draw() {
			pending = false;
			if ( ! bubble ) {
				return;
			}
			while ( bubble.firstChild ) {
				bubble.removeChild( bubble.firstChild );
			}
			renderText( bubble, text );
			scrollDown();
		}

		function clear() {
			if ( row ) {
				row.remove();
			}
			row = null;
			bubble = null;
			text = '';
		}

		function handle( event, data ) {
			if ( event === 'delta' ) {
				if ( ! bubble ) {
					showTyping( false );
					row = el( 'div', 'sai-msg sai-bot sai-streaming' );
					bubble = el( 'div', 'sai-bubble' );
					row.appendChild( bubble );
					log.appendChild( row );
				}
				text += data.t || '';
				if ( ! pending ) {
					pending = true;
					( window.requestAnimationFrame || setTimeout )( draw );
				}
			} else if ( event === 'tool' ) {
				clear();
				showTyping( true, i18n.checking );
			} else if ( event === 'reset' ) {
				clear();
				showTyping( true, i18n.checking );
			} else if ( event === 'done' || event === 'error' ) {
				finished = true;
				clear();
				onResult( { ok: event === 'done', body: data } );
			}
		}

		fetch( endpoint( 'chat/stream' ), {
			method: 'POST',
			credentials: signedIn ? 'same-origin' : 'omit',
			headers: headers,
			body: JSON.stringify( payload )
		} )
			.then( function ( response ) {
				var type = response.headers.get( 'Content-Type' ) || '';

				if ( ! response.ok || type.indexOf( 'text/event-stream' ) === -1 || ! response.body ) {
					fallback();
					return;
				}

				started = true;
				var reader = response.body.getReader();
				var decoder = new TextDecoder();
				var buffer = '';

				function pump() {
					return reader.read().then( function ( chunk ) {
						if ( chunk.done ) {
							if ( ! finished ) {
								clear();
								onFailure();
							}
							return;
						}

						buffer += decoder.decode( chunk.value, { stream: true } ).replace( /\r\n/g, '\n' );

						var boundary;
						while ( ( boundary = buffer.indexOf( '\n\n' ) ) !== -1 ) {
							var block = buffer.slice( 0, boundary );
							buffer = buffer.slice( boundary + 2 );

							var event = 'message';
							var data = [];
							block.split( '\n' ).forEach( function ( line ) {
								if ( line.indexOf( 'event:' ) === 0 ) {
									event = line.slice( 6 ).trim();
								} else if ( line.indexOf( 'data:' ) === 0 ) {
									data.push( line.slice( 5 ).replace( /^ /, '' ) );
								}
							} );

							if ( data.length ) {
								try {
									handle( event, JSON.parse( data.join( '\n' ) ) );
								} catch ( e ) {}
							}
						}

						return pump();
					} );
				}

				return pump();
			} )
			.catch( function () {
				if ( ! started ) {
					fallback();
				} else if ( ! finished ) {
					clear();
					onFailure();
				}
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
		// We already know who a logged-in visitor is.
		if ( config.user && config.user.skipLead ) {
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
		// Logged-in visitors: their account details, ready to send.
		if ( config.user && ( name === 'name' || name === 'email' ) && config.user[ name ] ) {
			field.value = config.user[ name ];
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
		if ( first && ! panel.hidden && ! quiet ) {
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

		api( 'lead', { method: 'POST', body: body } )
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
				renderMessage( m.role, m.content, m.sources, m.cards, m );
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
		api( 'history', {}, { conversation_id: id, visitor_token: visitorToken() } )
			.then( function ( result ) {
				return result.ok ? result.body : null;
			} )
			.then( function ( body ) {
				if ( ! body || ! Array.isArray( body.messages ) ) {
					write( local, KEY_CONVERSATION, null );
					return;
				}
				body.messages.forEach( function ( m ) {
					var role = m.role === 'user' || m.role === 'agent' || m.role === 'system' ? m.role : 'bot';
					var entry = { role: role, content: m.content, sources: m.sources || [], cards: m.cards || [], id: m.id || 0, rating: m.rating || 0 };
					if ( m.agent ) {
						entry.agent = m.agent;
					}
					if ( m.role === 'agent' || m.role === 'system' ) {
						live.after = Math.max( live.after, m.id || 0 );
					}
					messages.push( entry );
					renderMessage( role, m.content, m.sources, m.cards, entry );
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
		if ( live.mode !== 'ai' ) {
			leaveLive();
		}
		setLiveMode( 'ai', null );
		live.after = 0;
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
		if ( popupEl ) {
			popupEl.remove();
			popupEl = null;
		}
		panel.hidden = false;
		launcher.setAttribute( 'aria-expanded', 'true' );
		launcher.setAttribute( 'aria-label', i18n.close );
		hostEl.classList.add( 'sai-is-open' );
		write( session, KEY_OPEN, '1' );
		live.unread = 0;
		if ( liveBadge ) {
			liveBadge.hidden = true;
		}
		liveSchedule();
		scrollDown();
		input.focus();
	}

	function close() {
		panel.hidden = true;
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-label', i18n.open );
		hostEl.classList.remove( 'sai-is-open' );
		write( session, KEY_OPEN, null );
		liveSchedule();
		launcher.focus();
	}

	function autoGrow() {
		input.style.height = 'auto';
		input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
	}

	// ── Live chat with a person ─────────────────────────────────────────────────

	function liveSave() {
		write( local, KEY_LIVE, JSON.stringify( { c: conversationId(), mode: live.mode, after: live.after } ) );
	}

	function liveLoad() {
		try {
			var saved = JSON.parse( read( local, KEY_LIVE ) || '{}' );
			if ( saved && saved.c && saved.c === conversationId() ) {
				live.mode = [ 'waiting', 'human' ].indexOf( saved.mode ) !== -1 ? saved.mode : 'ai';
				live.after = Number( saved.after ) || 0;
			}
		} catch ( e ) {}
	}

	/** Is someone from the team online? Cached briefly: pages are not. */
	function liveCheck() {
		if ( ! config.live ) {
			return Promise.resolve( false );
		}
		if ( live.available !== null && Date.now() - live.checkedAt < 30000 ) {
			return Promise.resolve( live.available );
		}
		return api( 'live/status' ).then( function ( result ) {
			live.available = !! ( result.ok && result.body.available );
			live.checkedAt = Date.now();
			return live.available;
		}, function () {
			return false;
		} );
	}

	function refreshLiveButton() {
		if ( ! liveButton ) {
			return Promise.resolve( false );
		}
		return liveCheck().then( function ( available ) {
			liveButton.hidden = ! available || live.mode !== 'ai';
			return ! liveButton.hidden;
		} );
	}

	/** "Chat with our team" offered under an answer the AI could not give. */
	function offerLive() {
		if ( live.mode !== 'ai' ) {
			return;
		}
		var row = el( 'div', 'sai-flow-menu sai-live-offer' );
		var chip = el( 'button', 'sai-chip', '💬 ' + config.live.label );
		chip.type = 'button';
		chip.addEventListener( 'click', function () {
			row.remove();
			requestLive();
		} );
		row.appendChild( chip );
		log.appendChild( row );
		scrollDown();
	}

	function requestLive() {
		if ( busy || ! passGate() ) {
			return;
		}
		if ( liveButton ) {
			liveButton.disabled = true;
		}

		api( 'live/request', {
			method: 'POST',
			body: { conversation_id: conversationId(), visitor_token: visitorToken(), page_url: window.location.href.split( '#' )[ 0 ] }
		} ).then( function ( result ) {
			if ( liveButton ) {
				liveButton.disabled = false;
			}
			contactBox.hidden = true;

			if ( ! result.ok ) {
				live.available = false;
				live.checkedAt = Date.now();
				addMessage( 'bot', result.body.message || i18n.liveNobody, null, false );
				if ( leadCfg && ! leadDone() ) {
					showLeadForm( 'handoff' );
				}
				return;
			}

			write( local, KEY_CONVERSATION, result.body.conversation_id );
			setLiveMode( result.body.mode, null );
			applyLiveMessages( result.body.messages || [] );
			liveSchedule();
		}, function () {
			if ( liveButton ) {
				liveButton.disabled = false;
			}
			addMessage( 'error', i18n.error, null, false );
		} );
	}

	function leaveLive() {
		var id = conversationId();
		if ( ! id ) {
			return;
		}
		api( 'live/leave', { method: 'POST', body: { conversation_id: id, visitor_token: visitorToken() } } )
			.then( function () {
				pollLive();
			} )
			.catch( function () {} );
	}

	function setLiveMode( mode, agent ) {
		live.mode = mode;
		live.agent = agent || null;
		liveSave();

		if ( liveButton ) {
			liveButton.hidden = mode !== 'ai' || ! live.available;
		}

		if ( ! liveBar ) {
			return;
		}

		liveBar.textContent = '';
		liveBar.hidden = mode === 'ai';

		if ( mode === 'ai' ) {
			return;
		}

		if ( mode === 'human' && live.agent && live.agent.avatar && safeHref( live.agent.avatar ) ) {
			var face = el( 'img', 'sai-agent-avatar' );
			face.src = live.agent.avatar;
			face.alt = '';
			face.onerror = function () {
				face.remove();
			};
			liveBar.appendChild( face );
		}

		liveBar.appendChild( el( 'span', 'sai-live-text', mode === 'human' ? i18n.liveWith.replace( '%s', live.agent ? live.agent.name : '' ) : i18n.liveWaiting ) );

		var end = el( 'button', 'sai-live-end', mode === 'human' ? i18n.liveEnd : i18n.liveCancel );
		end.type = 'button';
		end.addEventListener( 'click', function () {
			setLiveMode( 'ai', null );
			leaveLive();
		} );
		liveBar.appendChild( end );
	}

	function applyLiveMessages( list ) {
		list.forEach( function ( m ) {
			if ( m.id <= live.after ) {
				return;
			}
			live.after = m.id;

			if ( m.role === 'system' ) {
				addMessage( 'system', m.content, null, true );
				if ( m.event === 'timeout' && leadCfg && ! leadDone() ) {
					showLeadForm( 'handoff' );
				}
				return;
			}

			showTyping( false );
			addMessage( 'agent', m.content, null, true, null, { agent: m.agent } );

			if ( panel.hidden && liveBadge ) {
				live.unread++;
				liveBadge.textContent = String( live.unread );
				liveBadge.hidden = false;
			}
		} );
		liveSave();
	}

	function pollLive() {
		clearTimeout( live.timer );
		var id = conversationId();
		if ( ! id || ! config.live ) {
			return;
		}

		api( 'live/poll', {}, { conversation_id: id, visitor_token: visitorToken(), after: live.after } )
			.then( function ( result ) {
				if ( ! result.ok ) {
					if ( result.status === 404 ) {
						setLiveMode( 'ai', null );
					}
					return;
				}
				var body = result.body;
				applyLiveMessages( body.messages || [] );

				var agentChanged = body.agent && ( ! live.agent || live.agent.name !== body.agent.name );
				if ( body.mode !== live.mode || agentChanged ) {
					setLiveMode( body.mode, body.agent );
				}

				if ( body.typing && live.mode === 'human' ) {
					showTyping( true, i18n.liveTyping.replace( '%s', body.typing ) );
				} else if ( ! busy ) {
					showTyping( false );
				}
			} )
			.catch( function () {} )
			.then( liveSchedule );
	}

	/**
	 * Poll only when it matters: often during a live chat, rarely while the
	 * panel is open (an agent may join an AI chat), never otherwise.
	 */
	function liveSchedule() {
		clearTimeout( live.timer );
		if ( ! config.live || ! conversationId() || ! panel ) {
			return;
		}
		var visible = ! panel.hidden && ! document.hidden;
		var delay = live.mode !== 'ai' ? ( visible ? 3000 : 10000 ) : ( visible ? 20000 : 0 );
		if ( delay ) {
			live.timer = setTimeout( pollLive, delay );
		}
	}

	function liveTyping() {
		if ( live.mode === 'ai' || Date.now() - live.typingSent < 4000 || ! input.value.trim() ) {
			return;
		}
		live.typingSent = Date.now();
		api( 'live/typing', { method: 'POST', body: { conversation_id: conversationId(), visitor_token: visitorToken() } } ).catch( function () {} );
	}

	// ── Build ───────────────────────────────────────────────────────────────────

	function build() {
		var inlineHost = document.querySelector( '[data-aicb-inline]' );
		var hours = hoursState();

		// An inline chat on the page replaces the floating bubble. Without
		// one, the bubble follows the page rules and business hours.
		if ( ! inlineHost && ( ! config.floating || ( hours && hours.mode === 'hide' && ! hours.open ) ) ) {
			return;
		}

		inline = !! inlineHost;

		var host = hostEl = el( 'div' );
		host.id = 'all-in-one-ai-chatbot';
		host.style.setProperty( '--sai-color', config.color || '#2563eb' );
		if ( inline ) {
			host.style.outline = 'none';
			host.style.display = 'block';
			host.style.height = '100%';
		}
		( inlineHost || document.body ).appendChild( host );

		root = host.attachShadow ? host.attachShadow( { mode: 'open' } ) : host;

		var css = el( 'link' );
		css.rel = 'stylesheet';
		css.href = config.cssUrl;
		root.appendChild( css );

		var wrap = el( 'div', 'sai-wrap sai-' + ( config.position === 'left' ? 'left' : 'right' ) + ( inline ? ' sai-inline' : '' ) );
		root.appendChild( wrap );

		// Panel.
		panel = el( 'section', 'sai-panel' );
		panel.hidden = true;
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', config.title || 'Chat' );

		var header = el( 'header', 'sai-header' );
		if ( config.avatar && safeHref( config.avatar ) ) {
			var avatar = el( 'img', 'sai-avatar' );
			avatar.src = config.avatar;
			avatar.alt = '';
			header.appendChild( avatar );
		}
		var heading = el( 'div', 'sai-heading' );
		heading.appendChild( el( 'strong', 'sai-title', config.title || '' ) );
		if ( hours ) {
			var status = el( 'span', 'sai-subtitle sai-status ' + ( hours.open ? 'is-online' : 'is-offline' ) );
			status.textContent = hours.open ? i18n.online : i18n.offline + ( hours.back ? ' · ' + i18n.backAt.replace( '%s', hours.back ) : '' );
			heading.appendChild( status );
		} else if ( config.subtitle ) {
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

		if ( ! inline ) {
			var closeButton = el( 'button', 'sai-icon-btn' );
			closeButton.type = 'button';
			closeButton.setAttribute( 'aria-label', i18n.close );
			closeButton.appendChild( icon( ICON_CLOSE ) );
			closeButton.addEventListener( 'click', close );
			header.appendChild( closeButton );
		}

		panel.appendChild( header );

		liveBar = el( 'div', 'sai-live-bar' );
		liveBar.hidden = true;
		liveBar.setAttribute( 'role', 'status' );
		panel.appendChild( liveBar );

		if ( hours && ! hours.open && hours.mode === 'notice' && hours.message ) {
			panel.appendChild( el( 'div', 'sai-offline', hours.message ) );
		}

		log = el( 'div', 'sai-log' );
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );
		panel.appendChild( log );

		suggestionsBox = el( 'div', 'sai-suggestions' );
		if ( config.flows && config.flows.length ) {
			flowChips( config.flows, suggestionsBox, runFlow );
		} else {
			( config.suggestions || [] ).forEach( function ( text ) {
				var chip = el( 'button', 'sai-chip', text );
				chip.type = 'button';
				chip.addEventListener( 'click', function () {
					send( text );
				} );
				suggestionsBox.appendChild( chip );
			} );
		}
		panel.appendChild( suggestionsBox );

		contactBox = el( 'div', 'sai-contact' );
		if ( config.live ) {
			liveButton = el( 'button', 'sai-contact-link sai-live-start', '💬 ' + config.live.label );
			liveButton.type = 'button';
			liveButton.hidden = true;
			liveButton.addEventListener( 'click', requestLive );
			contactBox.appendChild( liveButton );
		}
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
				if ( ! contactBox.hidden && liveButton ) {
					refreshLiveButton().then( function ( shown ) {
						// Live chat was the only way to reach someone, and nobody is on.
						if ( ! shown && contactBox.childNodes.length === 1 ) {
							contactBox.hidden = true;
							addMessage( 'bot', i18n.liveNobody, null, false );
						}
					} );
				}
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
		input.addEventListener( 'input', liveTyping );
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' && ! event.shiftKey && ! event.isComposing ) {
				event.preventDefault();
				send( input.value );
			}
		} );
		form.appendChild( input );

		var mic = voiceButton();
		if ( mic ) {
			form.appendChild( mic );
		}

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

		wrap.appendChild( panel );

		greet();
		restore();

		if ( config.live ) {
			liveLoad();
			if ( live.mode !== 'ai' ) {
				setLiveMode( live.mode, null );
				pollLive();
			}
			document.addEventListener( 'visibilitychange', liveSchedule );
		}

		if ( inline ) {
			panel.hidden = false;
			// Do not pull focus (and scroll the page) before the visitor
			// has touched the chat.
			quiet = true;
			maybeGate();
			quiet = false;
			return;
		}

		panel.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				close();
			}
		} );

		// Launcher.
		launcher = el( 'button', 'sai-launcher' + ( config.label ? ' has-label' : '' ) );
		launcher.type = 'button';
		launcher.setAttribute( 'aria-expanded', 'false' );
		launcher.setAttribute( 'aria-label', config.label || i18n.open );
		if ( config.icon === 'avatar' && config.avatar && safeHref( config.avatar ) ) {
			var face = el( 'img', 'sai-launcher-avatar' );
			face.src = config.avatar;
			face.alt = '';
			launcher.appendChild( face );
		} else {
			launcher.appendChild( icon( LAUNCHER_ICONS[ config.icon ] || ICON_CHAT ) );
		}
		if ( config.label ) {
			launcher.appendChild( el( 'span', 'sai-launcher-label', config.label ) );
		}
		liveBadge = el( 'span', 'sai-launcher-badge' );
		liveBadge.hidden = true;
		launcher.appendChild( liveBadge );
		launcher.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				open();
			} else {
				close();
			}
		} );
		wrap.appendChild( launcher );

		maybeGate();
		schedulePopup( wrap );

		if ( read( session, KEY_OPEN ) === '1' ) {
			open();
		}
	}

	/** The pop-up greeting: once per visit, never over an open chat. */
	function schedulePopup( wrap ) {
		var popup = config.popup;
		if ( ! popup || read( session, KEY_POPUP ) === '1' ) {
			return;
		}
		if ( ! popup.mobile && window.matchMedia && window.matchMedia( '(max-width: 480px)' ).matches ) {
			return;
		}

		setTimeout( function () {
			if ( ! panel.hidden || read( session, KEY_POPUP ) === '1' ) {
				return;
			}
			write( session, KEY_POPUP, '1' );

			var bubble = el( 'div', 'sai-popup' );
			bubble.setAttribute( 'role', 'status' );
			var text = el( 'button', 'sai-popup-text', popup.message );
			text.type = 'button';
			text.addEventListener( 'click', function () {
				bubble.remove();
				open();
			} );
			var dismiss = el( 'button', 'sai-popup-close', '×' );
			dismiss.type = 'button';
			dismiss.setAttribute( 'aria-label', i18n.dismiss );
			dismiss.addEventListener( 'click', function () {
				bubble.remove();
			} );
			bubble.appendChild( text );
			bubble.appendChild( dismiss );
			wrap.insertBefore( bubble, launcher );
			popupEl = bubble;
		}, Math.max( 0, popup.delay || 0 ) * 1000 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
} )();
