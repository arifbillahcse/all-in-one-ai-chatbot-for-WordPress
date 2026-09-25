/**
 * All in One AI Chatbot — block editor placeholder for the inline chat.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;

	wp.blocks.registerBlockType( 'all-in-one-ai-chatbot/chat', {
		edit: function ( props ) {
			var height = props.attributes.height || 520;

			return el(
				'div',
				useBlockProps( {
					style: { height: height + 'px', border: '2px dashed #c3c4c7', borderRadius: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: '6px', color: '#50575e', background: '#f6f7f7' }
				} ),
				el( 'span', { className: 'dashicons dashicons-format-chat', style: { fontSize: '32px', width: '32px', height: '32px' } } ),
				el( 'strong', null, __( 'AI Chatbot', 'all-in-one-ai-chatbot' ) ),
				el( 'span', null, __( 'The chat appears here on the live page.', 'all-in-one-ai-chatbot' ) ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Size', 'all-in-one-ai-chatbot' ) },
						el( RangeControl, {
							label: __( 'Height (px)', 'all-in-one-ai-chatbot' ),
							min: 320,
							max: 1200,
							step: 10,
							value: height,
							onChange: function ( value ) {
								props.setAttributes( { height: value } );
							}
						} )
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
