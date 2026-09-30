<?php
/**
 * Build languages/all-in-one-ai-chatbot.pot from the source.
 *
 * Usage: php bin/make-pot.php
 *
 * A small stand-in for `wp i18n make-pot` (which needs WP-CLI): it reads the
 * gettext calls with PHP's own tokenizer, keeps plurals, contexts and
 * "translators:" comments, and records where each string is used.
 *
 * @package Softorio\AiAssistant
 */

// phpcs:disable

$root   = dirname( __DIR__ );
$domain = 'all-in-one-ai-chatbot';

// function => [singular arg, plural arg, context arg, domain arg] (0-based; null = none).
$functions = array(
	'__'         => array( 0, null, null, 1 ),
	'_e'         => array( 0, null, null, 1 ),
	'esc_html__' => array( 0, null, null, 1 ),
	'esc_html_e' => array( 0, null, null, 1 ),
	'esc_attr__' => array( 0, null, null, 1 ),
	'esc_attr_e' => array( 0, null, null, 1 ),
	'_x'         => array( 0, null, 1, 2 ),
	'esc_html_x' => array( 0, null, 1, 2 ),
	'esc_attr_x' => array( 0, null, 1, 2 ),
	'_n'         => array( 0, 1, null, 3 ),
	'_nx'        => array( 0, 1, 3, 4 ),
);

$entries = array();
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $files as $file ) {
	$path = substr( $file->getPathname(), strlen( $root ) + 1 );

	if ( 'php' !== $file->getExtension() || preg_match( '#^(tests|bin|languages)/#', $path ) ) {
		continue;
	}

	$tokens  = token_get_all( (string) file_get_contents( $file->getPathname() ) );
	$count   = count( $tokens );
	$comment = '';

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_COMMENT === $token[0] && preg_match( '#translators:\s*(.*?)\s*(\*/)?$#s', $token[1], $m ) ) {
			$comment = trim( preg_replace( '#\s+#', ' ', $m[1] ) );
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
			continue;
		}

		// Must be a call: name followed by "(", not a method or definition.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( '(' !== ( $tokens[ $j ] ?? '' ) ) {
			continue;
		}
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( is_array( $tokens[ $prev ] ?? null ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		// Collect the literal string arguments (null for anything else).
		$args  = array();
		$depth = 0;
		$arg   = '';
		$plain = true;

		for ( $k = $j; $k < $count; $k++ ) {
			$t = $tokens[ $k ];

			if ( '(' === $t || '[' === $t ) {
				if ( 0 !== $depth++ ) {
					$plain = false;
				}
				continue;
			}
			if ( ')' === $t || ']' === $t ) {
				if ( 0 === --$depth ) {
					$args[] = $plain ? $arg : null;
					break;
				}
				continue;
			}
			if ( ',' === $t && 1 === $depth ) {
				$args[] = $plain ? $arg : null;
				$arg    = '';
				$plain  = true;
				continue;
			}
			if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
				continue;
			}
			if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && 1 === $depth ) {
				$arg .= eval( 'return ' . $t[1] . ';' );
				continue;
			}
			if ( '.' === $t && 1 === $depth ) {
				continue; // "a" . "b" concatenation of literals.
			}
			if ( 1 === $depth ) {
				$plain = false;
			}
		}

		[ $s, $p, $c, $d ] = $functions[ $token[1] ];

		if ( ( $args[ $d ] ?? null ) !== $domain || ! isset( $args[ $s ] ) || '' === $args[ $s ] ) {
			$comment = '';
			continue;
		}

		$key = ( null !== $c ? ( $args[ $c ] ?? '' ) . "\4" : '' ) . $args[ $s ];

		$entries[ $key ] ??= array(
			'msgid'    => $args[ $s ],
			'plural'   => null !== $p ? ( $args[ $p ] ?? '' ) : null,
			'context'  => null !== $c ? ( $args[ $c ] ?? '' ) : null,
			'refs'     => array(),
			'comments' => array(),
		);

		$entries[ $key ]['refs'][] = $path . ':' . $token[2];

		if ( '' !== $comment ) {
			$entries[ $key ]['comments'][ $comment ] = true;
		}

		$comment = '';
	}
}

$quote = static function ( string $s ): string {
	$s = addcslashes( $s, "\\\"\t" );
	$s = str_replace( "\n", '\n', $s );

	return '"' . $s . '"';
};

$header = sprintf(
	"# Copyright (C) %s Softorio\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: All in One AI Chatbot\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"POT-Creation-Date: %s\\n\"\n\"X-Domain: %s\\n\"\n\n",
	gmdate( 'Y' ),
	gmdate( 'Y-m-d H:i+0000' ),
	$domain
);

$out = $header;

foreach ( $entries as $e ) {
	foreach ( array_keys( $e['comments'] ) as $c ) {
		$out .= '#. translators: ' . $c . "\n";
	}
	$out .= '#: ' . implode( ' ', array_slice( array_unique( $e['refs'] ), 0, 6 ) ) . "\n";
	if ( preg_match( '/%[0-9$]*[sd]/', $e['msgid'] . ( $e['plural'] ?? '' ) ) ) {
		$out .= "#, php-format\n";
	}
	if ( null !== $e['context'] ) {
		$out .= 'msgctxt ' . $quote( $e['context'] ) . "\n";
	}
	$out .= 'msgid ' . $quote( $e['msgid'] ) . "\n";
	if ( null !== $e['plural'] ) {
		$out .= 'msgid_plural ' . $quote( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$out .= "msgstr \"\"\n\n";
	}
}

@mkdir( $root . '/languages' );
file_put_contents( $root . '/languages/' . $domain . '.pot', $out );

echo count( $entries ), " strings written to languages/{$domain}.pot\n";
