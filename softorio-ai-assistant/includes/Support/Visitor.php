<?php
/**
 * Who is calling, as far as rate limiting is concerned.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the client IP and the key used to rate-limit it.
 */
final class Visitor {

	/**
	 * The client IP.
	 *
	 * Proxy headers are forgeable by anyone, so they are only read when the site
	 * owner says the site really is behind Cloudflare. Otherwise a visitor could
	 * send a new fake header with each message and never hit a limit.
	 */
	public static function ip(): string {
		if ( Settings::get( 'trust_cloudflare', false ) && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );

			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	/**
	 * Rate-limit key for an IP.
	 *
	 * IPv6 addresses are grouped by /64: one home connection or phone gets a
	 * whole /64, so keying on the full address would hand every visitor
	 * billions of fresh limits.
	 *
	 * @param string $ip Client IP.
	 */
	public static function limit_key( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );

			if ( false !== $packed ) {
				$ip = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
			}
		}

		return 'ip|' . $ip;
	}

	/**
	 * One-way hash of a visitor's browser token, so the database never holds
	 * a value that could be replayed to read their conversation.
	 *
	 * @param string $token Random token from the widget.
	 */
	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}
}
