<?php
// phpcs:disable
/**
 * Builds small PDFs in shapes real generators produce, for the extractor
 * tests: a compressed object stream (PDF 1.5, like Word and Google Docs),
 * a simple font with WinAnsi + Differences and TJ kerning (like older
 * generators), ASCII85 streams, an encrypted file and a "scanned" one.
 */

final class SaiPdf {
	/**
	 * Assemble a PDF from object bodies (1-based). Stream objects are given
	 * as [dict, data].
	 *
	 * @param array<int, string|array{0: string, 1: string}> $objects Objects.
	 * @param string                                         $trailer Extra trailer entries.
	 */
	public static function build( array $objects, string $trailer = '' ): string {
		$pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		foreach ( $objects as $n => $body ) {
			$offsets[ $n ] = strlen( $pdf );
			if ( is_array( $body ) ) {
				$body = $body[0] . "\nstream\n" . $body[1] . "\nendstream";
			}
			$pdf .= "$n 0 obj\n$body\nendobj\n";
		}

		$xref = strlen( $pdf );
		$max  = max( array_keys( $objects ) );
		$pdf .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";

		for ( $i = 1; $i <= $max; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ?? 0 );
		}

		return $pdf . "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R $trailer >>\nstartxref\n$xref\n%%EOF\n";
	}

	/** Flate stream object. */
	public static function flate( string $data, string $extra = '' ): array {
		$z = gzcompress( $data );
		return array( '<< /Length ' . strlen( $z ) . " /Filter /FlateDecode $extra >>", $z );
	}

	/** Old-style: Helvetica with WinAnsi + Differences, TJ kerning, two pages. */
	public static function simple(): string {
		$page1 = "BT /F1 12 Tf 72 720 Td (Delivery policy) Tj 0 -30 Td [(W) 120 (e deliver to all 64 districts. Caf) 30 (\\351 orders ship daily.)] TJ T* 0 -15 Td (Refunds take 7 days) Tj ( \\(bKash or card\\).) Tj ET";
		$page2 = "BT /F1 12 Tf 14 TL 72 720 Td (Page two: \\001 bullet) Tj T* (Cash on delivery is available.) Tj ET";

		return self::build(
			array(
				1 => '<< /Type /Catalog /Pages 2 0 R >>',
				2 => '<< /Type /Pages /Kids [3 0 R 6 0 R] /Count 2 /Resources << /Font << /F1 5 0 R >> >> >>',
				3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
				4 => self::flate( $page1 ),
				5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [1 /bullet] >> >>',
				6 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents [7 0 R] >>',
				7 => array( '<< /Length 999 /Filter /ASCII85Decode >>', self::a85( $page2 ) . '~>' ),
			)
		);
	}

	/** PDF 1.5: page, font and CMap dictionaries inside a compressed object stream. */
	public static function objstm(): string {
		// A two-byte CID font whose ToUnicode maps codes to Bangla and Latin.
		$cmap = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
			. "3 beginbfchar <0001> <09B8> <0002> <09BE> <0003> <0020> endbfchar\n"
			. "1 beginbfrange <0010> <0012> <0041> endbfrange\n"
			. "1 beginbfrange <0020> <0021> [<00660069> <09A8>] endbfrange\n"
			. "endcmap CMapName currentdict /CMap defineresource pop end end";

		$content = "BT /F1 10 Tf 50 700 Td <000100020003> Tj <0010001100120003> Tj <00200021> Tj ET\n"
			. "q 1 0 0 1 0 0 cm /Fm1 Do Q";

		$form = "BT /F1 10 Tf 50 600 Td <0010> Tj ET";

		$inner = array(
			3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 7 0 R >> /XObject << /Fm1 10 0 R >> >> /Contents 4 0 R >>',
			7 => '<< /Type /Font /Subtype /Type0 /BaseFont /Noto /Encoding /Identity-H /DescendantFonts [8 0 R] /ToUnicode 5 0 R >>',
			8 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Noto /DW 500 /W [1 [600 300] 3 3 250] >>',
		);

		$header = '';
		$body   = '';

		foreach ( $inner as $n => $obj ) {
			$header .= $n . ' ' . strlen( $body ) . ' ';
			$body   .= $obj . "\n";
		}

		return self::build(
			array(
				1  => '<< /Type /Catalog /Pages 2 0 R >>',
				2  => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				4  => self::flate( $content ),
				5  => self::flate( $cmap ),
				6  => self::flate( $header . $body, '/Type /ObjStm /N ' . count( $inner ) . ' /First ' . strlen( $header ) ),
				10 => self::flate( $form, '/Type /XObject /Subtype /Form /BBox [0 0 100 100]' ),
			)
		);
	}

	/** Encrypted (the /Encrypt entry is what matters to the extractor). */
	public static function encrypted(): string {
		return self::build(
			array(
				1 => '<< /Type /Catalog /Pages 2 0 R >>',
				2 => '<< /Type /Pages /Kids [] /Count 0 >>',
				3 => '<< /Filter /Standard /V 2 /R 3 /O <00> /U <00> /P -4 >>',
			),
			'/Encrypt 3 0 R'
		);
	}

	/** A "scanned" page: only an image, no text. */
	public static function scanned(): string {
		return self::build(
			array(
				1 => '<< /Type /Catalog /Pages 2 0 R >>',
				2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
				4 => self::flate( "q 612 0 0 792 0 0 cm BI /W 2 /H 1 /CS /G /BPC 8 ID \x00\xFF EI Q" ),
			)
		);
	}

	private static function a85( string $data ): string {
		$out = '';
		foreach ( str_split( $data, 4 ) as $chunk ) {
			$pad   = 4 - strlen( $chunk );
			$value = unpack( 'N', str_pad( $chunk, 4, "\0" ) )[1];
			if ( 0 === $value && 0 === $pad ) {
				$out .= 'z';
				continue;
			}
			$digits = '';
			for ( $i = 0; $i < 5; $i++ ) {
				$digits = chr( $value % 85 + 33 ) . $digits;
				$value  = intdiv( $value, 85 );
			}
			$out .= substr( $digits, 0, 5 - $pad );
		}
		return $out;
	}
}
