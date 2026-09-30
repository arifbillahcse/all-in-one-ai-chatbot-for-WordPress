/**
 * All in One AI Chatbot — live chat alerts on every admin screen.
 *
 * Rides on WordPress's Heartbeat (which also keeps the agent "online"):
 * when a visitor starts waiting, or writes in one of the agent's live
 * chats, it chimes, shows a notice with a link, and updates the menu badge.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.softorioAiLive;
	if ( ! cfg || ! $ ) {
		return;
	}

	var last = null;
	var audio = null;
	var notice = null;

	function chime() {
		if ( ! cfg.sound ) {
			return;
		}
		try {
			audio = audio || new ( window.AudioContext || window.webkitAudioContext )();
			[ 880, 1175 ].forEach( function ( freq, i ) {
				var osc = audio.createOscillator();
				var gain = audio.createGain();
				var start = audio.currentTime + i * 0.16;
				osc.frequency.value = freq;
				gain.gain.setValueAtTime( 0.0001, start );
				gain.gain.exponentialRampToValueAtTime( 0.2, start + 0.02 );
				gain.gain.exponentialRampToValueAtTime( 0.0001, start + 0.3 );
				osc.connect( gain );
				gain.connect( audio.destination );
				osc.start( start );
				osc.stop( start + 0.32 );
			} );
		} catch ( e ) {}
	}

	function show( text ) {
		if ( ! notice ) {
			notice = $( '<div class="notice notice-warning is-dismissible sai-live-alert"><p></p></div>' );
			$( '.wrap' ).first().prepend( notice );
		}
		var p = notice.find( 'p' ).empty();
		p.append( document.createTextNode( text + ' ' ) );
		$( '<a class="button button-primary"></a>' ).attr( 'href', cfg.inbox ).text( cfg.i18n.open ).appendTo( p );

		if ( window.Notification && Notification.permission === 'granted' && document.hidden ) {
			var n = new Notification( text );
			n.onclick = function () {
				window.focus();
				window.location.href = cfg.inbox;
			};
		}
	}

	$( document )
		.on( 'heartbeat-send', function ( e, data ) {
			data.softorio_ai_live = 1;
		} )
		.on( 'heartbeat-tick', function ( e, data ) {
			var now = data && data.softorio_ai_live;
			if ( ! now ) {
				return;
			}

			$( '.sai-live-count' ).toggleClass( 'count-0', ! now.waiting ).find( '.pending-count' ).text( now.waiting );

			if ( last && ( now.waiting > last.waiting || now.unread > last.unread ) ) {
				chime();
				show( now.waiting > last.waiting ? cfg.i18n.waiting : cfg.i18n.newMessage );
			}
			last = now;
		} );

	// Browsers only allow sound after a click on the page.
	$( document ).one( 'click', function () {
		try {
			audio = audio || new ( window.AudioContext || window.webkitAudioContext )();
			audio.resume();
		} catch ( e ) {}
	} );

	// Ask right away instead of waiting a full Heartbeat interval.
	if ( window.wp && wp.heartbeat ) {
		wp.heartbeat.connectNow();
	}
} )( window.jQuery );
