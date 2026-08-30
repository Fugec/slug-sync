<?php
/**
 * Optional title transformations used by the Free slug workflow.
 *
 * These helpers are deliberately independent of WordPress. A run calls them
 * after add-on filters and before sanitize_title(), so they affect only the
 * proposed URL slug. The stored title and WooCommerce SKU are never changed.
 *
 * @package Slug_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Slug_Sync_Transforms' ) ) {
	return;
}

/**
 * Pure string transformations for source titles.
 */
class Slug_Sync_Transforms {

	/**
	 * Separators left stranded when a value is removed, as a PCRE class.
	 *
	 * Deliberately not a byte list for trim(). trim() takes a set of bytes,
	 * not characters, so a list containing the en and em dash also contains
	 * their individual bytes -- 0xE2, 0x80, 0x93, 0x94 -- and those bytes end
	 * perfectly ordinary letters too. Cyrillic "р" is 0xD1 0x80, so trimming
	 * its 0x80 left a dangling 0xD1 and invalid UTF-8: "товар-12882" with SKU
	 * "12882" came back as "това" plus a broken byte.
	 */
	const SEPARATOR_CLASS = '\s\-\x{2013}\x{2014}|\/,:;';

	/** Punctuation trimmed while counting meaningful words. */
	const TRIM = " \t\n\r\0\x0B.,;:!?()[]{}\"'";

	/**
	 * Transliterate text to Latin characters.
	 *
	 * The bundled map runs first and owns Cyrillic and Greek, so those two
	 * scripts produce the same slug on every supported host. intl is then
	 * offered whatever the map could not resolve -- CJK, Arabic, Hebrew and
	 * the rest -- which is a bonus where the extension exists rather than a
	 * different answer for the same title.
	 *
	 * Running intl first, as this once did, made the host decide the URL:
	 * "Φίλτρου" became "Philtrou" with the extension and "Filtroy" without.
	 * ICU also reaches past transliteration on Greek -- it renders "Ευρώπη"
	 * as the English "Europe" and "Θεσσαλονίκη" with ancient-Greek eta -- and
	 * this plugin promises to write the same sounds, not to translate.
	 *
	 * @param mixed $text Source text.
	 * @return string
	 */
	public static function transliterate( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		$out = self::transliterate_fallback( $text );

		// Nothing outside ASCII survived, so the map answered in full and
		// there is nothing for intl to add.
		if ( ! preg_match( '/[^\x00-\x7F]/', $out ) ) {
			return $out;
		}

		if ( class_exists( '\\Transliterator' ) ) {
			$intl = \Transliterator::create( 'Any-Latin; Latin-ASCII' );

			if ( $intl ) {
				$latin = $intl->transliterate( $out );

				if ( is_string( $latin ) && '' !== $latin ) {
					// ICU represents Arabic ayn/hamza and similar apostrophe-like
					// sounds with Unicode modifier letters. WordPress percent-encodes
					// those marks, leaving an otherwise Latin slug such as
					// "makynt-qhwt-%ca%bfrbyt". They do not add a useful URL word,
					// so remove them just as sanitize_title() removes an ASCII
					// apostrophe.
					$latin = str_replace( array( 'ʻ', 'ʼ', 'ʾ', 'ʿ' ), '', $latin );

					return $latin;
				}
			}
		}

		return $out;
	}

	/**
	 * Portable Cyrillic and Greek transliteration.
	 *
	 * Public so exact fallback behaviour can be tested even on a machine where
	 * ext-intl is installed.
	 *
	 * @param mixed $text Source text.
	 * @return string
	 */
	public static function transliterate_fallback( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		return strtr( self::greek_digraphs( $text ), self::transliteration_map() );
	}

	/**
	 * Greek vowel digraphs, which a per-character map cannot see.
	 *
	 * ELOT 743 -- the standard behind Greek passports and road signs -- reads
	 * these as pairs. "ου" is "ou", never "oy". "αυ", "ευ" and "ηυ" end in a
	 * "v" before a vowel or a voiced consonant and an "f" everywhere else, so
	 * "Ναύπλιο" is "Nafplio" and "Ευρώπη" is "Evropi". Character by character
	 * those came out "Nayplio" and "Eyropi".
	 *
	 * Runs before the map, so the map never sees the upsilon of a pair.
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	private static function greek_digraphs( $text ) {
		// Unconditional, and first: it removes upsilons the pairs below would
		// otherwise re-read.
		$text = strtr(
			$text,
			array(
				'ου' => 'ou',
				'ού' => 'ou',
				'Ου' => 'Ou',
				'Ού' => 'Ou',
				'ΟΥ' => 'OU',
				'ΟΎ' => 'OU',
			)
		);

		// Prefix, then the voiced and voiceless endings for each pair.
		$pairs = array(
			'αυ' => array( 'a', 'v', 'f' ),
			'αύ' => array( 'a', 'v', 'f' ),
			'ευ' => array( 'e', 'v', 'f' ),
			'εύ' => array( 'e', 'v', 'f' ),
			'ηυ' => array( 'i', 'v', 'f' ),
			'ηύ' => array( 'i', 'v', 'f' ),
			'Αυ' => array( 'A', 'v', 'f' ),
			'Αύ' => array( 'A', 'v', 'f' ),
			'Ευ' => array( 'E', 'v', 'f' ),
			'Εύ' => array( 'E', 'v', 'f' ),
			'Ηυ' => array( 'I', 'v', 'f' ),
			'Ηύ' => array( 'I', 'v', 'f' ),
			'ΑΥ' => array( 'A', 'V', 'F' ),
			'ΕΥ' => array( 'E', 'V', 'F' ),
			'ΗΥ' => array( 'I', 'V', 'F' ),
		);

		// Greek vowels and voiced consonants, plus the Latin vowels a prior
		// "ου" may have just produced.
		$voiced = 'αάεέηήιίϊΐοόυύϋΰωώβγδζλμνρΑΆΕΈΗΉΙΊΪΟΌΥΎΫΩΏΒΓΔΖΛΜΝΡaeiouAEIOU';

		$keys    = implode( '|', array_map( 'preg_quote', array_keys( $pairs ) ) );
		$replaced = preg_replace_callback(
			'/(' . $keys . ')(.?)/u',
			static function ( $match ) use ( $pairs, $voiced ) {
				list( $prefix, $before_voiced, $otherwise ) = $pairs[ $match[1] ];
				$next = isset( $match[2] ) ? $match[2] : '';
				// strpos, not mb_strpos: nothing else in the plugin needs
				// mbstring, and a URL rule must not fatal on a host without
				// it. UTF-8 is self-synchronising, so a multi-byte needle
				// cannot match at a misaligned offset.
				$is_voiced = '' !== $next && false !== strpos( $voiced, $next );

				return $prefix . ( $is_voiced ? $before_voiced : $otherwise ) . $next;
			},
			$text
		);

		// preg_replace_callback returns null only on failure; keep the input
		// rather than silently emptying a title.
		return is_string( $replaced ) ? $replaced : $text;
	}

	/**
	 * Remove the exact assigned SKU from a product-name source.
	 *
	 * Matching is case-insensitive but bounded, so SKU "15" does not remove the
	 * same digits from "1500". An adjacent "SKU" label and punctuation left by
	 * the removal are tidied too. If fewer than two useful name words would
	 * survive, the original is safer and is returned unchanged.
	 *
	 * @param mixed $text Product-name source.
	 * @param mixed $sku  Exact WooCommerce SKU.
	 * @return string
	 */
	public static function remove_exact_sku( $text, $sku ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		if ( ! is_scalar( $sku ) ) {
			return $text;
		}

		$sku = trim( (string) $sku );

		if ( '' === $text || '' === $sku ) {
			return $text;
		}

		$pattern = '/(?<![\p{L}\p{N}])(?:sku\b\s*[:.#-]?\s*)?' . preg_quote( $sku, '/' ) . '(?![\p{L}\p{N}])/iu';
		$out     = preg_replace( $pattern, ' ', $text );

		if ( ! is_string( $out ) || $out === $text ) {
			return $text;
		}

		$out = self::tidy( $out );

		if ( '' === $out || ( self::word_count( $out ) < 2 && self::word_count( $text ) >= 2 ) ) {
			return $text;
		}

		return $out;
	}

	/**
	 * Add the exact assigned SKU to a product-name source when it is absent.
	 *
	 * The SKU is matched as a bounded, case-insensitive value before it is
	 * appended, so selecting this option never duplicates a code already present
	 * in the title source. Punctuation-only values are ignored because they cannot
	 * produce a useful WordPress slug segment.
	 *
	 * @param mixed $text Product-name source.
	 * @param mixed $sku  Exact WooCommerce SKU.
	 * @return string
	 */
	public static function add_exact_sku( $text, $sku ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		if ( ! is_scalar( $sku ) ) {
			return $text;
		}

		$sku = trim( (string) $sku );

		if ( '' === trim( $text ) || '' === $sku || ! preg_match( '/[\p{L}\p{N}]/u', $sku ) ) {
			return $text;
		}

		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $sku, '/' ) . '(?![\p{L}\p{N}])/iu';

		// preg_match returns false, not 0, when /u meets malformed UTF-8, and
		// false is falsy -- so testing it as a boolean appended the SKU to a
		// title that already carried it. Not knowing whether the code is there
		// is a reason to leave the title alone, not to risk doubling it.
		if ( 0 !== preg_match( $pattern, $text ) ) {
			return $text;
		}

		return rtrim( $text ) . ' ' . $sku;
	}

	/**
	 * Collapse whitespace and separators stranded by an SKU removal.
	 *
	 * @param string $text Text after removal.
	 * @return string
	 */
	private static function tidy( $text ) {
		$text = preg_replace( '/[\(\[\{]\s*[\)\]\}]/u', ' ', (string) $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = preg_replace( '/(?:\s*[-\x{2013}\x{2014}|\/,:;]\s*){2,}/u', ' - ', (string) $text );

		$trimmed = preg_replace(
			'/^[' . self::SEPARATOR_CLASS . ']+|[' . self::SEPARATOR_CLASS . ']+$/u',
			'',
			(string) $text
		);

		return is_string( $trimmed ) ? $trimmed : trim( (string) $text );
	}

	/**
	 * Count tokens containing something other than punctuation/separators.
	 *
	 * @param string $text Text to count.
	 * @return int
	 */
	private static function word_count( $text ) {
		$words = 0;

		foreach ( (array) preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$bare = preg_replace(
				'/^[' . self::SEPARATOR_CLASS . ']+|[' . self::SEPARATOR_CLASS . ']+$/u',
				'',
				(string) $token
			);

			if ( '' !== trim( is_string( $bare ) ? $bare : (string) $token, self::TRIM ) ) {
				$words++;
			}
		}

		return $words;
	}

	/**
	 * Character map used when ext-intl is not available.
	 *
	 * @return array<string,string>
	 */
	private static function transliteration_map() {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map = array(
			// Cyrillic, lower case.
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
			'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
			'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
			'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
			'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
			'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
			'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
			// Cyrillic, upper case.
			'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
			'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
			'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
			'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
			'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
			'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
			'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
			// Serbian, Macedonian, Ukrainian and Belarusian extras.
			'ђ' => 'dj', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ћ' => 'c',
			'џ' => 'dz', 'ѓ' => 'g', 'ќ' => 'k', 'ѕ' => 'dz', 'ў' => 'u',
			'Ђ' => 'Dj', 'Ј' => 'J', 'Љ' => 'Lj', 'Њ' => 'Nj', 'Ћ' => 'C',
			'Џ' => 'Dz', 'Ѓ' => 'G', 'Ќ' => 'K', 'Ѕ' => 'Dz', 'Ў' => 'U',
			'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
			'І' => 'I', 'Ї' => 'Yi', 'Є' => 'Ye', 'Ґ' => 'G',
			// Greek.
			'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e',
			'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i', 'κ' => 'k',
			'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o',
			'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't',
			'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o',
			'ά' => 'a', 'έ' => 'e', 'ή' => 'i', 'ί' => 'i', 'ό' => 'o',
			'ύ' => 'y', 'ώ' => 'o', 'ϊ' => 'i', 'ϋ' => 'y',
			'Α' => 'A', 'Β' => 'V', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E',
			'Ζ' => 'Z', 'Η' => 'I', 'Θ' => 'Th', 'Ι' => 'I', 'Κ' => 'K',
			'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ξ' => 'X', 'Ο' => 'O',
			'Π' => 'P', 'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T', 'Υ' => 'Y',
			'Φ' => 'F', 'Χ' => 'Ch', 'Ψ' => 'Ps', 'Ω' => 'O',
		);

		return $map;
	}
}
