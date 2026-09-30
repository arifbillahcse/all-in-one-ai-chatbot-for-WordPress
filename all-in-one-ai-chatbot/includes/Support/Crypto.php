<?php
/**
 * Encryption for API keys at rest.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets before they are written to wp_options.
 *
 * The key is derived from the site's own salts in wp-config.php, so a database
 * dump or a leaked backup alone does not reveal the client's API keys — an
 * attacker also needs the filesystem. That is the realistic threat on shared
 * hosting, where SQL dumps travel far more often than wp-config.php does.
 *
 * If the salts are ever rotated the stored keys become unreadable; decrypt()
 * then returns '' and the admin screen asks for the key again. That is the
 * correct failure: silently using a wrong key is not possible with an
 * authenticated cipher.
 */
final class Crypto {

	private const PREFIX = 'sai1:';

	/**
	 * Encrypt a plaintext secret.
	 *
	 * @param string $plaintext Secret to store.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding.
	}

	/**
	 * Decrypt a stored secret. Returns '' when it cannot be decrypted.
	 *
	 * @param string $stored Value from the database.
	 */
	public static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext decoding.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \SodiumException $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * Show the last four characters of a secret, for "a key is saved" hints.
	 *
	 * @param string $stored Encrypted value.
	 */
	public static function hint( string $stored ): string {
		$plain = self::decrypt( $stored );

		if ( strlen( $plain ) < 8 ) {
			return '' === $plain ? '' : '••••';
		}

		return '••••' . substr( $plain, -4 );
	}

	private static function key(): string {
		// wp_salt() falls back to a generated, stored salt when wp-config.php
		// defines none, so this is never an empty or predictable key.
		return hash( 'sha256', 'softorio-ai|' . wp_salt( 'secure_auth' ), true );
	}
}
