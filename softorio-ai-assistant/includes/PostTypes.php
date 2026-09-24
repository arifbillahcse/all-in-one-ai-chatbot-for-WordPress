<?php
/**
 * The Knowledge Article post type and the per-post "exclude" switch.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge Articles are private posts written only for the assistant.
 *
 * Site owners usually have answers that do not belong on a public page —
 * refund rules, delivery times, "how do I…" steps, opening hours. Giving them a
 * dedicated post type means they are edited with the normal WordPress editor,
 * revisioned, and never appear on the front end, sitemaps or search.
 */
final class PostTypes {

	public const DOC         = 'softorio_ai_doc';
	public const EXCLUDE_KEY = '_softorio_ai_exclude';

	/**
	 * Hook everything up.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_exclude_box' ) );
		add_action( 'save_post', array( self::class, 'save_exclude_box' ), 5 );
	}

	/**
	 * Register the post type and the exclude meta key.
	 */
	public static function register(): void {
		register_post_type(
			self::DOC,
			array(
				'labels'              => array(
					'name'               => __( 'Knowledge Articles', 'softorio-ai-assistant' ),
					'singular_name'      => __( 'Knowledge Article', 'softorio-ai-assistant' ),
					'menu_name'          => __( 'Knowledge Articles', 'softorio-ai-assistant' ),
					'add_new'            => __( 'Add Article', 'softorio-ai-assistant' ),
					'add_new_item'       => __( 'Add Knowledge Article', 'softorio-ai-assistant' ),
					'edit_item'          => __( 'Edit Knowledge Article', 'softorio-ai-assistant' ),
					'new_item'           => __( 'New Knowledge Article', 'softorio-ai-assistant' ),
					'search_items'       => __( 'Search Knowledge Articles', 'softorio-ai-assistant' ),
					'not_found'          => __( 'No knowledge articles yet.', 'softorio-ai-assistant' ),
					'not_found_in_trash' => __( 'No knowledge articles in the trash.', 'softorio-ai-assistant' ),
					'all_items'          => __( 'Knowledge Articles', 'softorio-ai-assistant' ),
				),
				'description'         => __( 'Answers written for the AI assistant: policies, FAQs, how-to steps. Not shown on the website.', 'softorio-ai-assistant' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => Admin\Menu::SLUG,
				'show_in_rest'        => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_post_meta(
			'',
			self::EXCLUDE_KEY,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * Show the exclude switch on every post type the assistant reads.
	 */
	public static function add_exclude_box(): void {
		foreach ( (array) Settings::get( 'post_types', array() ) as $post_type ) {
			if ( self::DOC === $post_type ) {
				continue;
			}

			add_meta_box(
				'softorio-ai-exclude',
				__( 'AI Assistant', 'softorio-ai-assistant' ),
				array( self::class, 'render_exclude_box' ),
				(string) $post_type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the exclude checkbox.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public static function render_exclude_box( \WP_Post $post ): void {
		wp_nonce_field( 'softorio_ai_exclude', 'softorio_ai_exclude_nonce' );

		$checked = (bool) get_post_meta( $post->ID, self::EXCLUDE_KEY, true );
		?>
		<label>
			<input type="checkbox" name="softorio_ai_exclude" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Hide this from the AI assistant', 'softorio-ai-assistant' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'The assistant will not use this content in its answers.', 'softorio-ai-assistant' ); ?></p>
		<?php
	}

	/**
	 * Persist the exclude checkbox.
	 *
	 * Runs at priority 5, before the indexer's hook, so the indexer sees the
	 * new value on the same save.
	 *
	 * @param int $post_id Post id.
	 */
	public static function save_exclude_box( int $post_id ): void {
		if ( ! isset( $_POST['softorio_ai_exclude_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['softorio_ai_exclude_nonce'] ) ), 'softorio_ai_exclude' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['softorio_ai_exclude'] ) ) {
			update_post_meta( $post_id, self::EXCLUDE_KEY, true );
		} else {
			delete_post_meta( $post_id, self::EXCLUDE_KEY );
		}
	}

	/**
	 * Whether a post is marked as hidden from the assistant.
	 *
	 * @param int $post_id Post id.
	 */
	public static function is_excluded( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::EXCLUDE_KEY, true );
	}
}
