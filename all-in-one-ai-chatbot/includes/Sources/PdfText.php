<?php
/**
 * Text from PDF files.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A small PDF text extractor in plain PHP.
 *
 * Shared hosting rarely has pdftotext, and a Composer PDF library would
 * weigh more than the whole plugin, so this reads the common cases itself:
 *
 *  - compressed streams (Flate, ASCIIHex, ASCII85) and compressed object
 *    streams (PDF 1.5+, as written by Word, LibreOffice, Chrome and Google Docs);
 *  - fonts with a ToUnicode map (Unicode text, including Bangla), and simple
 *    fonts in WinAnsi / MacRoman / Standard encoding with Differences;
 *  - text inside Form XObjects;
 *  - reading order by page, with line and paragraph breaks from positioning.
 *
 * Scanned PDFs (pictures of text) and encrypted PDFs have no readable text;
 * those fail with a message the owner can act on.
 */
final class PdfText {

	/** Stop runaway files: largest decoded stream, most pages. */
	private const MAX_STREAM = 20_000_000;
	private const MAX_PAGES  = 2000;

	/**
	 * Raw objects: number => body (the part between "obj" and "endobj").
	 *
	 * @var array<int, string>
	 */
	private array $objects = array();

	/**
	 * Decoded stream data by object number.
	 *
	 * @var array<int, string|null>
	 */
	private array $streams = array();

	/**
	 * Parsed fonts by object number.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fonts = array();

	private string $data;

	/**
	 * Extract the text of a PDF.
	 *
	 * @param string $data File contents.
	 * @throws SourceException When the file is not a readable PDF.
	 */
	public static function extract( string $data ): string {
		if ( ! str_starts_with( ltrim( substr( $data, 0, 1024 ) ), '%PDF-' ) && false === strpos( substr( $data, 0, 1024 ), '%PDF-' ) ) {
			throw new SourceException( __( 'This file is not a PDF.', 'all-in-one-ai-chatbot' ) );
		}

		$pdf = new self( $data );

		if ( $pdf->encrypted() ) {
			throw new SourceException( __( 'This PDF is password-protected or encrypted. Open it and use "Print → Save as PDF" to make an unprotected copy, then upload that.', 'all-in-one-ai-chatbot' ) );
		}

		return $pdf->text();
	}

	/**
	 * Constructor: index every object.
	 *
	 * @param string $data File contents.
	 */
	private function __construct( string $data ) {
		$this->data = $data;
		$this->index_objects();
		$this->unpack_object_streams();
	}

	// ── File structure ──────────────────────────────────────────────────────

	/**
	 * Find every "N G obj … endobj". Later definitions (incremental updates)
	 * replace earlier ones, as in a real reader.
	 */
	private function index_objects(): void {
		if ( ! preg_match_all( '/(?<![0-9])(\d+)\s+\d+\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$count = count( $matches[0] );

		for ( $i = 0; $i < $count; $i++ ) {
			$number = (int) $matches[1][ $i ][0];
			$start  = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$next   = $i + 1 < $count ? $matches[0][ $i + 1 ][1] : strlen( $this->data );

			$body = $this->object_body( $start, $next );

			if ( null !== $body ) {
				$this->objects[ $number ] = $body;
			}
		}
	}

	/**
	 * The body of one object, stream data included.
	 *
	 * @param int $start Offset just after "obj".
	 * @param int $limit Offset of the next object (a bound for the search).
	 */
	private function object_body( int $start, int $limit ): ?string {
		$stream = strpos( $this->data, 'stream', $start );
		$end    = strpos( $this->data, 'endobj', $start );

		if ( false !== $stream && ( false === $end || $stream < $end ) && 'endstream' !== substr( $this->data, $stream - 3, 9 ) ) {
			// Stream: the data may contain anything, including "endobj", so
			// use /Length when it is direct and plausible, else "endstream".
			$dict       = substr( $this->data, $start, $stream - $start );
			$data_start = $stream + 6;

			if ( "\r" === ( $this->data[ $data_start ] ?? '' ) ) {
				++$data_start;
			}
			if ( "\n" === ( $this->data[ $data_start ] ?? '' ) ) {
				++$data_start;
			}

			$length = null;

			if ( preg_match( '#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $m ) ) {
				$candidate = (int) $m[1];
				$after     = ltrim( substr( $this->data, $data_start + $candidate, 20 ) );

				if ( str_starts_with( $after, 'endstream' ) ) {
					$length = $candidate;
				}
			}

			if ( null === $length ) {
				$stop = strpos( $this->data, 'endstream', $data_start );

				if ( false === $stop ) {
					return null;
				}

				$length = $stop - $data_start;

				// The EOL before "endstream" is not part of the data.
				if ( $length > 0 && "\n" === $this->data[ $data_start + $length - 1 ] ) {
					--$length;
				}
				if ( $length > 0 && "\r" === $this->data[ $data_start + $length - 1 ] ) {
					--$length;
				}
			}

			return $dict . "\x00STREAM\x00" . substr( $this->data, $data_start, $length );
		}

		if ( false === $end ) {
			$end = $limit;
		}

		return substr( $this->data, $start, min( $end, $limit ) - $start );
	}

	/**
	 * Objects packed inside compressed object streams (PDF 1.5+).
	 */
	private function unpack_object_streams(): void {
		foreach ( array_keys( $this->objects ) as $number ) {
			$dict = $this->dict( $number );

			if ( ! preg_match( '#/Type\s*/ObjStm\b#', $dict ) ) {
				continue;
			}

			$data  = $this->stream( $number );
			$first = $this->int_value( $dict, 'First' );
			$n     = $this->int_value( $dict, 'N' );

			if ( null === $data || null === $first || null === $n ) {
				continue;
			}

			$header = preg_split( '/\s+/', trim( substr( $data, 0, $first ) ) ) ?: array();
			$pairs  = array();
			$total  = min( count( $header ), 2 * $n );

			for ( $i = 0; $i + 1 < $total; $i += 2 ) {
				$pairs[] = array( (int) $header[ $i ], (int) $header[ $i + 1 ] );
			}

			foreach ( $pairs as $i => [ $obj, $offset ] ) {
				$from = $first + $offset;
				$to   = isset( $pairs[ $i + 1 ] ) ? $first + $pairs[ $i + 1 ][1] : strlen( $data );

				// An object in the plain file body wins (incremental update).
				if ( ! isset( $this->objects[ $obj ] ) ) {
					$this->objects[ $obj ] = substr( $data, $from, $to - $from );
				}
			}
		}
	}

	/**
	 * Whether the document is encrypted.
	 */
	private function encrypted(): bool {
		return 1 === preg_match( '#/Encrypt\s+(\d+\s+\d+\s+R|<<)#', $this->data );
	}

	/**
	 * An object's dictionary text (without stream data).
	 *
	 * @param int $number Object number.
	 */
	private function dict( int $number ): string {
		$body = $this->objects[ $number ] ?? '';
		$pos  = strpos( $body, "\x00STREAM\x00" );

		return false === $pos ? $body : substr( $body, 0, $pos );
	}

	/**
	 * An object's decoded stream, or null.
	 *
	 * @param int $number Object number.
	 */
	private function stream( int $number ): ?string {
		if ( array_key_exists( $number, $this->streams ) ) {
			return $this->streams[ $number ];
		}

		$body = $this->objects[ $number ] ?? '';
		$pos  = strpos( $body, "\x00STREAM\x00" );

		if ( false === $pos ) {
			$this->streams[ $number ] = null;

			return null;
		}

		$dict = substr( $body, 0, $pos );
		$data = substr( $body, $pos + 8 );

		$filters = array();

		if ( preg_match( '#/Filter\s*\[([^\]]*)\]#', $dict, $m ) ) {
			preg_match_all( '#/(\w+)#', $m[1], $names );
			$filters = $names[1];
		} elseif ( preg_match( '#/Filter\s*/(\w+)#', $dict, $m ) ) {
			$filters = array( $m[1] );
		}

		foreach ( $filters as $filter ) {
			$data = self::decode( $filter, $data );

			if ( null === $data ) {
				break;
			}
		}

		$this->streams[ $number ] = $data;

		return $data;
	}

	/**
	 * Apply one stream filter.
	 *
	 * @param string $filter Filter name.
	 * @param string $data   Encoded data.
	 */
	private static function decode( string $filter, string $data ): ?string {
		switch ( $filter ) {
			case 'FlateDecode':
			case 'Fl':
				// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is expected; the fallbacks handle it.
				$out = @gzuncompress( $data, self::MAX_STREAM );
				if ( false === $out ) {
					$out = @gzinflate( substr( $data, 2 ), self::MAX_STREAM );
				}
				if ( false === $out ) {
					$out = @gzinflate( $data, self::MAX_STREAM );
				}
				// phpcs:enable
				return false === $out ? null : $out;

			case 'ASCIIHexDecode':
			case 'AHx':
				$hex = preg_replace( '/[^0-9A-Fa-f]/', '', strstr( $data, '>', true ) ?: $data ) ?? '';
				if ( 1 === strlen( $hex ) % 2 ) {
					$hex .= '0';
				}
				return (string) hex2bin( $hex );

			case 'ASCII85Decode':
			case 'A85':
				return self::ascii85( $data );
		}

		// LZW, DCT (images) and others: not text we can read.
		return null;
	}

	/**
	 * Decode ASCII85.
	 *
	 * @param string $data Encoded.
	 */
	private static function ascii85( string $data ): string {
		$data = preg_replace( '/\s+/', '', $data ) ?? '';
		$data = str_starts_with( $data, '<~' ) ? substr( $data, 2 ) : $data;
		$end  = strpos( $data, '~>' );
		$data = false === $end ? $data : substr( $data, 0, $end );
		$out  = '';
		$len  = strlen( $data );
		$i    = 0;

		while ( $i < $len ) {
			if ( 'z' === $data[ $i ] ) {
				$out .= "\0\0\0\0";
				++$i;
				continue;
			}

			$group = substr( $data, $i, 5 );
			$i    += 5;
			$pad   = 5 - strlen( $group );
			$group = str_pad( $group, 5, 'u' );
			$value = 0;

			for ( $j = 0; $j < 5; $j++ ) {
				$value = $value * 85 + ( ord( $group[ $j ] ) - 33 );
			}

			$out .= substr( pack( 'N', $value & 0xFFFFFFFF ), 0, 4 - $pad );
		}

		return $out;
	}

	/**
	 * A direct integer value in a dictionary.
	 *
	 * @param string $dict Dictionary text.
	 * @param string $key  Key without slash.
	 */
	private function int_value( string $dict, string $key ): ?int {
		if ( preg_match( '#/' . $key . '\s+(\d+)\s+(\d+)\s+R#', $dict, $m ) ) {
			$target = trim( $this->dict( (int) $m[1] ) );

			return is_numeric( $target ) ? (int) $target : null;
		}

		return preg_match( '#/' . $key . '\s+(-?\d+)#', $dict, $m ) ? (int) $m[1] : null;
	}

	/**
	 * Object number a key points to (/Key N 0 R).
	 *
	 * @param string $dict Dictionary text.
	 * @param string $key  Key without slash.
	 */
	private static function ref( string $dict, string $key ): ?int {
		return preg_match( '#/' . $key . '\s+(\d+)\s+\d+\s+R#', $dict, $m ) ? (int) $m[1] : null;
	}

	/**
	 * Every object number referenced in some text.
	 *
	 * @param string $text Text with "N 0 R" references.
	 * @return array<int, int>
	 */
	private static function refs( string $text ): array {
		preg_match_all( '#(\d+)\s+\d+\s+R\b#', $text, $m );

		return array_map( 'intval', $m[1] );
	}

	/**
	 * The balanced "<< … >>" value of a key, or the dictionary of the object
	 * it references.
	 *
	 * @param string $dict Dictionary text.
	 * @param string $key  Key without slash.
	 */
	private function sub_dict( string $dict, string $key ): ?string {
		if ( ! preg_match( '#/' . $key . '\s*(<<|\d+\s+\d+\s+R)#', $dict, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		if ( '<<' !== $m[1][0] ) {
			return $this->dict( (int) $m[1][0] );
		}

		$start = $m[1][1];
		$depth = 0;
		$len   = strlen( $dict );

		for ( $i = $start; $i < $len - 1; $i++ ) {
			$pair = $dict[ $i ] . $dict[ $i + 1 ];

			if ( '<<' === $pair ) {
				++$depth;
				++$i;
			} elseif ( '>>' === $pair ) {
				--$depth;
				++$i;

				if ( 0 === $depth ) {
					return substr( $dict, $start, $i - $start + 1 );
				}
			}
		}

		return null;
	}

	/**
	 * Name => object number pairs in a dictionary (e.g. /F1 5 0 R).
	 *
	 * @param string|null $dict Dictionary.
	 * @return array<string, int>
	 */
	private static function named_refs( ?string $dict ): array {
		if ( null === $dict || ! preg_match_all( '#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $dict, $m, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();

		foreach ( $m as $pair ) {
			$out[ $pair[1] ] = (int) $pair[2];
		}

		return $out;
	}

	// ── Pages ───────────────────────────────────────────────────────────────

	/**
	 * Page objects in reading order, with their (possibly inherited) resources.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function pages(): array {
		$root = null;

		if ( preg_match_all( '#/Root\s+(\d+)\s+\d+\s+R#', $this->data, $m ) ) {
			$root = (int) end( $m[1] );
		}

		$pages = array();

		if ( null !== $root ) {
			$tree = self::ref( $this->dict( $root ), 'Pages' );

			if ( null !== $tree ) {
				$this->walk( $tree, '', $pages, array() );
			}
		}

		// No usable page tree: take every page object in file order.
		if ( array() === $pages ) {
			foreach ( array_keys( $this->objects ) as $number ) {
				$dict = $this->dict( $number );

				if ( preg_match( '#/Type\s*/Page\b(?!s)#', $dict ) ) {
					$pages[] = array( $number, (string) $this->sub_dict( $dict, 'Resources' ) );
				}
			}
		}

		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * Walk the page tree.
	 *
	 * @param int                               $node      Node object.
	 * @param string                            $resources Inherited resources.
	 * @param array<int, array{0: int, 1: string}> $pages  Collected pages.
	 * @param array<int, bool>                  $seen      Loop guard.
	 */
	private function walk( int $node, string $resources, array &$pages, array $seen ): void {
		if ( isset( $seen[ $node ] ) || count( $pages ) >= self::MAX_PAGES ) {
			return;
		}

		$seen[ $node ] = true;
		$dict          = $this->dict( $node );
		$own           = $this->sub_dict( $dict, 'Resources' );
		$resources     = null !== $own ? $own : $resources;

		if ( preg_match( '#/Kids\s*\[([^\]]*)\]#', $dict, $m ) ) {
			foreach ( self::refs( $m[1] ) as $kid ) {
				$this->walk( $kid, $resources, $pages, $seen );
			}
			return;
		}

		if ( preg_match( '#/Type\s*/Page\b(?!s)#', $dict ) || str_contains( $dict, '/Contents' ) ) {
			$pages[] = array( $node, $resources );
		}
	}

	/**
	 * All text, page by page.
	 */
	private function text(): string {
		$out = array();

		foreach ( $this->pages() as [ $page, $resources ] ) {
			$dict    = $this->dict( $page );
			$content = '';

			if ( preg_match( '#/Contents\s*\[([^\]]*)\]#', $dict, $m ) ) {
				$parts = self::refs( $m[1] );
			} else {
				$ref   = self::ref( $dict, 'Contents' );
				$parts = null === $ref ? array() : array( $ref );

				// /Contents may point at an array object.
				if ( null !== $ref && null === $this->stream( $ref ) ) {
					$parts = self::refs( $this->dict( $ref ) );
				}
			}

			foreach ( $parts as $part ) {
				$content .= $this->stream( $part ) . "\n";
			}

			$text = trim( $this->run( $content, $resources, 0 ) );

			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return self::tidy( implode( "\n\n", $out ) );
	}

	// ── Content streams ─────────────────────────────────────────────────────

	/**
	 * Interpret a content stream's text operators.
	 *
	 * Text position is tracked like a real renderer does (text matrix, glyph
	 * widths, kerning), so spaces and line breaks come from actual gaps on
	 * the page rather than from how a generator happened to split its
	 * drawing commands. Marked content with /ActualText (used by Chrome,
	 * Word and others for ligatures and complex scripts such as Bangla, where
	 * glyphs are drawn in visual order) replaces the glyphs it covers.
	 *
	 * @param string $content   Decoded content stream.
	 * @param string $resources Resource dictionary.
	 * @param int    $depth     XObject nesting depth.
	 */
	private function run( string $content, string $resources, int $depth ): string {
		$fonts    = self::named_refs( $this->sub_dict( $resources, 'Font' ) );
		$xobjects = self::named_refs( $this->sub_dict( $resources, 'XObject' ) );

		$this->out  = '';
		$this->last = null;

		$font     = null;
		$size     = 10.0;
		$leading  = 0.0;
		$char_sp  = 0.0;
		$word_sp  = 0.0;
		$scale    = 1.0;
		$tm       = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
		$lm       = $tm;
		$marks    = array();
		$operands = array();

		$move = static function ( array $m, float $tx, float $ty ): array {
			return array( $m[0], $m[1], $m[2], $m[3], $tx * $m[0] + $ty * $m[2] + $m[4], $tx * $m[1] + $ty * $m[3] + $m[5] );
		};

		foreach ( self::tokens( $content ) as [ $type, $value ] ) {
			if ( 'op' !== $type ) {
				$operands[] = array( $type, $value );
				continue;
			}

			$num = static fn( int $i ): float => (float) ( $operands[ $i ][1] ?? 0 );

			switch ( $value ) {
				case 'BT':
					$tm = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
					$lm = $tm;
					break;

				case 'Tf':
					$name = (string) ( $operands[0][1] ?? '' );
					$size = $num( 1 ) ?: 10.0;
					$font = isset( $fonts[ $name ] ) ? $this->font( $fonts[ $name ] ) : null;
					break;

				case 'Tc':
					$char_sp = $num( 0 );
					break;

				case 'Tw':
					$word_sp = $num( 0 );
					break;

				case 'Tz':
					$scale = $num( 0 ) / 100;
					break;

				case 'TL':
					$leading = $num( 0 );
					break;

				case 'Td':
					$lm = $move( $lm, $num( 0 ), $num( 1 ) );
					$tm = $lm;
					break;

				case 'TD':
					$leading = -$num( 1 );
					$lm      = $move( $lm, $num( 0 ), $num( 1 ) );
					$tm      = $lm;
					break;

				case 'Tm':
					$tm = array( $num( 0 ), $num( 1 ), $num( 2 ), $num( 3 ), $num( 4 ), $num( 5 ) );
					$lm = $tm;
					break;

				case 'T*':
					$lm = $move( $lm, 0, -$leading );
					$tm = $lm;
					break;

				case 'Tj':
				case "'":
				case '"':
					if ( "'" === $value || '"' === $value ) {
						if ( '"' === $value ) {
							$word_sp = $num( 0 );
							$char_sp = $num( 1 );
						}
						$lm = $move( $lm, 0, -$leading );
						$tm = $lm;
					}
					$string = end( $operands );
					if ( false !== $string && 'str' === $string[0] ) {
						$tm = $this->show_at( $tm, (string) $string[1], $font, $size, $char_sp, $word_sp, $scale, $marks );
					}
					break;

				case 'TJ':
					$array = end( $operands );
					if ( false !== $array && 'array' === $array[0] ) {
						foreach ( $array[1] as [ $item_type, $item ] ) {
							if ( 'str' === $item_type ) {
								$tm = $this->show_at( $tm, (string) $item, $font, $size, $char_sp, $word_sp, $scale, $marks );
							} elseif ( 'num' === $item_type ) {
								$tm = $move( $tm, - (float) $item / 1000 * $size * $scale, 0 );
							}
						}
					}
					break;

				case 'BMC':
					$marks[] = array(
						'text'  => null,
						'shown' => false,
					);
					break;

				case 'BDC':
					$props   = (string) ( ( end( $operands ) ?: array( '', '' ) )[1] );
					$marks[] = array(
						'text'  => self::actual_text( $props ),
						'shown' => false,
					);
					break;

				case 'EMC':
					array_pop( $marks );
					break;

				case 'Do':
					$name = (string) ( $operands[0][1] ?? '' );
					if ( $depth < 3 && isset( $xobjects[ $name ] ) ) {
						$number = $xobjects[ $name ];
						$dict   = $this->dict( $number );

						if ( preg_match( '#/Subtype\s*/Form\b#', $dict ) ) {
							$own   = $this->sub_dict( $dict, 'Resources' );
							$saved = array( $this->out, $this->last );
							$inner = $this->run( (string) $this->stream( $number ), null !== $own ? $own : $resources, $depth + 1 );

							[ $this->out, $this->last ] = $saved;
							$this->out                 .= "\n" . $inner . "\n";
							$this->last                 = null;
						}
					}
					break;
			}

			$operands = array();
		}

		return $this->out;
	}

	/**
	 * Text assembled by run().
	 */
	private string $out = '';

	/**
	 * Where the previous glyphs ended: [x, y, font size on the page].
	 *
	 * @var array{0: float, 1: float, 2: float}|null
	 */
	private ?array $last = null;

	/**
	 * Show a string at the text matrix: add its text (with a space or line
	 * break when it is apart from the previous text) and return the matrix
	 * advanced by its width.
	 *
	 * @param array<int, float>                         $tm      Text matrix.
	 * @param string                                    $bytes   Raw string.
	 * @param array<string, mixed>|null                 $font    Current font.
	 * @param float                                     $size    Font size.
	 * @param float                                     $char_sp Character spacing.
	 * @param float                                     $word_sp Word spacing.
	 * @param float                                     $scale   Horizontal scaling.
	 * @param array<int, array{text: ?string, shown: bool}> $marks Open marked-content spans.
	 * @return array<int, float>
	 */
	private function show_at( array $tm, string $bytes, ?array $font, float $size, float $char_sp, float $word_sp, float $scale, array &$marks ): array {
		$page_size = abs( $size ) * max( 0.01, sqrt( $tm[2] * $tm[2] + $tm[3] * $tm[3] ) );
		$x         = $tm[4];
		$y         = $tm[5];

		// Separator from the previous text, by position.
		if ( null !== $this->last && '' !== $this->out ) {
			[ $last_x, $last_y, $last_size ] = $this->last;
			$em                              = max( $page_size, $last_size );
			$dy                              = abs( $y - $last_y );

			if ( $dy > $em * 0.5 ) {
				$this->out = rtrim( $this->out, ' ' ) . ( $dy > $em * 1.9 ? "\n\n" : "\n" );
			} elseif ( abs( $x - $last_x ) > $em * 0.2 && ! ctype_space( substr( $this->out, -1 ) ) ) {
				$this->out .= ' ';
			}
		}

		// Inside an /ActualText span, the span's text stands for its glyphs.
		$replaced = false;

		foreach ( $marks as $i => $mark ) {
			if ( null !== $mark['text'] ) {
				if ( ! $mark['shown'] ) {
					$this->out           .= $mark['text'];
					$marks[ $i ]['shown'] = true;
				}
				$replaced = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$this->out .= $this->show( $bytes, $font );
		}

		// Advance by the glyph widths.
		$step    = null === $font ? 1 : (int) $font['bytes'];
		$advance = 0.0;
		$len     = strlen( $bytes );

		for ( $i = 0; $i + $step <= $len; $i += $step ) {
			$code  = $step > 1 ? (int) hexdec( bin2hex( substr( $bytes, $i, $step ) ) ) : ord( $bytes[ $i ] );
			$width = null === $font ? 500 : (float) ( $font['widths'][ $code ] ?? $font['dw'] );

			$advance += ( $width / 1000 * $size + $char_sp + ( 1 === $step && 32 === $code ? $word_sp : 0 ) ) * $scale;
		}

		$tm = array( $tm[0], $tm[1], $tm[2], $tm[3], $advance * $tm[0] + $tm[4], $advance * $tm[1] + $tm[5] );

		$this->last = array( $tm[4], $tm[5], $page_size );

		return $tm;
	}

	/**
	 * The /ActualText of a marked-content property list, or null.
	 *
	 * @param string $props Inline dictionary text.
	 */
	private static function actual_text( string $props ): ?string {
		if ( ! preg_match( '#/ActualText\s*(<[0-9A-Fa-f\s]*>|\()#', $props, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		if ( '(' === $m[1][0] ) {
			[ $bytes ] = self::literal( $props, $m[1][1] );
		} else {
			$hex   = preg_replace( '/[^0-9A-Fa-f]/', '', $m[1][0] ) ?? '';
			$bytes = (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex );
		}

		if ( str_starts_with( $bytes, "\xFE\xFF" ) ) {
			return (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
		}

		return (string) @iconv( 'Windows-1252', 'UTF-8//IGNORE', $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- PDFDocEncoding is close to cp1252 for text.
	}

	/**
	 * Split a content stream into tokens: [type, value].
	 *
	 * Types: num, name, str (raw bytes), array (list of tokens), op.
	 *
	 * @param string $s Content stream.
	 * @return \Generator<int, array{0: string, 1: mixed}>
	 */
	private static function tokens( string $s ): \Generator {
		$len   = strlen( $s );
		$i     = 0;
		$stack = array();

		while ( $i < $len ) {
			$c = $s[ $i ];

			if ( ctype_space( $c ) || "\0" === $c ) {
				++$i;
				continue;
			}

			$token = null;

			if ( '%' === $c ) {
				$eol = strcspn( $s, "\r\n", $i );
				$i  += $eol;
				continue;
			} elseif ( '(' === $c ) {
				[ $value, $i ] = self::literal( $s, $i );
				$token         = array( 'str', $value );
			} elseif ( '<' === $c && '<' === ( $s[ $i + 1 ] ?? '' ) ) {
				// Inline dictionary (marked-content properties such as /ActualText).
				$dict_start = $i;
				$depth      = 0;
				while ( $i < $len - 1 ) {
					if ( '<' === $s[ $i ] && '<' === $s[ $i + 1 ] ) {
						++$depth;
						$i += 2;
					} elseif ( '>' === $s[ $i ] && '>' === $s[ $i + 1 ] ) {
						--$depth;
						$i += 2;
						if ( 0 === $depth ) {
							break;
						}
					} elseif ( '<' === $s[ $i ] ) {
						// A hex string: its ">" is not a dictionary end.
						$close = strpos( $s, '>', $i + 1 );
						$i     = false === $close ? $len : $close + 1;
					} elseif ( '(' === $s[ $i ] ) {
						$i = self::literal( $s, $i )[1];
					} else {
						++$i;
					}
				}
				$token = array( 'dict', substr( $s, $dict_start, $i - $dict_start ) );
			} elseif ( '<' === $c ) {
				$end   = strpos( $s, '>', $i );
				$end   = false === $end ? $len : $end;
				$hex   = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $s, $i + 1, $end - $i - 1 ) ) ?? '';
				$hex  .= 1 === strlen( $hex ) % 2 ? '0' : '';
				$token = array( 'str', (string) hex2bin( $hex ) );
				$i     = $end + 1;
			} elseif ( '[' === $c ) {
				$stack[] = array();
				++$i;
				continue;
			} elseif ( ']' === $c ) {
				++$i;
				$items = array_pop( $stack ) ?? array();
				$token = array( 'array', $items );
			} elseif ( '/' === $c ) {
				$n     = strcspn( $s, " \t\r\n\f\0/[]<>(){}%", $i + 1 );
				$token = array( 'name', substr( $s, $i + 1, $n ) );
				$i    += $n + 1;
			} elseif ( ctype_digit( $c ) || '-' === $c || '+' === $c || '.' === $c ) {
				$n     = strspn( $s, '0123456789.-+', $i );
				$token = array( 'num', substr( $s, $i, $n ) );
				$i    += $n;
			} else {
				$n  = strcspn( $s, " \t\r\n\f\0/[]<>(){}%", $i );
				$n  = max( 1, $n );
				$op = substr( $s, $i, $n );
				$i += $n;

				if ( 'BI' === $op ) {
					// Inline image: skip to "EI" after the binary data.
					$id = strpos( $s, 'ID', $i );
					$ei = false === $id ? false : strpos( $s, 'EI', $id + 3 );
					while ( false !== $ei && ! ( ctype_space( $s[ $ei - 1 ] ?? ' ' ) && ( ! isset( $s[ $ei + 2 ] ) || ctype_space( $s[ $ei + 2 ] ) ) ) ) {
						$ei = strpos( $s, 'EI', $ei + 2 );
					}
					$i = false === $ei ? $len : $ei + 2;
					continue;
				}

				if ( array() !== $stack ) {
					// Operators cannot appear inside arrays; treat as junk.
					continue;
				}

				yield array( 'op', $op );
				continue;
			}

			if ( array() !== $stack ) {
				$stack[ count( $stack ) - 1 ][] = $token;
			} else {
				yield $token;
			}
		}
	}

	/**
	 * Read a literal string "( … )" with nesting and escapes.
	 *
	 * @param string $s Content.
	 * @param int    $i Offset of "(".
	 * @return array{0: string, 1: int} Bytes, offset after ")".
	 */
	private static function literal( string $s, int $i ): array {
		$len   = strlen( $s );
		$depth = 0;
		$out   = '';

		for ( ; $i < $len; $i++ ) {
			$c = $s[ $i ];

			if ( '\\' === $c ) {
				$next = $s[ ++$i ] ?? '';

				if ( ctype_digit( $next ) && $next < '8' ) {
					$oct = $next;
					while ( ! isset( $oct[2] ) && isset( $s[ $i + 1 ] ) && ctype_digit( $s[ $i + 1 ] ) && $s[ $i + 1 ] < '8' ) {
						$oct .= $s[ ++$i ];
					}
					$out .= chr( octdec( $oct ) & 0xFF );
					continue;
				}

				$map = array(
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\f",
				);

				if ( "\r" === $next ) {
					if ( "\n" === ( $s[ $i + 1 ] ?? '' ) ) {
						++$i;
					}
					continue;
				}

				if ( "\n" !== $next ) {
					$out .= $map[ $next ] ?? $next;
				}
				continue;
			}

			if ( '(' === $c ) {
				if ( $depth++ > 0 ) {
					$out .= $c;
				}
				continue;
			}

			if ( ')' === $c ) {
				--$depth;

				if ( 0 === $depth ) {
					return array( $out, $i + 1 );
				}
				$out .= $c;
				continue;
			}

			$out .= $c;
		}

		return array( $out, $len );
	}

	// ── Fonts ───────────────────────────────────────────────────────────────

	/**
	 * How to turn a font's byte codes into Unicode.
	 *
	 * @param int $number Font object.
	 * @return array{bytes: int, map: array<int, string>, simple: array<int, string>|null, unknown: bool}
	 */
	private function font( int $number ): array {
		if ( isset( $this->fonts[ $number ] ) ) {
			return $this->fonts[ $number ];
		}

		$dict  = $this->dict( $number );
		$type0 = 1 === preg_match( '#/Subtype\s*/Type0\b#', $dict );
		$font  = array(
			'bytes'   => $type0 ? 2 : 1,
			'map'     => array(),
			'simple'  => null,
			'unknown' => false,
			'widths'  => array(),
			'dw'      => $type0 ? 1000.0 : 500.0,
		);

		if ( $type0 ) {
			$descendants = $this->array_body( $dict, 'DescendantFonts' );
			$cid         = null !== $descendants ? ( self::refs( $descendants )[0] ?? null ) : null;

			if ( null !== $cid ) {
				$cid_dict       = $this->dict( $cid );
				$font['dw']     = (float) ( $this->int_value( $cid_dict, 'DW' ) ?? 1000 );
				$font['widths'] = self::cid_widths( (string) $this->array_body( $cid_dict, 'W' ) );
			}
		} else {
			$first  = (int) ( $this->int_value( $dict, 'FirstChar' ) ?? 0 );
			$widths = $this->array_body( $dict, 'Widths' );

			if ( null !== $widths && preg_match_all( '/-?\d+(?:\.\d+)?/', $widths, $m ) ) {
				foreach ( $m[0] as $i => $w ) {
					$font['widths'][ $first + $i ] = (float) $w;
				}
			}
		}
		$unicode = self::ref( $dict, 'ToUnicode' );

		if ( null !== $unicode && null !== $this->stream( $unicode ) ) {
			[ $font['map'], $bytes ] = self::cmap( (string) $this->stream( $unicode ) );
			$font['bytes']           = $bytes ?? $font['bytes'];
		}

		if ( ! $type0 ) {
			$font['simple'] = $this->simple_encoding( $dict );
		} elseif ( array() === $font['map'] ) {
			// A CID font without a Unicode map: its codes are glyph ids we
			// cannot name. Its text is skipped rather than shown as junk.
			$font['unknown'] = true;
		}

		$this->fonts[ $number ] = $font;

		return $font;
	}

	/**
	 * The inside of an array value ("/Key [ … ]" or "/Key N 0 R" pointing at
	 * an array object), nested brackets included.
	 *
	 * @param string $dict Dictionary.
	 * @param string $key  Key without slash.
	 */
	private function array_body( string $dict, string $key ): ?string {
		if ( ! preg_match( '#/' . $key . '\s*(\[|(\d+)\s+\d+\s+R)#', $dict, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		if ( '[' !== $m[1][0] ) {
			$body = trim( $this->dict( (int) $m[2][0] ) );

			return str_starts_with( $body, '[' ) ? substr( $body, 1, (int) strrpos( $body, ']' ) - 1 ) : null;
		}

		$depth = 0;
		$len   = strlen( $dict );

		for ( $i = $m[1][1]; $i < $len; $i++ ) {
			if ( '[' === $dict[ $i ] ) {
				++$depth;
			} elseif ( ']' === $dict[ $i ] && 0 === --$depth ) {
				return substr( $dict, $m[1][1] + 1, $i - $m[1][1] - 1 );
			}
		}

		return null;
	}

	/**
	 * Glyph widths of a CID font from its /W array:
	 * "c [w1 w2 …]" (consecutive codes) or "cfirst clast w" (a range).
	 *
	 * @param string $w Inside of the /W array.
	 * @return array<int, float>
	 */
	private static function cid_widths( string $w ): array {
		preg_match_all( '/\[|\]|-?\d+(?:\.\d+)?/', $w, $m );

		$tokens = $m[0];
		$out    = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count && ! isset( $out[70000] ); ) { // Cap on huge width tables.
			if ( ! is_numeric( $tokens[ $i ] ) ) {
				++$i;
				continue;
			}

			$first = (int) $tokens[ $i ];

			if ( '[' === ( $tokens[ $i + 1 ] ?? '' ) ) {
				$i   += 2;
				$code = $first;
				while ( $i < $count && ']' !== $tokens[ $i ] ) {
					$out[ $code++ ] = (float) $tokens[ $i++ ];
				}
				++$i;
				continue;
			}

			if ( isset( $tokens[ $i + 2 ] ) && is_numeric( $tokens[ $i + 1 ] ) && is_numeric( $tokens[ $i + 2 ] ) ) {
				$last  = min( (int) $tokens[ $i + 1 ], $first + 65535 );
				$width = (float) $tokens[ $i + 2 ];
				for ( $code = $first; $code <= $last; $code++ ) {
					$out[ $code ] = $width;
				}
				$i += 3;
				continue;
			}

			++$i;
		}

		return $out;
	}

	/**
	 * Parse a ToUnicode CMap.
	 *
	 * @param string $cmap CMap program.
	 * @return array{0: array<int, string>, 1: int|null} Code => UTF-8, code length in bytes.
	 */
	private static function cmap( string $cmap ): array {
		$map   = array();
		$bytes = null;

		if ( preg_match( '/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m ) ) {
			$bytes = max( 1, intdiv( strlen( $m[1] ), 2 ) );
		}

		if ( preg_match_all( '/beginbfchar(.*?)endbfchar/s', $cmap, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER );
				foreach ( $pairs as $pair ) {
					$map[ (int) hexdec( $pair[1] ) ] = self::utf16( $pair[2] );
					$bytes                         ??= max( 1, intdiv( strlen( $pair[1] ), 2 ) );
				}
			}
		}

		if ( preg_match_all( '/beginbfrange(.*?)endbfrange/s', $cmap, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				preg_match_all( '/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER );

				foreach ( $ranges as $range ) {
					$from    = (int) hexdec( $range[1] );
					$to      = min( (int) hexdec( $range[2] ), $from + 65535 );
					$bytes ??= max( 1, intdiv( strlen( $range[1] ), 2 ) );

					if ( '[' === $range[3][0] ) {
						preg_match_all( '/<([0-9A-Fa-f]*)>/', $range[3], $items );
						foreach ( $items[1] as $k => $hex ) {
							if ( $from + $k <= $to ) {
								$map[ $from + $k ] = self::utf16( $hex );
							}
						}
						continue;
					}

					$start = trim( $range[3], '<>' );

					for ( $code = $from; $code <= $to; $code++ ) {
						// The last byte counts up across the range.
						$offset       = $code - $from;
						$prefix       = substr( $start, 0, -4 );
						$last         = (int) hexdec( substr( $start, -4 ) ) + $offset;
						$map[ $code ] = self::utf16( $prefix . sprintf( '%04X', $last & 0xFFFF ) );
					}
				}
			}
		}

		return array( $map, $bytes );
	}

	/**
	 * UTF-16BE hex to UTF-8.
	 *
	 * @param string $hex Hex digits.
	 */
	private static function utf16( string $hex ): string {
		if ( '' === $hex ) {
			return '';
		}

		$bin = (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex );

		if ( 1 === strlen( $bin ) ) {
			return mb_chr( ord( $bin ), 'UTF-8' ) ?: '';
		}

		return (string) mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' );
	}

	/**
	 * Byte => character table for a simple (single-byte) font.
	 *
	 * @param string $dict Font dictionary.
	 * @return array<int, string>
	 */
	private function simple_encoding( string $dict ): array {
		$base = 'WinAnsiEncoding';
		$diff = '';

		if ( preg_match( '#/Encoding\s*/(\w+)#', $dict, $m ) ) {
			$base = $m[1];
		} else {
			$enc = $this->sub_dict( $dict, 'Encoding' );

			if ( null !== $enc ) {
				if ( preg_match( '#/BaseEncoding\s*/(\w+)#', $enc, $m ) ) {
					$base = $m[1];
				}
				if ( preg_match( '#/Differences\s*\[([^\]]*)\]#', $enc, $m ) ) {
					$diff = $m[1];
				}
			}
		}

		$charset = 'MacRomanEncoding' === $base ? 'MACINTOSH' : 'Windows-1252';
		$table   = array();

		for ( $b = 32; $b < 256; $b++ ) {
			$char = (string) @iconv( $charset, 'UTF-8//IGNORE', chr( $b ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unmapped bytes are expected.
			if ( '' !== $char ) {
				$table[ $b ] = $char;
			}
		}

		if ( '' !== $diff && preg_match_all( '#(\d+)|/([^\s/\[\]]+)#', $diff, $items, PREG_SET_ORDER ) ) {
			$code = 0;

			foreach ( $items as $item ) {
				if ( '' !== $item[1] ) {
					$code = (int) $item[1];
					continue;
				}

				$char = self::glyph( $item[2] );

				if ( null !== $char ) {
					$table[ $code ] = $char;
				}

				++$code;
			}
		}

		return $table;
	}

	/**
	 * A glyph name to its character (the common cases of the Adobe Glyph List).
	 *
	 * @param string $name Glyph name.
	 */
	private static function glyph( string $name ): ?string {
		static $names = array(
			'space'         => ' ',
			'exclam'        => '!',
			'quotedbl'      => '"',
			'numbersign'    => '#',
			'dollar'        => '$',
			'percent'       => '%',
			'ampersand'     => '&',
			'quotesingle'   => "'",
			'quoteright'    => '’',
			'quoteleft'     => '‘',
			'quotedblleft'  => '“',
			'quotedblright' => '”',
			'parenleft'     => '(',
			'parenright'    => ')',
			'asterisk'      => '*',
			'plus'          => '+',
			'comma'         => ',',
			'hyphen'        => '-',
			'minus'         => '−',
			'endash'        => '–',
			'emdash'        => '—',
			'period'        => '.',
			'slash'         => '/',
			'colon'         => ':',
			'semicolon'     => ';',
			'less'          => '<',
			'equal'         => '=',
			'greater'       => '>',
			'question'      => '?',
			'at'            => '@',
			'bracketleft'   => '[',
			'backslash'     => '\\',
			'bracketright'  => ']',
			'underscore'    => '_',
			'bullet'        => '•',
			'ellipsis'      => '…',
			'fi'            => 'fi',
			'fl'            => 'fl',
			'ff'            => 'ff',
			'ffi'           => 'ffi',
			'ffl'           => 'ffl',
			'zero'          => '0',
			'one'           => '1',
			'two'           => '2',
			'three'         => '3',
			'four'          => '4',
			'five'          => '5',
			'six'           => '6',
			'seven'         => '7',
			'eight'         => '8',
			'nine'          => '9',
			'nbspace'       => ' ',
			'copyright'     => '©',
			'registered'    => '®',
			'trademark'     => '™',
			'degree'        => '°',
			'Euro'          => '€',
			'sterling'      => '£',
		);

		if ( isset( $names[ $name ] ) ) {
			return $names[ $name ];
		}

		if ( 1 === strlen( $name ) && ctype_alpha( $name ) ) {
			return $name;
		}

		if ( preg_match( '/^uni([0-9A-Fa-f]{4})/', $name, $m ) || preg_match( '/^u([0-9A-Fa-f]{4,6})$/', $name, $m ) ) {
			return mb_chr( (int) hexdec( $m[1] ), 'UTF-8' ) ?: null;
		}

		return null;
	}

	/**
	 * Decode the bytes of a text-showing operator.
	 *
	 * @param string                    $bytes Raw string.
	 * @param array<string, mixed>|null $font  Current font.
	 */
	private function show( string $bytes, ?array $font ): string {
		if ( null === $font ) {
			// No known font: assume plain single-byte text.
			return (string) @iconv( 'Windows-1252', 'UTF-8//IGNORE', $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		}

		if ( $font['unknown'] ) {
			return '';
		}

		$out  = '';
		$step = (int) $font['bytes'];
		$len  = strlen( $bytes );

		for ( $i = 0; $i + $step <= $len; $i += $step ) {
			$code = $step > 1 ? (int) hexdec( bin2hex( substr( $bytes, $i, $step ) ) ) : ord( $bytes[ $i ] );

			if ( isset( $font['map'][ $code ] ) ) {
				$out .= $font['map'][ $code ];
			} elseif ( null !== $font['simple'] && isset( $font['simple'][ $code ] ) ) {
				$out .= $font['simple'][ $code ];
			}
		}

		return $out;
	}

	/**
	 * Clean up the assembled text.
	 *
	 * @param string $text Raw text.
	 */
	private static function tidy( string $text ): string {
		$text = str_replace( array( "\r\n", "\r", "\u{00A0}", "\u{FEFF}", "\u{00AD}" ), array( "\n", "\n", ' ', '', '' ), $text );
		$text = preg_replace( '/[^\P{C}\n\t]/u', '', $text ) ?? $text;
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/ *\n */', "\n", $text ) ?? $text;
		// Words hyphenated across a line break: "infor-\nmation" -> "information".
		$text = preg_replace( '/(\p{Ll})-\n(\p{Ll})/u', '$1$2', $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
