/**
 * All in One AI Chatbot — Live Chat inbox for agents.
 *
 * Polls the agent REST endpoints: the inbox every few seconds (which also
 * keeps this agent "online"), and the open conversation more often. Every
 * piece of visitor text is inserted with textContent, never as HTML.
 */
( function () {
	'use strict';

	var cfg = window.softorioAiLive;
	var root = document.getElementById( 'sai-live' );

	if ( ! cfg || ! root ) {
		return;
	}

	// wp_localize_script sends every value as a string.
	cfg.open = parseInt( cfg.open, 10 ) || 0;
	cfg.me = parseInt( cfg.me, 10 ) || 0;
	cfg.sound = cfg.sound === true || cfg.sound === '1';

	var t = cfg.i18n;
	var state = {
		list: [],
		away: false,
		online: [],
		openId: cfg.open || 0,
		after: 0,
		conversation: null,
		waitingSeen: {},
		unreadSeen: {},
		first: true
	};
	var ui = {};
	var inboxTimer = null;
	var convTimer = null;
	var typingSent = 0;
	var baseTitle = document.title;
	var audio = null;

	// ── Helpers ─────────────────────────────────────────────────────────────

	function el( tag, className, text ) {
		var n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = text;
		}
		return n;
	}

	/** REST URL with query args; works with and without pretty permalinks. */
	function url( path, params ) {
		var base = cfg.rest + path;
		var query = Object.keys( params || {} ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
		} ).join( '&' );
		return query ? base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + query : base;
	}

	function api( path, options, params ) {
		options = options || {};
		return fetch( url( path, params ), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: options.body ? JSON.stringify( options.body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( body && body.message ? body.message : 'HTTP ' + response.status );
				}
				return body;
			} );
		} );
	}

	function ago( mysql ) {
		if ( ! mysql ) {
			return '';
		}
		var seconds = Math.max( 0, Math.round( ( Date.now() - Date.parse( mysql.replace( ' ', 'T' ) + 'Z' ) ) / 1000 ) );
		if ( seconds < 60 ) {
			return seconds + 's';
		}
		if ( seconds < 3600 ) {
			return Math.floor( seconds / 60 ) + 'm';
		}
		return Math.floor( seconds / 3600 ) + 'h';
	}

	function clock( mysql ) {
		var d = new Date( Date.parse( String( mysql ).replace( ' ', 'T' ) + 'Z' ) );
		return isNaN( d ) ? '' : d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
	}

	// ── Alerts ──────────────────────────────────────────────────────────────

	/** A short two-tone chime made with Web Audio: no sound file needed. */
	function chime( soft ) {
		if ( ! cfg.sound ) {
			return;
		}
		try {
			audio = audio || new ( window.AudioContext || window.webkitAudioContext )();
			[ 880, soft ? 880 : 1175 ].forEach( function ( freq, i ) {
				var osc = audio.createOscillator();
				var gain = audio.createGain();
				var start = audio.currentTime + i * 0.16;
				osc.type = 'sine';
				osc.frequency.value = freq;
				gain.gain.setValueAtTime( 0.0001, start );
				gain.gain.exponentialRampToValueAtTime( soft ? 0.08 : 0.2, start + 0.02 );
				gain.gain.exponentialRampToValueAtTime( 0.0001, start + 0.3 );
				osc.connect( gain );
				gain.connect( audio.destination );
				osc.start( start );
				osc.stop( start + 0.32 );
			} );
		} catch ( e ) {}
	}

	function notify( title, body, id ) {
		if ( ! window.Notification || Notification.permission !== 'granted' || ! document.hidden ) {
			return;
		}
		var n = new Notification( title, { body: body || '', tag: 'sai-live-' + id } );
		n.onclick = function () {
			window.focus();
			openConversation( id );
			n.close();
		};
	}

	function updateTitle( waiting, unread ) {
		var total = waiting + unread;
		document.title = ( total ? '(' + total + ') ' : '' ) + baseTitle;
		document.querySelectorAll( '.sai-live-count' ).forEach( function ( badge ) {
			badge.className = 'awaiting-mod sai-live-count' + ( waiting ? '' : ' count-0' );
			var inner = badge.querySelector( '.pending-count' );
			if ( inner ) {
				inner.textContent = waiting;
			}
		} );
	}

	// Browsers only allow sound after the page was clicked once.
	document.addEventListener( 'click', function () {
		if ( audio && audio.state === 'suspended' ) {
			audio.resume();
		}
	} );

	// ── Layout ──────────────────────────────────────────────────────────────

	function build() {
		var bar = el( 'div', 'sai-live-bar' );

		ui.status = el( 'button', 'sai-live-status' );
		ui.status.type = 'button';
		ui.status.addEventListener( 'click', function () {
			api( 'agent/presence', { method: 'POST', body: { away: ! state.away } } ).then( renderInbox ).catch( function () {} );
			if ( ! audio ) {
				chime( true );
			}
		} );
		bar.appendChild( ui.status );

		ui.online = el( 'span', 'sai-live-online' );
		bar.appendChild( ui.online );

		if ( window.Notification && Notification.permission === 'default' ) {
			var ask = el( 'button', 'button-link sai-live-notify', t.notify );
			ask.type = 'button';
			ask.addEventListener( 'click', function () {
				Notification.requestPermission().then( function () {
					ask.remove();
				} );
			} );
			bar.appendChild( ask );
		}

		ui.error = el( 'span', 'sai-live-error' );
		ui.error.hidden = true;
		bar.appendChild( ui.error );

		root.appendChild( bar );

		var panes = el( 'div', 'sai-live-panes' );
		ui.list = el( 'div', 'sai-live-list' );
		panes.appendChild( ui.list );

		ui.main = el( 'div', 'sai-live-main' );
		panes.appendChild( ui.main );
		root.appendChild( panes );

		renderEmptyMain();
	}

	function renderEmptyMain() {
		ui.main.textContent = '';
		ui.main.appendChild( el( 'p', 'sai-live-pick', t.pick ) );
	}

	// ── Inbox ───────────────────────────────────────────────────────────────

	function pollInbox() {
		clearTimeout( inboxTimer );
		api( 'agent/inbox' )
			.then( function ( body ) {
				ui.error.hidden = true;
				renderInbox( body );
			} )
			.catch( function () {
				ui.error.textContent = t.failed;
				ui.error.hidden = false;
			} )
			.then( function () {
				inboxTimer = setTimeout( pollInbox, document.hidden ? 8000 : 4000 );
			} );
	}

	function renderInbox( body ) {
		state.list = body.conversations || [];
		state.away = !! body.away;
		state.online = body.online || [];

		ui.status.textContent = state.away ? '○ ' + t.away : '● ' + t.available;
		ui.status.className = 'sai-live-status ' + ( state.away ? 'is-away' : 'is-available' );
		ui.online.textContent = state.online.length ? t.online + ' ' + state.online.join( ', ' ) : t.nobody;

		var waiting = 0;
		var unread = 0;
		var alertWaiting = null;
		var alertMessage = null;

		state.list.forEach( function ( c ) {
			if ( c.mode === 'waiting' ) {
				waiting++;
				if ( ! state.waitingSeen[ c.id ] ) {
					alertWaiting = alertWaiting || c;
				}
			}
			if ( c.mine && c.unread ) {
				unread += c.unread;
				if ( ( state.unreadSeen[ c.id ] || 0 ) < c.unread && c.id !== state.openId ) {
					alertMessage = alertMessage || c;
				}
			}
		} );

		// No alerts for what was already there when the screen opened.
		if ( ! state.first ) {
			if ( alertWaiting ) {
				chime( false );
				notify( t.waiting, alertWaiting.visitor + ': ' + alertWaiting.last, alertWaiting.id );
			} else if ( alertMessage ) {
				chime( true );
				notify( t.newMessage, alertMessage.visitor + ': ' + alertMessage.last, alertMessage.id );
			}
		}

		state.waitingSeen = {};
		state.unreadSeen = {};
		state.list.forEach( function ( c ) {
			if ( c.mode === 'waiting' ) {
				state.waitingSeen[ c.id ] = true;
			}
			state.unreadSeen[ c.id ] = c.unread;
		} );
		state.first = false;

		updateTitle( waiting, unread );
		drawList();
	}

	function drawList() {
		ui.list.textContent = '';

		if ( ! state.list.length ) {
			ui.list.appendChild( el( 'p', 'sai-live-empty', t.empty ) );
			return;
		}

		[
			[ 'waiting', t.waitingList ],
			[ 'human', t.liveList ],
			[ 'ai', t.aiList ]
		].forEach( function ( group ) {
			var items = state.list.filter( function ( c ) {
				return c.mode === group[ 0 ];
			} );
			if ( ! items.length ) {
				return;
			}
			ui.list.appendChild( el( 'h3', 'sai-live-group sai-live-group-' + group[ 0 ], group[ 1 ] + ' (' + items.length + ')' ) );

			items.forEach( function ( c ) {
				var item = el( 'button', 'sai-live-item is-' + c.mode + ( c.id === state.openId ? ' is-open' : '' ) + ( c.mine ? ' is-mine' : '' ) );
				item.type = 'button';

				var top = el( 'span', 'sai-live-item-top' );
				top.appendChild( el( 'strong', null, c.visitor ) );
				top.appendChild( el( 'span', 'sai-live-time', c.mode === 'waiting' ? t.waitingFor.replace( '%s', ago( c.since ) ) : ago( c.updated ) ) );
				item.appendChild( top );

				var last = el( 'span', 'sai-live-last', c.typing ? t.typing : c.last );
				item.appendChild( last );

				if ( c.mode === 'human' && c.agent && ! c.mine ) {
					item.appendChild( el( 'span', 'sai-live-agent', '🧑‍💼 ' + c.agent ) );
				}
				if ( c.unread && c.mode !== 'ai' ) {
					item.appendChild( el( 'span', 'sai-live-unread', String( c.unread ) ) );
				}

				item.addEventListener( 'click', function () {
					openConversation( c.id );
				} );
				ui.list.appendChild( item );
			} );
		} );
	}

	// ── Conversation ────────────────────────────────────────────────────────

	function openConversation( id ) {
		state.openId = id;
		state.after = 0;
		state.conversation = null;
		clearTimeout( convTimer );

		if ( window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'conversation', id );
			window.history.replaceState( null, '', url.toString() );
		}

		ui.main.textContent = '';

		ui.head = el( 'div', 'sai-live-head' );
		ui.main.appendChild( ui.head );

		ui.details = el( 'div', 'sai-live-details' );
		ui.main.appendChild( ui.details );

		ui.log = el( 'div', 'sai-live-log' );
		ui.main.appendChild( ui.log );

		ui.typing = el( 'div', 'sai-live-typing', t.typing );
		ui.typing.hidden = true;
		ui.main.appendChild( ui.typing );

		ui.note = el( 'div', 'sai-live-note' );
		ui.main.appendChild( ui.note );

		var form = el( 'form', 'sai-live-form' );
		ui.input = el( 'textarea', 'sai-live-input' );
		ui.input.rows = 2;
		ui.input.placeholder = t.placeholder;
		ui.input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				sendReply();
			}
		} );
		ui.input.addEventListener( 'input', function () {
			if ( Date.now() - typingSent > 4000 && ui.input.value.trim() ) {
				typingSent = Date.now();
				api( 'agent/conversations/' + state.openId + '/typing', { method: 'POST', body: {} } ).catch( function () {} );
			}
		} );
		form.appendChild( ui.input );

		ui.send = el( 'button', 'button button-primary', t.send );
		ui.send.type = 'submit';
		form.appendChild( ui.send );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			sendReply();
		} );
		ui.main.appendChild( form );

		drawList();
		pollConversation();
		ui.input.focus();
	}

	function pollConversation() {
		clearTimeout( convTimer );
		var id = state.openId;

		api( 'agent/conversations/' + id, {}, { after: state.after } )
			.then( function ( body ) {
				if ( id === state.openId ) {
					applyConversation( body );
				}
			} )
			.catch( function () {} )
			.then( function () {
				if ( id === state.openId ) {
					convTimer = setTimeout( pollConversation, document.hidden ? 6000 : 2500 );
				}
			} );
	}

	function applyConversation( body ) {
		var c = body.conversation;
		var changed = ! state.conversation || state.conversation.mode !== c.mode || state.conversation.agentId !== c.agentId;
		state.conversation = c;

		if ( changed ) {
			drawHead();
		}

		( body.messages || [] ).forEach( function ( m ) {
			drawMessage( m );
			state.after = Math.max( state.after, m.id );
		} );

		ui.typing.hidden = ! body.typing;
		if ( body.messages && body.messages.length ) {
			ui.log.scrollTop = ui.log.scrollHeight;
		}
	}

	function drawHead() {
		var c = state.conversation;
		ui.head.textContent = '';

		var title = el( 'div', 'sai-live-title' );
		title.appendChild( el( 'h2', null, c.visitor ) );
		var badge = el( 'span', 'sai-live-mode is-' + c.mode, c.mode === 'human' ? t.modeHuman + ( c.agent ? ' · ' + c.agent : '' ) : c.mode === 'waiting' ? t.modeWaiting : t.modeAi );
		title.appendChild( badge );
		ui.head.appendChild( title );

		var actions = el( 'div', 'sai-live-actions' );
		var mine = c.mode === 'human' && c.agentId === cfg.me;

		if ( ! mine ) {
			actions.appendChild( action( t.takeover, 'takeover', 'button button-primary' ) );
		}
		if ( c.mode !== 'ai' ) {
			actions.appendChild( action( t.release, 'release', 'button' ) );
			actions.appendChild( action( t.close, 'close', 'button sai-live-end', t.confirmEnd ) );
		}
		ui.head.appendChild( actions );

		ui.details.textContent = '';
		[
			[ t.email, c.email, c.email ? 'mailto:' + c.email : '' ],
			[ t.phone, c.phone, c.phone ? 'tel:' + c.phone : '' ],
			[ t.account, c.account, c.userUrl ],
			[ t.page, c.page ? c.page.replace( /^https?:\/\/[^/]+/, '' ) || '/' : '', c.page ]
		].forEach( function ( row ) {
			if ( ! row[ 1 ] ) {
				return;
			}
			var item = el( 'span', 'sai-live-detail' );
			item.appendChild( el( 'span', 'sai-live-detail-label', row[ 0 ] + ': ' ) );
			if ( row[ 2 ] && /^(https?:|mailto:|tel:)/i.test( row[ 2 ] ) ) {
				var a = el( 'a', null, row[ 1 ] );
				a.href = row[ 2 ];
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				item.appendChild( a );
			} else {
				item.appendChild( document.createTextNode( row[ 1 ] ) );
			}
			ui.details.appendChild( item );
		} );

		ui.note.textContent = c.mode === 'ai' ? t.aiNote : c.mode === 'human' && ! mine && c.agent ? t.otherAgent.replace( '%s', c.agent ) : '';
		ui.note.hidden = ! ui.note.textContent;
	}

	function action( label, name, className, confirmText ) {
		var b = el( 'button', className, label );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			if ( confirmText && ! window.confirm( confirmText ) ) {
				return;
			}
			b.disabled = true;
			api( 'agent/conversations/' + state.openId + '/' + name, { method: 'POST', body: { after: state.after } } )
				.then( applyConversation )
				.catch( function ( e ) {
					window.alert( e.message );
				} )
				.then( function () {
					b.disabled = false;
					pollInbox();
				} );
		} );
		return b;
	}

	function drawMessage( m ) {
		if ( m.role === 'system' ) {
			ui.log.appendChild( el( 'div', 'sai-live-system', m.content ) );
			return;
		}

		var row = el( 'div', 'sai-live-msg is-' + m.role );
		var who = m.role === 'user' ? t.visitor : m.role === 'assistant' ? '🤖 ' + t.ai : '🧑‍💼 ' + ( m.agent && m.agent.name ? m.agent.name : '' );
		var meta = el( 'div', 'sai-live-meta', who + ' · ' + clock( m.time ) );
		row.appendChild( meta );
		row.appendChild( el( 'div', 'sai-live-bubble', m.content ) );
		ui.log.appendChild( row );
	}

	function sendReply() {
		var text = ui.input.value.trim();
		if ( ! text || ! state.openId ) {
			return;
		}
		ui.send.disabled = true;
		api( 'agent/conversations/' + state.openId + '/message', { method: 'POST', body: { text: text, after: state.after } } )
			.then( function ( body ) {
				ui.input.value = '';
				applyConversation( body );
				ui.log.scrollTop = ui.log.scrollHeight;
			} )
			.catch( function ( e ) {
				window.alert( e.message );
			} )
			.then( function () {
				ui.send.disabled = false;
				ui.input.focus();
				pollInbox();
			} );
	}

	build();
	pollInbox();
	if ( state.openId ) {
		openConversation( state.openId );
	}
} )();
