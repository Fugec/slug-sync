<?php
/**
 * Title signal detection.
 *
 * Deliberately free of WordPress calls so it can be unit tested without a
 * WordPress bootstrap, and so it stays cheap enough to run once per row on a
 * catalogue of tens of thousands of items.
 *
 * @package Slug_Sync
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'SLUG_SYNC_TESTING' ) ) {
	// Loaded directly by PHPUnit; nothing to guard against there.
	define( 'SLUG_SYNC_TESTING', true );
}

/**
 * Reports what a title contains, without changing it.
 */
class Slug_Sync_Signals {

	/**
	 * Stop words counted for the upsell. English only on purpose: this drives a
	 * count on a marketing card, not the rewriting itself, and a wrong count is
	 * worse than a conservative one.
	 *
	 * @var string[]
	 */
	private static $stopwords = array(
		'a', 'an', 'and', 'as', 'at', 'be', 'by', 'for', 'from', 'in',
		'is', 'of', 'on', 'or', 'the', 'to', 'with',
	);

	/**
	 * Punctuation trimmed from a token before it is classified.
	 */
	const TRIM = " \t\n\r\0\x0B.,;:!?()[]{}\"'";

	/**
	 * Detect signals in a title.
	 *
	 * @param string $title Raw post title.
	 * @return array{code:bool,stopword:bool,non_latin:bool}
	 */
	public static function detect( $title ) {
		$title = (string) $title;

		return array(
			'code'      => self::has_code( $title ),
			'stopword'  => self::has_stopword( $title ),
			'non_latin' => self::has_non_latin( $title ),
		);
	}

	/**
	 * True when the title carries a product code, SKU or bracketed reference.
	 *
	 * @param string $title Title.
	 * @return bool
	 */
	private static function has_code( $title ) {
		if ( preg_match( '/\b(?:sku|mpn|ean|upc|asin|art|ref)\b\s*[:.#-]?\s*[A-Za-z0-9]/iu', $title ) ) {
			return true;
		}

		// A bracketed group containing a digit: (AB-1234), [90210].
		if ( preg_match( '/[\(\[\{][^\)\]\}]*\d[^\)\]\}]*[\)\]\}]/u', $title ) ) {
			return true;
		}

		foreach ( self::tokens( $title ) as $token ) {
			if ( self::is_code_token( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify a single whitespace-delimited token.
	 *
	 * A short digit run is a model number people want to keep ("iPhone 15"), so
	 * only long runs count. A mixed letter/digit token of four or more characters
	 * is treated as a code.
	 *
	 * @param string $token Trimmed token.
	 * @return bool
	 */
	public static function is_code_token( $token ) {
		if ( preg_match( '/^#?\d+$/', $token ) ) {
			return strlen( ltrim( $token, '#' ) ) >= 5;
		}

		if ( strlen( $token ) < 4 ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z0-9\-_\/]+$/', $token ) ) {
			return false;
		}

		return (bool) preg_match( '/\d/', $token ) && (bool) preg_match( '/[A-Za-z]/', $token );
	}

	/**
	 * True when the title contains at least one stop word.
	 *
	 * @param string $title Title.
	 * @return bool
	 */
	private static function has_stopword( $title ) {
		foreach ( self::tokens( $title ) as $token ) {
			if ( in_array( strtolower( $token ), self::$stopwords, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when the title contains letters outside the Latin script.
	 *
	 * Latin diacritics are excluded deliberately: sanitize_title() already folds
	 * them through remove_accents(), so they are not a reason to buy anything.
	 *
	 * @param string $title Title.
	 * @return bool
	 */
	private static function has_non_latin( $title ) {
		return (bool) preg_match( '/\p{L}/u', $title ) && (bool) preg_match( '/(?!\p{Latin})\p{L}/u', $title );
	}

	/**
	 * Split a title into punctuation-trimmed tokens.
	 *
	 * @param string $title Title.
	 * @return string[]
	 */
	private static function tokens( $title ) {
		$parts  = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array();

		foreach ( (array) $parts as $part ) {
			$bare = trim( $part, self::TRIM );

			if ( '' !== $bare ) {
				$tokens[] = $bare;
			}
		}

		return $tokens;
	}
}
