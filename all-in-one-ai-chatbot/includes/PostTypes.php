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
		add_filter( 'manage_' . self::DOC . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::DOC . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * "Source" and "Who can see it" columns on the Knowledge Articles list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );

		$columns['sai_source'] = __( 'Source', 'all-in-one-ai-chatbot' );

		if ( Knowledge\Audience::enabled() ) {
			$columns['sai_audience'] = __( 'Who can see it', 'all-in-one-ai-chatbot' );
		}

		if ( null !== $date ) {
			$columns['date'] = $date;
		}

		return $columns;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public static function column( string $column, int $post_id ): void {
		if ( 'sai_audience' === $column ) {
			echo esc_html( Knowledge\Audience::label( Knowledge\Audience::for_post( $post_id ) ) );
			return;
		}

		if ( 'sai_source' !== $column ) {
			return;
		}

		$type = (string) get_post_meta( $post_id, Sources\SourceStore::TYPE, true );

		if ( 'url' === $type ) {
			$url = (string) get_post_meta( $post_id, Sources\SourceStore::URL, true );
			printf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>', esc_url( $url ), esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		} elseif ( 'file' === $type ) {
			echo esc_html( (string) get_post_meta( $post_id, Sources\SourceStore::NAME, true ) );
		} elseif ( 'faq' === $type ) {
			esc_html_e( 'FAQ import', 'all-in-one-ai-chatbot' );
		} else {
			esc_html_e( 'Written here', 'all-in-one-ai-chatbot' );
		}
	}

	/**
	 * Register the post type and the exclude meta key.
	 */
	public static function register(): void {
		register_post_type(
			self::DOC,
			array(
				'labels'              => array(
					'name'               => __( 'Knowledge Articles', 'all-in-one-ai-chatbot' ),
					'singular_name'      => __( 'Knowledge Article', 'all-in-one-ai-chatbot' ),
					'menu_name'          => __( 'Knowledge Articles', 'all-in-one-ai-chatbot' ),
					'add_new'            => __( 'Add Article', 'all-in-one-ai-chatbot' ),
					'add_new_item'       => __( 'Add Knowledge Article', 'all-in-one-ai-chatbot' ),
					'edit_item'          => __( 'Edit Knowledge Article', 'all-in-one-ai-chatbot' ),
					'new_item'           => __( 'New Knowledge Article', 'all-in-one-ai-chatbot' ),
					'search_items'       => __( 'Search Knowledge Articles', 'all-in-one-ai-chatbot' ),
					'not_found'          => __( 'No knowledge articles yet.', 'all-in-one-ai-chatbot' ),
					'not_found_in_trash' => __( 'No knowledge articles in the trash.', 'all-in-one-ai-chatbot' ),
					'all_items'          => __( 'Knowledge Articles', 'all-in-one-ai-chatbot' ),
				),
				'description'         => __( 'Answers written for the AI assistant: policies, FAQs, how-to steps. Not shown on the website.', 'all-in-one-ai-chatbot' ),
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
		$types = (array) Settings::get( 'post_types', array() );

		// Knowledge Articles have no "hide" switch (make them a draft), but
		// they get the audience choice when members-only knowledge is on.
		if ( Knowledge\Audience::enabled() ) {
			$types[] = self::DOC;
		} else {
			$types = array_diff( $types, array( self::DOC ) );
		}

		foreach ( array_unique( $types ) as $post_type ) {

			add_meta_box(
				'softorio-ai-exclude',
				__( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
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

		if ( self::DOC !== $post->post_type ) :
			?>
			<label>
				<input type="checkbox" name="softorio_ai_exclude" value="1" <?php checked( $checked ); ?>>
				<?php esc_html_e( 'Hide this from the AI assistant', 'all-in-one-ai-chatbot' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'The assistant will not use this content in its answers.', 'all-in-one-ai-chatbot' ); ?></p>
			<?php
		endif;

		if ( Knowledge\Audience::enabled() ) :
			?>
			<p><strong><?php esc_html_e( 'Who can the assistant share this with?', 'all-in-one-ai-chatbot' ); ?></strong></p>
			<?php Knowledge\Audience::render_picker( 'softorio_ai_audience', Knowledge\Audience::for_post( $post->ID ) ); ?>
			<?php
		endif;
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

		// Only when the picker was on the screen: saving with the feature off
		// must not wipe audiences set earlier.
		if ( Knowledge\Audience::enabled() && isset( $_POST['softorio_ai_audience'] ) ) {
			Knowledge\Audience::set( $post_id, Knowledge\Audience::from_picker( map_deep( wp_unslash( $_POST['softorio_ai_audience'] ), 'sanitize_text_field' ) ) );
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
