<?php
/**
 * REST access to Knowledge Articles.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * The block editor needs Knowledge Articles in the REST API, but WordPress
 * lets anyone read published posts there, whatever the post type's
 * "public" setting. Knowledge Articles are private notes for the assistant
 * (some members-only), so reading them requires being able to edit them.
 */
final class DocsController extends \WP_REST_Posts_Controller {

	/**
	 * Whether the current user may read articles at all.
	 */
	private function can_read(): bool {
		$type = get_post_type_object( $this->post_type );

		return null !== $type && current_user_can( $type->cap->edit_posts );
	}

	/**
	 * Listing articles.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! $this->can_read() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'all-in-one-ai-chatbot' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Reading one article.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! $this->can_read() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'all-in-one-ai-chatbot' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return parent::get_item_permissions_check( $request );
	}
}
