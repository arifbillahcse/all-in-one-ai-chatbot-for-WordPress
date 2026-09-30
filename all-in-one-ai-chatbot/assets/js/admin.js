/**
 * All in One AI Chatbot — admin screens.
 */
( function () {
	'use strict';

	var cfg = window.softorioAiAdmin;

	if ( ! cfg ) {
		return;
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return template.replace( /%(\d+\$)?d/g, function ( match, position ) {
			return position ? args[ parseInt( position, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	// ── Index rebuild ───────────────────────────────────────────────────────────
	var rebuild = document.getElementById( 'sai-rebuild' );

	if ( rebuild ) {
		var status = document.getElementById( 'sai-rebuild-status' );
		var progress = document.getElementById( 'sai-rebuild-progress' );

		var step = function ( offset ) {
			post( 'softorio_ai_rebuild', { offset: offset, fresh: offset === 0 ? '1' : '' } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						throw new Error( 'failed' );
					}

					var d = res.data;
					progress.max = Math.max( 1, d.total );
					progress.value = d.processed;
					status.textContent = format( cfg.i18n.indexing, d.processed, d.total );

					if ( d.done ) {
						status.textContent = format( cfg.i18n.indexed, d.total );
						rebuild.disabled = false;
					} else {
						step( d.next );
					}
				} )
				.catch( function () {
					status.textContent = cfg.i18n.failed;
					rebuild.disabled = false;
				} );
		};

		rebuild.addEventListener( 'click', function () {
			rebuild.disabled = true;
			progress.hidden = false;
			progress.value = 0;
			status.textContent = cfg.i18n.working;
			step( 0 );
		} );
	}

	// ── Test search ─────────────────────────────────────────────────────────────
	var searchForm = document.getElementById( 'sai-search-form' );

	if ( searchForm ) {
		var list = document.getElementById( 'sai-search-results' );

		searchForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var q = document.getElementById( 'sai-search-q' ).value;

			list.textContent = cfg.i18n.working;

			post( 'softorio_ai_search', { q: q } )
				.then( function ( res ) {
					list.textContent = '';

					var results = res && res.success ? res.data.results : [];

					if ( ! results.length ) {
						var empty = document.createElement( 'li' );
						empty.textContent = cfg.i18n.noResults;
						list.appendChild( empty );
						return;
					}

					results.forEach( function ( r ) {
						var li = document.createElement( 'li' );
						var a = document.createElement( 'a' );
						a.href = r.url;
						a.target = '_blank';
						a.rel = 'noopener noreferrer';
						a.textContent = r.title;
						li.appendChild( a );

						var score = document.createElement( 'span' );
						score.className = 'sai-score';
						score.textContent = ' ' + r.score + ( r.members ? ' · 🔒 ' + r.members : '' );
						li.appendChild( score );

						var excerpt = document.createElement( 'p' );
						excerpt.textContent = r.excerpt + '…';
						li.appendChild( excerpt );

						list.appendChild( li );
					} );
				} )
				.catch( function () {
					list.textContent = cfg.i18n.failed;
				} );
		} );
	}

	// ── Test connection ─────────────────────────────────────────────────────────
	document.querySelectorAll( '.sai-test' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var provider = button.getAttribute( 'data-provider' );
			var out = document.querySelector( '.sai-test-result[data-for="' + provider + '"]' );

			out.className = 'sai-test-result';
			out.textContent = cfg.i18n.working;

			post( 'softorio_ai_test', { provider: provider } )
				.then( function ( res ) {
					out.classList.add( res && res.success ? 'is-ok' : 'is-error' );
					out.textContent = res && res.data && res.data.message ? res.data.message : cfg.i18n.failed;
				} )
				.catch( function () {
					out.classList.add( 'is-error' );
					out.textContent = cfg.i18n.failed;
				} );
		} );
	} );

	// ── Test email / Telegram / webhooks ────────────────────────────────────────
	document.querySelectorAll( '.sai-test-integration' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var kind = button.getAttribute( 'data-kind' );
			var out = document.querySelector( '.sai-test-result[data-for="' + kind + '"]' );

			out.className = 'sai-test-result';
			out.textContent = cfg.i18n.working;
			button.disabled = true;

			post( 'softorio_ai_test_integration', { kind: kind } )
				.then( function ( res ) {
					out.classList.add( res && res.success ? 'is-ok' : 'is-error' );
					out.textContent = res && res.data && res.data.message ? res.data.message : cfg.i18n.failed;
				} )
				.catch( function () {
					out.classList.add( 'is-error' );
					out.textContent = cfg.i18n.failed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	} );

	// ── Media picker for image settings ─────────────────────────────────────────
	document.querySelectorAll( '.sai-media' ).forEach( function ( button ) {
		var frame = null;
		button.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			var input = document.getElementById( button.getAttribute( 'data-target' ) );
			var preview = button.parentNode.querySelector( '.sai-image-preview' );
			frame = frame || window.wp.media( { library: { type: 'image' }, multiple: false } );
			frame.off( 'select' ).on( 'select', function () {
				var image = frame.state().get( 'selection' ).first().toJSON();
				var url = ( image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url );
				input.value = url;
				preview.src = url;
				preview.hidden = false;
			} );
			frame.open();
		} );
	} );

	// ── Delete conversation ─────────────────────────────────────────────────────
	var del = document.getElementById( 'sai-delete-conversation' );

	if ( del ) {
		del.addEventListener( 'click', function () {
			if ( ! window.confirm( cfg.i18n.confirm ) ) {
				return;
			}

			post( 'softorio_ai_delete_conversation', { id: del.getAttribute( 'data-id' ) } ).then( function () {
				window.location.href = del.getAttribute( 'data-back' );
			} );
		} );
	}
} )();
