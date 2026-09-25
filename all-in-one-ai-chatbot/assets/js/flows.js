/**
 * All in One AI Chatbot — Quick Replies builder.
 *
 * Edits a tree of buttons held as JSON in #sai-flows-json. Every node is
 * re-validated on the server, so this only needs to be convenient, not safe.
 */
( function () {
	'use strict';

	var cfg = window.softorioAiFlows;
	var root = document.getElementById( 'sai-flows' );
	var field = document.getElementById( 'sai-flows-json' );

	if ( ! cfg || ! root || ! field ) {
		return;
	}

	var MAX_DEPTH = 4;
	var tree;

	try {
		tree = JSON.parse( field.value || '[]' );
	} catch ( e ) {
		tree = [];
	}

	function node() {
		return { label: '', action: 'reply', reply: '', url: '', prompt: '', children: [] };
	}

	function el( tag, className, text ) {
		var n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	function input( type, value, placeholder, onInput ) {
		var i = el( type === 'textarea' ? 'textarea' : 'input' );
		if ( type !== 'textarea' ) {
			i.type = type;
		} else {
			i.rows = 2;
		}
		i.value = value || '';
		i.placeholder = placeholder || '';
		i.addEventListener( 'input', function () {
			onInput( i.value );
			sync();
		} );
		return i;
	}

	function sync() {
		field.value = JSON.stringify( tree );
	}

	function render() {
		root.textContent = '';
		if ( ! tree.length ) {
			root.appendChild( el( 'p', 'description', cfg.i18n.empty ) );
		}
		root.appendChild( list( tree, 1 ) );
		sync();
	}

	function list( nodes, depth ) {
		var ul = el( 'ul', 'sai-flow-list' );

		nodes.forEach( function ( item, index ) {
			item.children = item.children || [];

			var li = el( 'li', 'sai-flow-node' );
			var row = el( 'div', 'sai-flow-row' );

			var label = input( 'text', item.label, cfg.i18n.label, function ( v ) {
				item.label = v;
			} );
			label.className = 'sai-flow-label';
			label.maxLength = 40;
			row.appendChild( label );

			var action = el( 'select', 'sai-flow-action' );
			Object.keys( cfg.actions ).forEach( function ( key ) {
				var opt = el( 'option', null, cfg.actions[ key ] );
				opt.value = key;
				opt.selected = item.action === key;
				action.appendChild( opt );
			} );
			action.addEventListener( 'change', function () {
				item.action = action.value;
				render();
			} );
			row.appendChild( action );

			var tools = el( 'span', 'sai-flow-tools' );
			[
				[ '↑', cfg.i18n.up, index > 0, function () {
					nodes.splice( index - 1, 0, nodes.splice( index, 1 )[ 0 ] );
				} ],
				[ '↓', cfg.i18n.down, index < nodes.length - 1, function () {
					nodes.splice( index + 1, 0, nodes.splice( index, 1 )[ 0 ] );
				} ],
				[ '✕', cfg.i18n.remove, true, function () {
					if ( ! item.children.length || window.confirm( cfg.i18n.confirm ) ) {
						nodes.splice( index, 1 );
					}
				} ]
			].forEach( function ( t ) {
				var b = el( 'button', 'button-link', t[ 0 ] );
				b.type = 'button';
				b.title = t[ 1 ];
				b.setAttribute( 'aria-label', t[ 1 ] );
				b.disabled = ! t[ 2 ];
				b.addEventListener( 'click', function () {
					t[ 3 ]();
					render();
				} );
				tools.appendChild( b );
			} );
			row.appendChild( tools );
			li.appendChild( row );

			var details = el( 'div', 'sai-flow-details' );
			details.appendChild( input( 'textarea', item.reply, item.action === 'reply' ? cfg.i18n.reply : cfg.i18n.replyOptional, function ( v ) {
				item.reply = v;
			} ) );

			if ( item.action === 'link' ) {
				details.appendChild( input( 'url', item.url, 'https://…', function ( v ) {
					item.url = v;
				} ) );
			}
			if ( item.action === 'ask_ai' ) {
				details.appendChild( input( 'text', item.prompt, cfg.i18n.prompt, function ( v ) {
					item.prompt = v;
				} ) );
			}
			li.appendChild( details );

			if ( item.action === 'reply' ) {
				if ( item.children.length ) {
					li.appendChild( list( item.children, depth + 1 ) );
				}
				if ( depth < MAX_DEPTH ) {
					var add = el( 'button', 'button-link sai-flow-add-child', cfg.i18n.addChild );
					add.type = 'button';
					add.addEventListener( 'click', function () {
						item.children.push( node() );
						render();
					} );
					li.appendChild( add );
				}
			}

			ul.appendChild( li );
		} );

		return ul;
	}

	document.getElementById( 'sai-flow-add' ).addEventListener( 'click', function () {
		tree.push( node() );
		render();
	} );

	var example = document.getElementById( 'sai-flow-example' );
	example.addEventListener( 'click', function () {
		if ( tree.length && ! window.confirm( cfg.i18n.replace ) ) {
			return;
		}
		tree = JSON.parse( example.getAttribute( 'data-example' ) );
		render();
	} );

	render();
} )();
