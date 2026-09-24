<?php
/**
 * Lead form endpoint.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Rest;

use Softorio\AiAssistant\Chat\ChatError;
use Softorio\AiAssistant\Leads\LeadService;

defined( 'ABSPATH' ) || exit;

/**
 * POST /softorio-ai/v1/lead — public, like the chat endpoint, and
 * protected the same way: rate limits and server-side validation.
 */
final class LeadController {

	/**
	 * Register the route.
	 */
	public static function register(): void {
		register_rest_route(
			ChatController::NAMESPACE,
			'/lead',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'visitor_token'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9]{16,64}$',
					),
					'conversation_id' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-f0-9]{0,32}$',
					),
				),
			)
		);
	}

	/**
	 * Save a lead.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function create( \WP_REST_Request $request ): \WP_REST_Response {
		$input = array();

		foreach ( array( 'name', 'email', 'phone', 'message', 'consent', 'source', 'conversation_id', 'page_url' ) as $key ) {
			$value         = $request->get_param( $key );
			$input[ $key ] = is_scalar( $value ) ? $value : '';
		}

		try {
			$result = ( new LeadService() )->submit( $input, (string) $request->get_param( 'visitor_token' ) );
		} catch ( ChatError $e ) {
			$response = new \WP_REST_Response(
				array(
					'code'    => $e->error_code,
					'message' => $e->getMessage(),
					'fields'  => $e->fields,
				),
				$e->status
			);

			if ( $e->retry_after > 0 ) {
				$response->header( 'Retry-After', (string) $e->retry_after );
			}

			return $response;
		}

		return new \WP_REST_Response( array( 'message' => $result['message'] ), 201 );
	}
}
