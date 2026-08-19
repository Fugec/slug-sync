# Slug Sync Pro Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an uncapped free `slug-sync` plugin to wordpress.org and a paid `slug-sync-pro` add-on sold from slugsync.com at $6.99 one-time for unlimited sites, whose two paid features are a slug rules engine that fixes the garbage titles the free preview exposes, and term/taxonomy slug sync that the free plugin does not touch at all.

**Architecture:** The free plugin stays fully functional with no quota, no license code and no remote calls — required by wordpress.org Guideline 5. It gains one extension filter (`slug_sync_source_title`) and a pure-PHP signal detector that powers a contextual upsell on the preview results screen. Pro is a separate plugin in a separate private repo, declares `Requires Plugins: slug-sync`, verifies an Ed25519-signed license key entirely offline, updates itself from a static JSON on slugsync.com, and hooks the filter to rewrite titles before slug generation. Term sync is standalone Pro code against `wp_terms`, with its own batching, reports and undo, because core's `_wp_old_slug` redirect has no term equivalent and nothing in the free plugin's post-oriented run machinery is reusable for it.

**Tech Stack:** PHP 7.4+ (free) / PHP 7.4+ with libsodium (Pro), WordPress 5.6+ (free) / 6.5+ (Pro), PHPUnit 9 for pure-logic units, Composer for Pro dev deps only (no runtime dependencies shipped).

---

## Paywall boundaries — read before writing any gating code

These are hard rules for this codebase. Violating them is what gets the free plugin rejected or removed from the directory.

**Free plugin (`slug-sync`, wordpress.org):**
- No item quota. No cap on posts previewed or applied. Ever.
- No time limit, no trial, no expiring functionality.
- No license key field, no license check, no `is_pro()` branch, no remote request of any kind.
- Every feature it ships today stays uncapped: preview, apply, redirect map, undo, resume, all public post types.
- The only commercial surface is one contextual card on the plugin's own Tools screen, shown after a preview completes. No `admin_notices`, no dashboard widget, no nag on activation.

Rationale, verbatim from [Guideline 5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/): *"Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade. Functionality may not be disabled after a trial period or quota is met."* A moderator answering this exact question also confirmed a license-key unlock inside the .org plugin violates the policy, and that the endorsed integration mechanism is *"the Light contains a hook with parameters and the Pro uses this to add further data."* That is precisely the architecture below.

**Pro plugin (`slug-sync-pro`, slugsync.com):**
- $6.99, charged once. No subscription, no renewal.
- Unlimited sites per licensed email. No activation counting, no seat management, no site registration — with unlimited sites there is nothing to track, which is why there is no license server.
- Lifetime updates from the static endpoint.
- The only limit is a licence-terms limit, not a code limit: the key is for sites the buyer owns or manages. Do not build enforcement for this. It is GPL; anyone can bypass any check with a one-line filter, and the [review team's own position](https://make.wordpress.org/plugins/2018/08/23/guideline-update-clarifications-to-trialware-and-human-readability/) is that such limits are pointless because users can fork. Spend zero hours on anti-tamper.
- If no valid key is stored, Pro does nothing at all — it does not degrade the free plugin, does not nag, and does not phone home. That is not a quota; it is an unlicensed plugin sitting idle.

**Out of scope for this plan:** the slugsync.com website (deferred by the user), term/taxonomy slug sync and WP-CLI (both v1.1), and scheduled sync (deliberately excluded from the $6.99 tier — it is the only feature with genuine recurring value and should not be sold once, forever).

---

## File structure

**Free plugin** — repo root, `~/Desktop/slug-sync-premium` (the directory name is a leftover; the plugin slug is `slug-sync`).

| File | Responsibility |
|---|---|
| `slug-sync.php` | Existing 76KB single-class plugin. Gains one filter, one include, signal accumulation in the run record, and the upsell card. |
| `includes/class-slug-sync-signals.php` | **New.** Pure PHP, zero WordPress calls. Detects product codes, stop words and non-Latin script in a title. Unit tested directly by PHPUnit with no WordPress bootstrap. |
| `readme.txt` | Drops the "no upsells" claim, documents the filter, bumps version. |
| `tests/SignalsTest.php` | **New.** Unit tests for the signal detector. |
| `phpunit.xml.dist`, `composer.json` | **New.** Dev-only. Excluded from the .org build. |
| `.distignore` | **New.** Keeps `docs/`, `tests/`, `composer.*`, `phpunit.xml.dist` out of the directory zip. |

**Pro plugin** — a **separate private git repo** at `~/Desktop/slug-sync-pro`.

Keeping Pro in a separate private repo is not optional. GPL obliges you to give source to *buyers*, not to the public; putting Pro in the same public repo as the free plugin gives the product away and there is no sale left to make.

| File | Responsibility |
|---|---|
| `slug-sync-pro.php` | Plugin header (`Requires Plugins`, `Update URI`), autoloader, bootstrap. Wires everything, contains no logic. |
| `src/License.php` | Pure. Parse and verify an Ed25519-signed key. No WordPress, no network. |
| `src/Rules.php` | Pure. Title rewriting: replacements, code stripping, stop words, max words. |
| `src/Transliterator.php` | Pure. Non-Latin script to Latin, with an `intl` fast path and a bundled fallback map. |
| `src/Settings.php` | WordPress. License screen, rules screen, option storage. |
| `src/Updater.php` | WordPress. `update_plugins_slugsync.com` filter plus a pure response mapper. |
| `bin/sign-key.php` | CLI. Mints a license key from an email. Run on your machine or from the purchase webhook. |
| `bin/make-keypair.php` | CLI. Generates the Ed25519 keypair once, at setup. |
| `tests/` | PHPUnit for License, Rules, Transliterator, Updater's mapper. |

`src/License.php`, `src/Rules.php` and `src/Transliterator.php` are pure functions with no WordPress dependency, which is what makes real TDD possible here. `src/Settings.php` and `src/Updater.php` touch WordPress and are verified manually with the exact click paths given in each task.

---

## Phase A — Free plugin v1.1 (wordpress.org)

### Task 1: Add the `slug_sync_source_title` extension filter

**Goal:** Give Pro a single, correctly-placed hook to rewrite a title before the slug is generated from it.

**Files:**
- Modify: `slug-sync.php:1776`

**Why the title and not the slug:** transliteration has to run *before* `sanitize_title()`, because `sanitize_title()` percent-encodes non-Latin characters and by then the information is gone. Stop-word removal and code stripping are also word-level operations that read far more cleanly on the title. One hook on the title covers every v1 rule; a second hook on the slug would be YAGNI.

**Files:**
- Modify: `slug-sync.php:1776`

**Acceptance Criteria:**
- [ ] `sanitize_title()` and `cap_length()` still run *after* the filter, so a filter returning an over-long or unsanitised string cannot overflow `post_name`
- [ ] With no filter attached, generated slugs are byte-identical to v1.0.0
- [ ] `php -l slug-sync.php` passes

**Verify:** `php -l slug-sync.php` → `No syntax errors detected`

**Steps:**

- [ ] **Step 1: Replace line 1776**

Current line 1776 reads:

```php
			$target = self::cap_length( sanitize_title( $row->post_title ) );
```

Replace it with:

```php
			/**
			 * Filters the title a slug is generated from.
			 *
			 * Runs before sanitize_title(), so a filter may return characters in
			 * any script; the result is sanitised and length-capped afterwards
			 * either way. Returning an empty string makes the run skip the post.
			 *
			 * @since 1.1.0
			 *
			 * @param string $title     Post title as stored.
			 * @param object $row       Row with ID, post_title, post_name, post_status, post_parent.
			 * @param string $post_type Post type being processed.
			 */
			$source = apply_filters( 'slug_sync_source_title', $row->post_title, $row, $post_type );
			$source = is_string( $source ) ? $source : $row->post_title;

			$target = self::cap_length( sanitize_title( $source ) );
```

- [ ] **Step 2: Verify syntax**

Run: `php -l slug-sync.php`
Expected: `No syntax errors detected in slug-sync.php`

- [ ] **Step 3: Verify behaviour is unchanged with no filter attached**

On a local WordPress install with the plugin active, go to Tools → Slug Sync, run a Preview on Posts, and download the changes CSV. Every `new_slug` value must be identical to a preview taken before this change.

- [ ] **Step 4: Verify the filter fires**

Drop this in `wp-content/mu-plugins/slug-sync-filter-test.php`:

```php
<?php
add_filter( 'slug_sync_source_title', function ( $title ) {
	return 'ZZTEST ' . $title;
} );
```

Run a Preview on Posts. Every `new_slug` in the CSV must now start with `zztest-`. Delete the mu-plugin afterwards.

- [ ] **Step 5: Commit**

```bash
git add slug-sync.php
git commit -m "feat: add slug_sync_source_title filter for add-on plugins"
```

---

### Task 2: Pure signal detector for the contextual upsell

**Goal:** A zero-dependency class that reports what a title contains, so the preview can quantify the upsell against the user's own data instead of showing a generic banner.

**Files:**
- Create: `includes/class-slug-sync-signals.php`
- Create: `tests/SignalsTest.php`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Modify: `slug-sync.php` (require the include near the top)

**Post-review tightening (already applied).** The first version flagged any 4+ character token mixing a letter and a digit, which counted `400mg`, `20mm`, `4-Pack`, `20000mAh`, `M404dn` and `18V-55` as product codes. Since this figure is shown to users as a specific number, over-counting destroys the trust the card exists to build, so the rule now requires **four or more digits** in a mixed token, **six or more** in a bare digit run, and excludes measurements, dosages, pack quantities and rated model designations. Under-counting is deliberate: missing a SKU costs nothing, inflating the number costs credibility. Every threshold is pinned by a boundary test — verified by mutation, all three mutations fail the suite.

**Acceptance Criteria:**
- [ ] `Slug_Sync_Signals::detect()` returns an array with boolean keys `code`, `stopword`, `non_latin`
- [ ] The class calls no WordPress function, so PHPUnit loads it directly with `require`
- [ ] `iPhone 15` is **not** flagged as containing a code (short digit runs are model numbers, not SKUs)
- [ ] `Nike Air Max 90 - White - SKU 4823-BLK` is flagged as containing a code
- [ ] `Кофеварка Bosch` is flagged non-Latin
- [ ] All tests pass, including boundary tests pinning both digit thresholds and the measurement guard

**Verify:** `vendor/bin/phpunit` → `OK (23 tests, ...)`

**Steps:**

- [ ] **Step 1: Create the Composer and PHPUnit config**

`composer.json`:

```json
{
	"name": "arminkapetanovic/slug-sync",
	"description": "Development tooling for the Slug Sync plugin. Not shipped.",
	"license": "GPL-2.0-or-later",
	"type": "wordpress-plugin",
	"require-dev": {
		"phpunit/phpunit": "^9.6"
	},
	"config": {
		"platform": {
			"php": "7.4"
		}
	}
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" convertDeprecationsToExceptions="true">
	<testsuites>
		<testsuite name="unit">
			<directory suffix="Test.php">tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php
require_once __DIR__ . '/../includes/class-slug-sync-signals.php';
```

Run: `composer install`
Expected: `vendor/` created, phpunit installed.

- [ ] **Step 2: Write the failing tests**

The nine tests below are the original spec and all still hold. `tests/SignalsTest.php` as shipped carries fourteen more that pin the thresholds — five/six-digit runs, three/four digits in a mixed token, dosages, measurements, pack quantities, fused model numbers and rated designations. Treat the shipped file as authoritative; these nine are the starting point, not the finished suite.

`tests/SignalsTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

final class SignalsTest extends TestCase {

	public function test_plain_title_has_no_signals() {
		$this->assertSame(
			array(
				'code'      => false,
				'stopword'  => false,
				'non_latin' => false,
			),
			Slug_Sync_Signals::detect( 'Blue Cotton Shirt' )
		);
	}

	public function test_sku_prefix_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Nike Air Max 90 - White - SKU 4823-BLK' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_bare_alphanumeric_token_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Wireless Mouse B09XYZ123' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_long_digit_run_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Replacement Filter 4820391' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_short_model_number_is_not_a_code() {
		$signals = Slug_Sync_Signals::detect( 'iPhone 15' );
		$this->assertFalse( $signals['code'] );
	}

	public function test_bracketed_code_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Desk Lamp (AB-1234)' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_stopwords_are_detected() {
		$signals = Slug_Sync_Signals::detect( 'The Best of the Summer Collection' );
		$this->assertTrue( $signals['stopword'] );
	}

	public function test_cyrillic_is_non_latin() {
		$signals = Slug_Sync_Signals::detect( 'Кофеварка Bosch' );
		$this->assertTrue( $signals['non_latin'] );
	}

	public function test_latin_diacritics_are_not_non_latin() {
		$signals = Slug_Sync_Signals::detect( 'Čokoladni Kolač' );
		$this->assertFalse( $signals['non_latin'] );
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: FAIL — `Error: Class "Slug_Sync_Signals" not found`

- [ ] **Step 4: Write the implementation**

`includes/class-slug-sync-signals.php`:

```php
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
		if ( '' === $token ) {
			return false;
		}

		// Measurements, dosages and pack quantities are not codes. Catalogues are
		// full of "400mg", "20mm" and "4-Pack", and counting them would overstate
		// the figure this drives, which is worse than missing a genuine SKU.
		if ( self::is_measurement( $token ) ) {
			return false;
		}

		// A bare digit run has to be long to be a code. Five digits is a dosage
		// ("10000 IU") or a year as often as it is a product number.
		if ( preg_match( '/^#?\d+$/', $token ) ) {
			return strlen( ltrim( $token, '#' ) ) >= 6;
		}

		if ( strlen( $token ) < 4 ) {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z0-9\-_\/]+$/', $token ) ) {
			return false;
		}

		if ( ! preg_match( '/[A-Za-z]/', $token ) ) {
			return false;
		}

		/*
		 * Four or more digits in a mixed token. Model numbers people want to keep
		 * carry fewer -- "S23", "M404dn", "iPhone 15" -- while SKUs and ASINs
		 * such as "B09XYZ123" or "4823-BLK" carry more. The separation is not
		 * perfect: "18V-55" is a model designation that still counts. Erring
		 * towards under-counting is deliberate.
		 */
		return preg_match_all( '/\d/', $token ) >= 4;
	}

	/**
	 * Whether a token is a measurement, dosage or pack quantity.
	 *
	 * @param string $token Trimmed token.
	 * @return bool
	 */
	private static function is_measurement( $token ) {
		$units = 'mg|mcg|ug|kg|g|ml|cl|dl|l|mm|cm|km|m|in|ft|yd|oz|lbs|lb|kw|w|kv|v|ma|mah|ah|hz|khz|mhz|ghz|kb|mb|gb|tb|iu|pcs|pc|pk|ct';

		// "400mg", "20mm", "1.5l", "5000iu"
		if ( preg_match( '/^\d+(?:[.,]\d+)?(?:' . $units . ')$/i', $token ) ) {
			return true;
		}

		// "4-Pack", "10pcs", "3 x", "2x"
		if ( preg_match( '/^\d+[-_]?(?:pack|packs|pcs|pieces|count|ct|x)$/i', $token ) ) {
			return true;
		}

		// Rated model designations: "18V-55", "12V-30". Common across power tools,
		// and a genuine SKU almost never takes the shape number-unit-number.
		if ( preg_match( '/^\d+(?:' . $units . ')[-_]\d+$/i', $token ) ) {
			return true;
		}

		return false;
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: `OK (23 tests, 23 assertions)`

If `test_latin_diacritics_are_not_non_latin` fails, the `(?!\p{Latin})\p{L}` construct is matching combining marks. Replace `has_non_latin()`'s second pattern with `'/[^\p{Latin}\p{Common}\p{Inherited}]/u'` and re-run.

- [ ] **Step 6: Load the class from the plugin**

In `slug-sync.php`, immediately after the `define( 'SLUG_SYNC_VERSION', '1.0.0' );` line (line 21), add:

```php
require_once __DIR__ . '/includes/class-slug-sync-signals.php';
```

- [ ] **Step 7: Commit**

```bash
git add composer.json phpunit.xml.dist includes/ tests/ slug-sync.php
git commit -m "feat: add pure signal detector with unit tests"
```

---

### Task 3: Count signals during a run and show the contextual upsell

**Goal:** After a preview completes, show one card on the Slug Sync screen quantifying what Pro would fix, using the numbers from the user's own catalogue.

**Files:**
- Modify: `slug-sync.php` — `create_run()` around line 313, the run loop around line 1776, the save block around line 1838, and a new `upsell_card()` method called from the finish branch around line 1880

**Acceptance Criteria:**
- [ ] Signal counts accumulate across batches and survive resume
- [ ] The card renders only after a completed run, only when at least one count is above zero
- [ ] Nothing renders on a site where every title is clean
- [ ] No `admin_notices` hook is added anywhere
- [ ] The card names real numbers from the run, not placeholders

**Verify:** Run a full Preview on a site with mixed titles → the card appears below the results with counts matching the CSV. Run one on a site with clean titles → no card.

**Steps:**

- [ ] **Step 1: Add signal counters to the run record**

Align the `=>` operators with the surrounding array entries (keys padded to the width of `'pause_after_batch'`). `WordPress.Arrays.MultipleStatementAlignment` flags a mismatched column, and wordpress.org runs Plugin Check on submission.

In `create_run()`, inside the `$run = array( ... )` literal (around line 313), add after `'errors' => 0,`:

```php
			'sig_code'          => 0,
			'sig_stopword'      => 0,
			'sig_non_latin'     => 0,
```

- [ ] **Step 2: Read the counters at the top of the batch**

In `run()`, immediately after the existing `$errors = isset( $run['errors'] ) ? absint( $run['errors'] ) : 0;` line, add:

```php
		$sig_code      = isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0;
		$sig_stopword  = isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0;
		$sig_non_latin = isset( $run['sig_non_latin'] ) ? absint( $run['sig_non_latin'] ) : 0;
```

- [ ] **Step 3: Count inside the loop**

In the `foreach ( $rows as $row )` loop, immediately after `$done++;` and *before* the `$source` filter added in Task 1, add:

```php
			$signals = Slug_Sync_Signals::detect( $row->post_title );

			if ( $signals['code'] ) {
				$sig_code++;
			}
			if ( $signals['stopword'] ) {
				$sig_stopword++;
			}
			if ( $signals['non_latin'] ) {
				$sig_non_latin++;
			}
```

- [ ] **Step 4: Persist the counters**

In the block that assigns `$run['done']`, `$run['total']` and so on before `self::save_run( $run );`, add:

```php
		$run['sig_code']          = $sig_code;
		$run['sig_stopword']      = $sig_stopword;
		$run['sig_non_latin']     = $sig_non_latin;
```

- [ ] **Step 5: Add the card renderer**

Add this method to the class, directly above `private static function cancel_run() {`:

```php
	/**
	 * Contextual note about what a rules add-on would change in this run.
	 *
	 * Rendered on the plugin's own screen, after a completed run, and only when
	 * the run actually found something. Guideline 11 permits an upsell here; it
	 * does not permit an admin notice, and there is deliberately none.
	 *
	 * @param array $run Completed run record.
	 */
	private static function upsell_card( $run ) {
		$code      = isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0;
		$stopword  = isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0;
		$non_latin = isset( $run['sig_non_latin'] ) ? absint( $run['sig_non_latin'] ) : 0;

		if ( ! $code && ! $stopword && ! $non_latin ) {
			return;
		}

		if ( has_filter( 'slug_sync_source_title' ) ) {
			return; // A rules add-on is already installed.
		}

		$lines = array();

		if ( $code ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains a product code or SKU.', '%d titles contain a product code or SKU.', $code, 'slug-sync' ), $code );
		}
		if ( $non_latin ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title is written in a non-Latin script.', '%d titles are written in a non-Latin script.', $non_latin, 'slug-sync' ), $non_latin );
		}
		if ( $stopword ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains common filler words.', '%d titles contain common filler words.', $stopword, 'slug-sync' ), $stopword );
		}

		echo '<div class="slug-sync-card"><h2>' . esc_html__( 'About these titles', 'slug-sync' ) . '</h2><ul>';

		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}

		echo '</ul><p class="description">' .
			esc_html__( 'Slug Sync builds each slug from the title exactly as WordPress would. Slug Sync Pro adds rules that rewrite the title first, so codes and filler words never reach the URL and non-Latin titles are transliterated rather than percent-encoded.', 'slug-sync' ) .
			'</p><p><a class="button" href="https://slugsync.com/pro/" target="_blank" rel="noopener noreferrer">' .
			esc_html__( 'Slug Sync Pro — $6.99, every site you own', 'slug-sync' ) .
			'</a></p></div>';
	}
```

- [ ] **Step 6: Call it from the finish branch**

In `run()`, inside `if ( $finished ) {` in the output section, immediately after the closing brace of the `if ( $apply ) { ... } else { ... }` notice block and before the `printf( '<p><a class="button" ...Back...' )` call, add:

```php
			self::upsell_card( $run );
```

- [ ] **Step 7: Verify manually**

Create three posts titled `Nike Air Max 90 - White - SKU 4823-BLK`, `The Best of the Summer Collection` and `Кофеварка Bosch`, each with a slug that does not match. Go to Tools → Slug Sync, run a Preview on Posts.
Expected: the "About these titles" card appears with counts 1, 1, 1.

Then add the mu-plugin from Task 1 Step 4 and re-run.
Expected: no card (a rules add-on is present).

- [ ] **Step 8: Commit**

```bash
git add slug-sync.php
git commit -m "feat: quantify rule-fixable titles and show a contextual Pro note"
```

---

### Task 4: Ship free v1.1 — readme, version, build exclusions

**Goal:** Get the free plugin honest and directory-ready.

**Files:**
- Modify: `readme.txt` — line 8 (`Stable tag`), line 53 (the "no upsells" claim), Changelog
- Modify: `slug-sync.php` — line 5 (`Version`), line 21 (`SLUG_SYNC_VERSION`)
- Create: `.distignore`

**Acceptance Criteria:**
- [ ] `readme.txt` no longer claims "no upsells"
- [ ] `readme.txt` still truthfully claims no telemetry and no external requests — verified by grep finding zero HTTP calls in the free plugin
- [ ] `Stable tag`, plugin header `Version` and `SLUG_SYNC_VERSION` all read `1.1.0`
- [ ] The build excludes dev files

**Verify:** `grep -rnE "wp_remote_|curl_|file_get_contents\s*\(\s*['\"]https?" slug-sync.php includes/` → no matches

**Steps:**

- [ ] **Step 1: Confirm the no-external-requests claim is still true**

Run:

```bash
grep -rnE "wp_remote_|curl_|fsockopen|file_get_contents\s*\(\s*['\"]https?" slug-sync.php includes/
```

Expected: no output. If anything matches, the claim on line 53 must be removed too.

- [ ] **Step 2: Rewrite line 53 of `readme.txt`**

Current:

```
* No telemetry, no external requests, no upsells.
```

Replace with:

```
* No telemetry and no external requests. The plugin never contacts a server.
* Everything above is uncapped: no item limit, no trial, nothing expires.
```

- [ ] **Step 3: Add the Pro note to the Description**

Append to the end of the `== Description ==` section, before `== Installation ==`:

```
**About Slug Sync Pro**

Slug Sync is complete on its own and has no limits. Slug Sync Pro is a separate add-on, sold at slugsync.com, that rewrites the title before the slug is built: it strips product codes and SKUs, drops filler words, and transliterates non-Latin titles instead of letting them be percent-encoded. It is not required for anything described above.
```

- [ ] **Step 4: Add a Changelog entry**

At the top of `== Changelog ==`:

```
= 1.1.0 =
* Added the `slug_sync_source_title` filter so add-on plugins can rewrite a title before its slug is generated.
* Preview now reports how many titles contain product codes, filler words or non-Latin script.
```

- [ ] **Step 5: Bump the version in three places**

`readme.txt` line 8: `Stable tag: 1.1.0`
`slug-sync.php` line 5: ` * Version:           1.1.0`
`slug-sync.php` line 21: `define( 'SLUG_SYNC_VERSION', '1.1.0' );`

- [ ] **Step 6: Create `.distignore`**

```
/.git
/.github
/docs
/tests
/vendor
/node_modules
composer.json
composer.lock
phpunit.xml.dist
.phpunit.result.cache
.distignore
.gitignore
```

- [ ] **Step 7: Verify the version is consistent**

Run:

```bash
grep -n "1\.1\.0" readme.txt slug-sync.php
```

Expected: three or more matches covering `Stable tag`, the `Version:` header and `SLUG_SYNC_VERSION`.

- [ ] **Step 8: Commit**

```bash
git add readme.txt slug-sync.php .distignore
git commit -m "chore: release 1.1.0 — document the filter, drop the no-upsells claim"
```

---

## Phase B — Slug Sync Pro foundation

### Task 5: Pro plugin skeleton in its own private repo

**Goal:** A loadable, do-nothing Pro plugin with the correct headers, an autoloader, and a working test harness.

**Files:**
- Create: `~/Desktop/slug-sync-pro/slug-sync-pro.php`
- Create: `~/Desktop/slug-sync-pro/composer.json`
- Create: `~/Desktop/slug-sync-pro/phpunit.xml.dist`
- Create: `~/Desktop/slug-sync-pro/tests/bootstrap.php`
- Create: `~/Desktop/slug-sync-pro/.gitignore`

**Acceptance Criteria:**
- [x] The repo is separate from the free plugin's repo and its remote is private (done: `Fugec/slugsync-pro`)
- [ ] `Requires Plugins: slug-sync` is present, so WordPress 6.5+ prompts a direct buyer to install the free plugin
- [ ] `Update URI: https://slugsync.com/slug-sync-pro` is present
- [ ] Activating Pro without the free plugin does not fatal
- [ ] `vendor/bin/phpunit` runs and reports no tests yet

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit` → `No tests executed!`

**Steps:**

- [x] **Step 1: Create the repo** — ALREADY DONE

The repo exists and is connected. Local working copy: `~/Desktop/slug-sync-pro`. Remote: `https://github.com/Fugec/slugsync-pro.git` (private, branch `main`).

Note the deliberate name difference: the **local directory and the released zip must both be `slug-sync-pro`**, because that is the WordPress plugin slug used by the text domain and by `SLUG_SYNC_PRO_BASENAME`. The GitHub repo is `slugsync-pro`. Git does not care that they differ; the zip build does.

Do not run `git init` or `gh repo create`. Before any `git add` in this directory, confirm isolation:

```bash
cd ~/Desktop/slug-sync-pro && git rev-parse --show-toplevel
```

Expected: `/Users/arminkapetanovic/Desktop/slug-sync-pro`. **If it prints anything else — especially the home directory — stop and run no further git commands.** The home directory is itself a git repo containing SSH private keys and GitHub recovery codes, and a `git add` from the wrong root would stage them.

Never use `git add -A` or `git add .` in this repo. Stage files by name.

- [x] **Step 2: Create `.gitignore`** — ALREADY DONE

Committed in `0e20b85`, deliberately before any other file, so the signing key can never be staged:

```
keys/
*.key
*.pem
/vendor
/node_modules
.phpunit.result.cache
/build
*.zip
.DS_Store
```

A `README.md` was committed alongside it.

- [ ] **Step 3: Create `slug-sync-pro.php`**

```php
<?php
/**
 * Plugin Name:       Slug Sync Pro
 * Description:       Rewrites titles before Slug Sync builds a slug from them: strips product codes and SKUs, drops filler words, and transliterates non-Latin scripts.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  slug-sync
 * Update URI:        https://slugsync.com/slug-sync-pro
 * Author:            Armin Kapetanovic
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slug-sync-pro
 *
 * @package Slug_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SLUG_SYNC_PRO_VERSION', '1.0.0' );
define( 'SLUG_SYNC_PRO_FILE', __FILE__ );
define( 'SLUG_SYNC_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Ed25519 public key, raw 32 bytes, base64 encoded.
 *
 * Replaced by the real value in Task 6 Step 1. The matching secret key lives
 * only on the signing machine and is never committed or shipped.
 */
define( 'SLUG_SYNC_PRO_PUBLIC_KEY', 'REPLACE_IN_TASK_6' );

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'SlugSync\\Pro\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'SlugSync\\Pro\\' ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		// Requires Plugins covers WordPress 6.5+, but a site that downgraded core
		// or force-activated the plugin would fatal on the missing filter target.
		if ( ! class_exists( 'Slug_Sync' ) ) {
			return;
		}

		\SlugSync\Pro\Settings::init();
		\SlugSync\Pro\Updater::init();
	}
);
```

- [ ] **Step 4: Create `composer.json`**

```json
{
	"name": "arminkapetanovic/slug-sync-pro",
	"description": "Development tooling for Slug Sync Pro. Not shipped.",
	"license": "GPL-2.0-or-later",
	"type": "wordpress-plugin",
	"require-dev": {
		"phpunit/phpunit": "^9.6"
	},
	"config": {
		"platform": {
			"php": "7.4"
		}
	}
}
```

- [ ] **Step 5: Create `phpunit.xml.dist` and `tests/bootstrap.php`**

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" convertDeprecationsToExceptions="true">
	<testsuites>
		<testsuite name="unit">
			<directory suffix="Test.php">tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php
/**
 * Loads only the WordPress-free classes. Settings and Updater are verified
 * manually against a real install; everything with logic worth testing is pure.
 */

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'SlugSync\\Pro\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'SlugSync\\Pro\\' ) );
		$path     = __DIR__ . '/../src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
```

- [ ] **Step 6: Install and verify**

Run: `composer install && vendor/bin/phpunit`
Expected: `No tests executed!`

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: scaffold Slug Sync Pro"
```

---

### Task 6: Offline license keys — format, verification, signing tools

**Goal:** A license key that verifies with no server, no network and no vendor dependency, so a $6.99 one-time purchase keeps working forever.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/License.php`
- Create: `~/Desktop/slug-sync-pro/tests/LicenseTest.php`
- Create: `~/Desktop/slug-sync-pro/bin/make-keypair.php`
- Create: `~/Desktop/slug-sync-pro/bin/sign-key.php`
- Create: `~/Desktop/slug-sync-pro/bin/revoke.php`
- Modify: `~/Desktop/slug-sync-pro/slug-sync-pro.php` (the `SLUG_SYNC_PRO_PUBLIC_KEY` constant)

**Key format:** `SS1.<base64url(payload)>.<base64url(signature)>` where payload is JSON `{"v":1,"p":"slug-sync-pro","e":"buyer@example.com"}`.

There is no site count, no expiry and no activation field, because the product is unlimited sites and one-time.

**The payload carries no timestamp, deliberately.** That makes the key a pure function of the email: the same address always produces a byte-identical key. A "retrieve my key" page can therefore re-derive it forever — ask the store's API whether that address has a completed order, re-sign, display. No database, no stored state, and **no transactional email service needed at all**, which is what replaces email delivery.

Sharing is not prevented, and cannot be: the plugin is GPL and runs on the buyer's own server, so any check is one edit away from removal. What the design gives instead is **traceability** — every key is a signature over its buyer's email, and the licence screen displays it — plus the revocation blocklist below as the lever for the rare case where a key turns up publicly.

**Acceptance Criteria:**
- [ ] A key signed by `bin/sign-key.php` verifies against the embedded public key
- [ ] A key with a tampered email fails
- [ ] A key with a tampered signature fails
- [ ] A key for a different product string fails
- [ ] Malformed input returns `false` and never throws
- [ ] The same email always mints a byte-identical key, so it can be re-derived rather than stored
- [ ] A key whose email hash appears in the revocation list fails verification even though its signature is valid
- [ ] The revocation list holds SHA-256 hashes, never plaintext addresses
- [ ] `License::verify()` makes no network call and touches no WordPress function

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit --filter LicenseTest` → `OK (13 tests, ...)`

**Steps:**

- [ ] **Step 1: Write the failing tests**

`tests/LicenseTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use SlugSync\Pro\License;

final class LicenseTest extends TestCase {

	/** @var string */
	private $public_key;

	/** @var string */
	private $secret_key;

	protected function setUp(): void {
		// Fixed seed so the fixture keys are identical on every run.
		$seed    = str_repeat( "\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES );
		$keypair = sodium_crypto_sign_seed_keypair( $seed );

		$this->public_key = sodium_crypto_sign_publickey( $keypair );
		$this->secret_key = sodium_crypto_sign_secretkey( $keypair );
	}

	private function make_key( array $payload ): string {
		$json = json_encode( $payload );
		$sig  = sodium_crypto_sign_detached( $json, $this->secret_key );

		return 'SS1.' . License::b64url_encode( $json ) . '.' . License::b64url_encode( $sig );
	}

	private function valid_payload(): array {
		return array(
			'v' => 1,
			'p' => 'slug-sync-pro',
			'e' => 'buyer@example.com',
			'i' => 1755561600,
		);
	}

	public function test_valid_key_verifies() {
		$key    = $this->make_key( $this->valid_payload() );
		$result = License::verify( $key, $this->public_key );

		$this->assertIsArray( $result );
		$this->assertSame( 'buyer@example.com', $result['e'] );
	}

	public function test_tampered_email_fails() {
		$key   = $this->make_key( $this->valid_payload() );
		$parts = explode( '.', $key );

		$payload       = json_decode( License::b64url_decode( $parts[1] ), true );
		$payload['e']  = 'thief@example.com';
		$parts[1]      = License::b64url_encode( json_encode( $payload ) );

		$this->assertFalse( License::verify( implode( '.', $parts ), $this->public_key ) );
	}

	public function test_tampered_signature_fails() {
		$key   = $this->make_key( $this->valid_payload() );
		$parts = explode( '.', $key );

		$sig      = License::b64url_decode( $parts[2] );
		$sig[0]   = ( "\x00" === $sig[0] ) ? "\x01" : "\x00";
		$parts[2] = License::b64url_encode( $sig );

		$this->assertFalse( License::verify( implode( '.', $parts ), $this->public_key ) );
	}

	public function test_wrong_product_fails() {
		$payload      = $this->valid_payload();
		$payload['p'] = 'some-other-plugin';

		$this->assertFalse( License::verify( $this->make_key( $payload ), $this->public_key ) );
	}

	public function test_wrong_public_key_fails() {
		$key   = $this->make_key( $this->valid_payload() );
		$other = sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( str_repeat( "\x02", SODIUM_CRYPTO_SIGN_SEEDBYTES ) ) );

		$this->assertFalse( License::verify( $key, $other ) );
	}

	public function test_garbage_returns_false() {
		$this->assertFalse( License::verify( 'not-a-key', $this->public_key ) );
		$this->assertFalse( License::verify( '', $this->public_key ) );
		$this->assertFalse( License::verify( 'SS1.aaa', $this->public_key ) );
	}

	public function test_whitespace_and_case_are_tolerated() {
		$key = $this->make_key( $this->valid_payload() );

		$this->assertIsArray( License::verify( "  \n" . $key . "  \t", $this->public_key ) );
	}

	public function test_same_email_always_mints_an_identical_key() {
		$this->assertSame(
			$this->make_key( $this->valid_payload() ),
			$this->make_key( $this->valid_payload() ),
			'Keys must be deterministic so they can be re-derived rather than stored.'
		);
	}

	public function test_revocation_hash_normalises_case_and_whitespace() {
		$this->assertSame(
			License::revocation_hash( 'buyer@example.com' ),
			License::revocation_hash( '  BUYER@Example.COM  ' )
		);
	}

	public function test_revocation_list_holds_hashes_not_addresses() {
		$this->assertSame( 64, strlen( License::revocation_hash( 'buyer@example.com' ) ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', License::revocation_hash( 'buyer@example.com' ) );
	}

	public function test_revoked_email_is_rejected() {
		$revoked = array( License::revocation_hash( 'buyer@example.com' ) => true );

		$this->assertTrue( License::is_revoked( 'buyer@example.com', $revoked ) );
		$this->assertFalse( License::is_revoked( 'someone@else.com', $revoked ) );
	}

	public function test_empty_revocation_list_revokes_nobody() {
		$this->assertFalse( License::is_revoked( 'buyer@example.com', array() ) );
	}

	public function test_base64url_roundtrip() {
		$raw = random_bytes( 64 );

		$this->assertSame( $raw, License::b64url_decode( License::b64url_encode( $raw ) ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter LicenseTest`
Expected: FAIL — `Error: Class "SlugSync\Pro\License" not found`

- [ ] **Step 3: Write the implementation**

`src/License.php`:

```php
<?php
/**
 * Offline license key verification.
 *
 * No network, no WordPress, no vendor API. A one-time purchase should still
 * work in ten years, which rules out depending on anyone's licensing service
 * staying online.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Parses and verifies Ed25519-signed license keys.
 */
final class License {

	const PREFIX  = 'SS1';
	const PRODUCT = 'slug-sync-pro';
	const VERSION = 1;

	/**
	 * Revoked licences, as SHA-256 hashes of the lowercased email.
	 *
	 * Hashes rather than addresses: buyers receive this source under the GPL, and
	 * shipping a plaintext list would publish the customer list along with it.
	 *
	 * Sharing cannot be prevented -- the plugin is GPL and runs on the buyer's own
	 * server, so any check is one edit away from removal. This exists for the rare
	 * case of a key posted publicly, and bites only sites that take updates.
	 * Generate entries with bin/revoke.php.
	 *
	 * @var array<string,bool>
	 */
	const REVOKED = array();

	/**
	 * Verify a license key against a raw 32-byte Ed25519 public key.
	 *
	 * @param string $key        Key as the buyer pasted it.
	 * @param string $public_key Raw binary public key.
	 * @return array|false Decoded payload, or false when the key is not valid.
	 */
	public static function verify( $key, $public_key ) {
		if ( ! is_string( $key ) || ! is_string( $public_key ) ) {
			return false;
		}

		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			return false;
		}

		$parts = explode( '.', trim( $key ) );

		if ( 3 !== count( $parts ) || self::PREFIX !== $parts[0] ) {
			return false;
		}

		$payload = self::b64url_decode( $parts[1] );
		$sig     = self::b64url_decode( $parts[2] );

		if ( '' === $payload || '' === $sig ) {
			return false;
		}

		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return false;
		}

		if ( ! sodium_crypto_sign_verify_detached( $sig, $payload, $public_key ) ) {
			return false;
		}

		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! isset( $data['p'], $data['v'], $data['e'] ) ) {
			return false;
		}

		if ( self::PRODUCT !== $data['p'] || self::VERSION !== (int) $data['v'] ) {
			return false;
		}

		if ( self::is_revoked( $data['e'] ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Whether a licensed email has been revoked.
	 *
	 * @param string     $email   Licensed email from the payload.
	 * @param array|null $revoked Override list, for tests. Defaults to self::REVOKED.
	 * @return bool
	 */
	public static function is_revoked( $email, array $revoked = null ) {
		$revoked = ( null === $revoked ) ? self::REVOKED : $revoked;

		if ( ! $revoked ) {
			return false;
		}

		return isset( $revoked[ self::revocation_hash( $email ) ] );
	}

	/**
	 * Hash an email the way the revocation list stores it.
	 *
	 * @param string $email Email address.
	 * @return string Lowercase hex SHA-256.
	 */
	public static function revocation_hash( $email ) {
		return hash( 'sha256', strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Base64url encode without padding.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	public static function b64url_encode( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode. Returns an empty string on malformed input.
	 *
	 * @param string $encoded Encoded text.
	 * @return string
	 */
	public static function b64url_decode( $encoded ) {
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9\-_]+$/', $encoded ) ) {
			return '';
		}

		$padded  = str_pad( strtr( $encoded, '-_', '+/' ), strlen( $encoded ) % 4 ? strlen( $encoded ) + 4 - strlen( $encoded ) % 4 : strlen( $encoded ), '=', STR_PAD_RIGHT );
		$decoded = base64_decode( $padded, true );

		return false === $decoded ? '' : $decoded;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter LicenseTest`
Expected: `OK (13 tests, ...)`

- [ ] **Step 5: Write the keypair generator**

`bin/make-keypair.php`:

```php
<?php
/**
 * Generates the Ed25519 keypair. Run once, ever.
 *
 * Usage: php bin/make-keypair.php
 *
 * @package Slug_Sync_Pro
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$dir = __DIR__ . '/../keys';

if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) {
	fwrite( STDERR, "Could not create keys/\n" );
	exit( 1 );
}

if ( file_exists( $dir . '/secret.key' ) ) {
	fwrite( STDERR, "keys/secret.key already exists. Refusing to overwrite it.\n" );
	exit( 1 );
}

$keypair = sodium_crypto_sign_keypair();
$secret  = sodium_crypto_sign_secretkey( $keypair );
$public  = sodium_crypto_sign_publickey( $keypair );

file_put_contents( $dir . '/secret.key', base64_encode( $secret ) );
chmod( $dir . '/secret.key', 0600 );

echo "Secret key written to keys/secret.key. Back it up offline; losing it means you cannot mint keys for existing customers.\n\n";
echo "Put this in slug-sync-pro.php as SLUG_SYNC_PRO_PUBLIC_KEY:\n\n";
echo base64_encode( $public ) . "\n";
```

- [ ] **Step 6: Generate the keypair and embed the public key**

```bash
cd ~/Desktop/slug-sync-pro
php bin/make-keypair.php
```

Copy the printed base64 string into `slug-sync-pro.php`, replacing `REPLACE_IN_TASK_6`.

Then back up `keys/secret.key` somewhere offline. It is already gitignored — confirm:

```bash
git status --porcelain keys/
```

Expected: no output.

- [ ] **Step 7: Write the signing script**

`bin/sign-key.php`:

```php
<?php
/**
 * Mints a license key for one buyer.
 *
 * Usage: php bin/sign-key.php buyer@example.com
 *
 * Called by hand, or by the purchase webhook once slugsync.com exists.
 *
 * @package Slug_Sync_Pro
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/../src/License.php';

use SlugSync\Pro\License;

$email = isset( $argv[1] ) ? trim( $argv[1] ) : '';

if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
	fwrite( STDERR, "Usage: php bin/sign-key.php buyer@example.com\n" );
	exit( 1 );
}

$secret_b64 = getenv( 'SLUG_SYNC_SECRET_KEY' );

if ( ! $secret_b64 ) {
	$path = __DIR__ . '/../keys/secret.key';

	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "No secret key. Set SLUG_SYNC_SECRET_KEY or create keys/secret.key.\n" );
		exit( 1 );
	}

	$secret_b64 = trim( file_get_contents( $path ) );
}

$secret = base64_decode( $secret_b64, true );

if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
	fwrite( STDERR, "Secret key is not a valid Ed25519 secret key.\n" );
	exit( 1 );
}

$payload = json_encode(
	array(
		'v' => License::VERSION,
		'p' => License::PRODUCT,
		'e' => strtolower( $email ),
	),
	JSON_UNESCAPED_SLASHES
);

$sig = sodium_crypto_sign_detached( $payload, $secret );

echo 'SS1.' . License::b64url_encode( $payload ) . '.' . License::b64url_encode( $sig ) . "\n";
```

- [ ] **Step 7b: Write the revocation helper**

`bin/revoke.php`:

```php
<?php
/**
 * Prints the revocation-list entry for one email.
 *
 * Usage: php bin/revoke.php leaked@example.com
 *
 * Paste the printed line into License::REVOKED and ship a plugin update. Only
 * sites that take the update are affected, and anyone willing to edit the file
 * is unaffected entirely -- this is a lever for a key posted publicly, not a
 * copy-protection scheme.
 *
 * @package Slug_Sync_Pro
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/../src/License.php';

use SlugSync\Pro\License;

$email = isset( $argv[1] ) ? trim( $argv[1] ) : '';

if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
	fwrite( STDERR, "Usage: php bin/revoke.php leaked@example.com\n" );
	exit( 1 );
}

printf( "'%s' => true, // %s\n", License::revocation_hash( $email ), $email );
```

Note the trailing comment carries the plaintext address. Strip it before committing if you would rather not keep the mapping in the repository; the hash alone is what the check uses.

- [ ] **Step 8: Verify a real key end to end**

```bash
php bin/sign-key.php test@example.com
```

Expected: a single line starting `SS1.`. Copy it, then confirm it verifies against the embedded public key:

```bash
php -r '
require "src/License.php";
$pub = base64_decode( trim( shell_exec( "grep SLUG_SYNC_PRO_PUBLIC_KEY slug-sync-pro.php | head -1 | cut -d\"'\''\" -f4" ) ) );
var_dump( SlugSync\Pro\License::verify( trim( fgets( STDIN ) ), $pub ) );
'
```

Paste the key and press Enter.
Expected: an array containing `["e"]=> string(16) "test@example.com"`.

- [ ] **Step 9: Commit**

```bash
git add src/License.php tests/LicenseTest.php bin/ slug-sync-pro.php
git commit -m "feat: offline Ed25519 license keys with signing tools"
```

---

### Task 7: License screen and stored license state

**Goal:** A place for the buyer to paste their key, and one function the rest of the plugin asks whether it may run.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/Settings.php`

**Acceptance Criteria:**
- [ ] The screen lives at Tools → Slug Sync Pro, restricted to `manage_options`, nonce-protected
- [ ] A valid key saves and shows the licensed email
- [ ] An invalid key is rejected with a clear message and is not stored
- [ ] `Settings::is_licensed()` returns false when no key is stored, and re-verifies the stored key rather than trusting a stored boolean
- [ ] No `wp_remote_*` call anywhere in this file

**Verify:** `grep -c "wp_remote_" src/Settings.php` → `0`

**Steps:**

- [ ] **Step 1: Write `src/Settings.php`**

```php
<?php
/**
 * License screen and license state.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Admin screen for the license key and the rules.
 */
final class Settings {

	const CAP        = 'manage_options';
	const PAGE       = 'slug-sync-pro';
	const KEY_OPTION = 'slug_sync_pro_license_key';

	/**
	 * Hook the admin screen.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Register the screen under Tools.
	 */
	public static function menu() {
		add_management_page(
			__( 'Slug Sync Pro', 'slug-sync-pro' ),
			__( 'Slug Sync Pro', 'slug-sync-pro' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The stored license payload, or false.
	 *
	 * Re-verified on every call rather than cached as a boolean, so editing the
	 * option in the database does not licence the plugin.
	 *
	 * @return array|false
	 */
	public static function license() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$key = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $key ) || '' === $key ) {
			$cache = false;
			return $cache;
		}

		$public = base64_decode( SLUG_SYNC_PRO_PUBLIC_KEY, true );
		$cache  = $public ? License::verify( $key, $public ) : false;

		return $cache;
	}

	/**
	 * Whether the plugin may do anything.
	 *
	 * @return bool
	 */
	public static function is_licensed() {
		return false !== self::license();
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slug-sync-pro' ) );
		}

		$error = '';

		if ( isset( $_POST['slug_sync_pro_save'] ) ) {
			check_admin_referer( 'slug_sync_pro' );

			$submitted = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
			$public    = base64_decode( SLUG_SYNC_PRO_PUBLIC_KEY, true );

			if ( '' === $submitted ) {
				delete_option( self::KEY_OPTION );
			} elseif ( $public && false !== License::verify( $submitted, $public ) ) {
				update_option( self::KEY_OPTION, $submitted, false );
			} else {
				$error = __( 'That licence key is not valid. Copy it from your purchase email exactly, including the SS1 prefix.', 'slug-sync-pro' );
			}
		}

		$license = self::license();

		echo '<div class="wrap"><h1>' . esc_html__( 'Slug Sync Pro', 'slug-sync-pro' ) . '</h1>';

		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( $license ) {
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: %s: licensed email address. */
				esc_html__( 'Licensed to %s. Valid on every site you own, with no renewal.', 'slug-sync-pro' ),
				esc_html( $license['e'] )
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Slug Sync Pro is inactive until a licence key is entered. Slug Sync itself is unaffected and stays fully functional.', 'slug-sync-pro' ) .
				'</p></div>';
		}

		echo '<form method="post"><table class="form-table"><tr><th scope="row"><label for="slug-sync-pro-key">' .
			esc_html__( 'Licence key', 'slug-sync-pro' ) .
			'</label></th><td>';

		wp_nonce_field( 'slug_sync_pro' );

		printf(
			'<textarea id="slug-sync-pro-key" name="license_key" rows="3" class="large-text code" spellcheck="false">%s</textarea>',
			esc_textarea( (string) get_option( self::KEY_OPTION, '' ) )
		);

		echo '<p class="description">' .
			esc_html__( 'Paste the key from your purchase email. Verified on this site; nothing is sent anywhere. Clear the field and save to remove it.', 'slug-sync-pro' ) .
			'</p></td></tr></table>';

		submit_button( __( 'Save licence', 'slug-sync-pro' ), 'primary', 'slug_sync_pro_save' );

		echo '</form></div>';
	}
}
```

- [ ] **Step 2: Verify no network calls**

Run: `grep -c "wp_remote_" src/Settings.php`
Expected: `0`

- [ ] **Step 3: Verify manually**

Install both plugins on a local WordPress 6.5+ site and activate them. Go to Tools → Slug Sync Pro.
Expected: the warning notice, empty textarea.

Paste garbage, save.
Expected: the error notice, nothing stored.

Paste the key minted in Task 6 Step 8, save.
Expected: `Licensed to test@example.com.`

Clear the field, save.
Expected: back to the warning notice.

- [ ] **Step 4: Commit**

```bash
git add src/Settings.php
git commit -m "feat: license screen with offline verification"
```

---

### Task 8: Self-hosted updates from a static endpoint

**Goal:** Pro updates itself from a JSON file on slugsync.com, using core's own mechanism and no third-party updater library.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/Updater.php`
- Create: `~/Desktop/slug-sync-pro/tests/UpdaterTest.php`
- Create: `~/Desktop/slug-sync-pro/build/slug-sync-pro.json` (the file to publish later)

**Design note:** the endpoint is a static, unauthenticated JSON file, and the zip is served openly. Gating the download would make the endpoint dynamic and buy nothing — the plugin is GPL, so the zip cannot be meaningfully restricted anyway. The updater only runs at all when a valid licence is stored locally, which is the honest boundary.

**Acceptance Criteria:**
- [ ] `Updater::to_update()` returns `false` when the remote version is not newer
- [ ] It returns an array with `version`, `package`, `tested` and `requires_php` when it is newer
- [ ] Malformed or partial JSON returns `false` rather than a fatal
- [ ] The filter is not registered at all when the plugin is unlicensed
- [ ] Responses are cached for six hours

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit --filter UpdaterTest` → `OK (6 tests, ...)`

**Steps:**

- [ ] **Step 1: Write the failing tests**

`tests/UpdaterTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use SlugSync\Pro\Updater;

final class UpdaterTest extends TestCase {

	private function remote(): array {
		return array(
			'version'      => '1.1.0',
			'package'      => 'https://slugsync.com/downloads/slug-sync-pro-1.1.0.zip',
			'tested'       => '7.0',
			'requires'     => '6.5',
			'requires_php' => '7.4',
		);
	}

	public function test_newer_version_produces_an_update() {
		$update = Updater::to_update( $this->remote(), '1.0.0' );

		$this->assertIsArray( $update );
		$this->assertSame( '1.1.0', $update['version'] );
		$this->assertSame( 'https://slugsync.com/downloads/slug-sync-pro-1.1.0.zip', $update['package'] );
		$this->assertSame( '7.0', $update['tested'] );
		$this->assertSame( '7.4', $update['requires_php'] );
	}

	public function test_same_version_produces_no_update() {
		$this->assertFalse( Updater::to_update( $this->remote(), '1.1.0' ) );
	}

	public function test_older_remote_produces_no_update() {
		$this->assertFalse( Updater::to_update( $this->remote(), '2.0.0' ) );
	}

	public function test_missing_version_produces_no_update() {
		$remote = $this->remote();
		unset( $remote['version'] );

		$this->assertFalse( Updater::to_update( $remote, '1.0.0' ) );
	}

	public function test_missing_package_produces_no_update() {
		$remote = $this->remote();
		unset( $remote['package'] );

		$this->assertFalse( Updater::to_update( $remote, '1.0.0' ) );
	}

	public function test_non_https_package_is_rejected() {
		$remote            = $this->remote();
		$remote['package'] = 'http://slugsync.com/downloads/slug-sync-pro-1.1.0.zip';

		$this->assertFalse( Updater::to_update( $remote, '1.0.0' ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter UpdaterTest`
Expected: FAIL — `Error: Class "SlugSync\Pro\Updater" not found`

- [ ] **Step 3: Write the implementation**

`src/Updater.php`:

```php
<?php
/**
 * Updates served from slugsync.com via core's Update URI mechanism.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Hooks update_plugins_slugsync.com and maps the response for core.
 */
final class Updater {

	const ENDPOINT  = 'https://slugsync.com/updates/slug-sync-pro.json';
	const CACHE_KEY = 'slug_sync_pro_update';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Register the filter, but only for a licensed site.
	 */
	public static function init() {
		if ( ! Settings::is_licensed() ) {
			return;
		}

		add_filter( 'update_plugins_slugsync.com', array( __CLASS__, 'check' ), 10, 3 );
	}

	/**
	 * Answer core's update query for this plugin.
	 *
	 * @param array|false $update      Update array, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( SLUG_SYNC_PRO_BASENAME !== $plugin_file ) {
			return $update;
		}

		$remote = get_transient( self::CACHE_KEY );

		if ( false === $remote ) {
			$remote   = array();
			$response = wp_remote_get( self::ENDPOINT, array( 'timeout' => 10 ) );

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$remote  = is_array( $decoded ) ? $decoded : array();
			}

			set_transient( self::CACHE_KEY, $remote, self::CACHE_TTL );
		}

		$current = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : SLUG_SYNC_PRO_VERSION;
		$mapped  = self::to_update( (array) $remote, $current );

		return false === $mapped ? $update : $mapped;
	}

	/**
	 * Map a decoded endpoint response to core's update array.
	 *
	 * Pure: no WordPress, no network, so it is unit tested directly.
	 *
	 * @param array  $remote  Decoded JSON.
	 * @param string $current Installed version.
	 * @return array|false
	 */
	public static function to_update( array $remote, $current ) {
		if ( empty( $remote['version'] ) || empty( $remote['package'] ) ) {
			return false;
		}

		if ( 0 !== strpos( (string) $remote['package'], 'https://' ) ) {
			return false;
		}

		if ( version_compare( (string) $remote['version'], (string) $current, '<=' ) ) {
			return false;
		}

		return array(
			'slug'         => 'slug-sync-pro',
			'version'      => (string) $remote['version'],
			'package'      => (string) $remote['package'],
			'tested'       => isset( $remote['tested'] ) ? (string) $remote['tested'] : '',
			'requires'     => isset( $remote['requires'] ) ? (string) $remote['requires'] : '',
			'requires_php' => isset( $remote['requires_php'] ) ? (string) $remote['requires_php'] : '',
		);
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter UpdaterTest`
Expected: `OK (6 tests, 9 assertions)`

`HOUR_IN_SECONDS` is a WordPress constant and is not defined under PHPUnit. Because `to_update()` is static and the class constant is only evaluated when the class is loaded, add this to `tests/bootstrap.php` above the autoloader:

```php
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
```

- [ ] **Step 5: Create the endpoint file to publish**

`build/slug-sync-pro.json` — this is what gets uploaded to `https://slugsync.com/updates/slug-sync-pro.json` on every release:

```json
{
	"version": "1.0.0",
	"package": "https://slugsync.com/downloads/slug-sync-pro-1.0.0.zip",
	"tested": "7.0",
	"requires": "6.5",
	"requires_php": "7.4"
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Updater.php tests/UpdaterTest.php tests/bootstrap.php build/
git commit -m "feat: self-hosted updates via Update URI"
```

---

## Phase C — The product: the slug rules engine

### Task 9: Rules engine

**Goal:** The feature people actually pay for — rewrite a title so codes and filler words never reach the URL.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/Rules.php`
- Create: `~/Desktop/slug-sync-pro/tests/RulesTest.php`

**Order of operations** (fixed, and the tests depend on it): transliterate → find/replace → strip codes → strip stop words → cap word count → tidy separators.

**Acceptance Criteria:**
- [ ] Every rule is off or empty by default, so an unconfigured install changes nothing
- [ ] A title reduced to nothing by the rules falls back to the original title, never to an empty string
- [ ] Leftover separators from removed tokens are tidied rather than left as `-- --`
- [ ] `Rules::apply()` calls no WordPress function
- [ ] Short model numbers survive; long SKUs do not

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit --filter RulesTest` → `OK (12 tests, ...)`

**Steps:**

- [ ] **Step 1: Write the failing tests**

`tests/RulesTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use SlugSync\Pro\Rules;

final class RulesTest extends TestCase {

	public function test_defaults_change_nothing() {
		$title = 'Nike Air Max 90 - White - SKU 4823-BLK';

		$this->assertSame( $title, Rules::apply( $title, Rules::defaults() ) );
	}

	public function test_strip_codes_removes_sku_clause() {
		$config                = Rules::defaults();
		$config['strip_codes'] = true;

		$this->assertSame(
			'Nike Air Max 90 - White',
			Rules::apply( 'Nike Air Max 90 - White - SKU 4823-BLK', $config )
		);
	}

	public function test_strip_codes_removes_bare_alphanumeric_token() {
		$config                = Rules::defaults();
		$config['strip_codes'] = true;

		$this->assertSame( 'Wireless Mouse', Rules::apply( 'Wireless Mouse B09XYZ123', $config ) );
	}

	public function test_strip_codes_keeps_short_model_numbers() {
		$config                = Rules::defaults();
		$config['strip_codes'] = true;

		$this->assertSame( 'iPhone 15 Pro', Rules::apply( 'iPhone 15 Pro', $config ) );
	}

	public function test_strip_codes_removes_bracketed_reference() {
		$config                = Rules::defaults();
		$config['strip_codes'] = true;

		$this->assertSame( 'Desk Lamp', Rules::apply( 'Desk Lamp (AB-1234)', $config ) );
	}

	public function test_stopwords_are_removed() {
		$config              = Rules::defaults();
		$config['stopwords'] = array( 'the', 'of' );

		$this->assertSame(
			'Best Summer Collection',
			Rules::apply( 'The Best of the Summer Collection', $config )
		);
	}

	public function test_stopword_only_title_falls_back_to_original() {
		$config              = Rules::defaults();
		$config['stopwords'] = array( 'the', 'of', 'and' );

		$this->assertSame( 'The of and', Rules::apply( 'The of and', $config ) );
	}

	public function test_max_words_truncates() {
		$config              = Rules::defaults();
		$config['max_words'] = 3;

		$this->assertSame( 'Blue Cotton Shirt', Rules::apply( 'Blue Cotton Shirt Extra Large', $config ) );
	}

	public function test_max_words_zero_is_unlimited() {
		$config              = Rules::defaults();
		$config['max_words'] = 0;

		$this->assertSame( 'Blue Cotton Shirt Extra Large', Rules::apply( 'Blue Cotton Shirt Extra Large', $config ) );
	}

	public function test_replacements_are_applied_case_insensitively() {
		$config                 = Rules::defaults();
		$config['replacements'] = array( array( 'from' => 'nike', 'to' => '' ) );

		$this->assertSame( 'Air Max 90', Rules::apply( 'Nike Air Max 90', $config ) );
	}

	public function test_rules_combine_in_order() {
		$config = array(
			'transliterate' => false,
			'replacements'  => array( array( 'from' => 'Deluxe', 'to' => '' ) ),
			'strip_codes'   => true,
			'stopwords'     => array( 'the' ),
			'max_words'     => 4,
		);

		$this->assertSame(
			'Winter Jacket Waterproof Shell',
			Rules::apply( 'The Deluxe Winter Jacket Waterproof Shell Mens SKU 88213-XL', $config )
		);
	}

	public function test_non_string_input_is_safe() {
		$this->assertSame( '', Rules::apply( null, Rules::defaults() ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter RulesTest`
Expected: FAIL — `Error: Class "SlugSync\Pro\Rules" not found`

- [ ] **Step 3: Write the implementation**

`src/Rules.php`:

```php
<?php
/**
 * Title rewriting rules.
 *
 * Pure string work, deliberately free of WordPress, so the whole engine is unit
 * tested and cheap enough to run once per row on a large catalogue.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Rewrites a title before Slug Sync derives a slug from it.
 */
final class Rules {

	/** Punctuation trimmed from a token before it is classified. */
	const TRIM = " \t\n\r\0\x0B.,;:!?()[]{}\"'";

	/** Separator characters left stranded when a token is removed. */
	const SEPARATORS = " -\xE2\x80\x93\xE2\x80\x94|/,:;";

	/**
	 * The default configuration: every rule inert.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'transliterate' => false,
			'replacements'  => array(),
			'strip_codes'   => false,
			'stopwords'     => array(),
			'max_words'     => 0,
		);
	}

	/**
	 * Apply the configured rules to a title.
	 *
	 * @param string $title  Raw title.
	 * @param array  $config Rule configuration; see defaults().
	 * @return string Rewritten title, or the original when the rules empty it.
	 */
	public static function apply( $title, array $config ) {
		if ( ! is_string( $title ) ) {
			return '';
		}

		$config   = array_merge( self::defaults(), $config );
		$original = $title;
		$out      = $title;

		if ( ! empty( $config['transliterate'] ) ) {
			$out = Latinise::convert( $out );
		}

		if ( ! empty( $config['replacements'] ) ) {
			$out = self::replace( $out, (array) $config['replacements'] );
		}

		if ( ! empty( $config['strip_codes'] ) ) {
			$out = self::strip_codes( $out );
		}

		if ( ! empty( $config['stopwords'] ) ) {
			$out = self::strip_stopwords( $out, (array) $config['stopwords'] );
		}

		if ( (int) $config['max_words'] > 0 ) {
			$out = self::max_words( $out, (int) $config['max_words'] );
		}

		$out = self::tidy( $out );

		return '' === $out ? $original : $out;
	}

	/**
	 * Case-insensitive find and replace.
	 *
	 * @param string $title        Title.
	 * @param array  $replacements List of from/to pairs.
	 * @return string
	 */
	private static function replace( $title, array $replacements ) {
		foreach ( $replacements as $pair ) {
			if ( ! isset( $pair['from'] ) || '' === $pair['from'] ) {
				continue;
			}

			$to    = isset( $pair['to'] ) ? (string) $pair['to'] : '';
			$title = str_ireplace( (string) $pair['from'], $to, $title );
		}

		return $title;
	}

	/**
	 * Remove product codes, SKUs and bracketed references.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function strip_codes( $title ) {
		// "SKU 4823-BLK", "Ref: AB-9", "MPN #123".
		$title = preg_replace( '/\b(?:sku|mpn|ean|upc|asin|art|ref)\b\s*[:.#-]?\s*[A-Za-z0-9][A-Za-z0-9\-_\/]*/iu', ' ', $title );

		// A bracketed group containing a digit.
		$title = preg_replace( '/[\(\[\{][^\)\]\}]*\d[^\)\]\}]*[\)\]\}]/u', ' ', $title );

		$kept = array();

		foreach ( self::tokens( $title ) as $token ) {
			if ( ! self::is_code_token( trim( $token, self::TRIM ) ) ) {
				$kept[] = $token;
			}
		}

		return implode( ' ', $kept );
	}

	/**
	 * Whether a token reads as a machine code rather than a word.
	 *
	 * A short digit run is a model number worth keeping ("iPhone 15"), so only
	 * long runs qualify. Mixed letters and digits of four or more characters do.
	 *
	 * @param string $token Trimmed token.
	 * @return bool
	 */
	private static function is_code_token( $token ) {
		if ( '' === $token ) {
			return false;
		}

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
	 * Drop configured stop words.
	 *
	 * @param string $title     Title.
	 * @param array  $stopwords Words to drop.
	 * @return string
	 */
	private static function strip_stopwords( $title, array $stopwords ) {
		$drop = array();

		foreach ( $stopwords as $word ) {
			$word = trim( (string) $word );

			if ( '' !== $word ) {
				$drop[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
			}
		}

		if ( ! $drop ) {
			return $title;
		}

		$kept = array();

		foreach ( self::tokens( $title ) as $token ) {
			$bare  = trim( $token, self::TRIM );
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $bare, 'UTF-8' ) : strtolower( $bare );

			if ( '' !== $bare && in_array( $lower, $drop, true ) ) {
				continue;
			}

			$kept[] = $token;
		}

		return implode( ' ', $kept );
	}

	/**
	 * Keep at most the first N words.
	 *
	 * @param string $title Title.
	 * @param int    $max   Word cap.
	 * @return string
	 */
	private static function max_words( $title, $max ) {
		return implode( ' ', array_slice( self::tokens( $title ), 0, $max ) );
	}

	/**
	 * Collapse whitespace and the separators stranded by removed tokens.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function tidy( $title ) {
		$title = preg_replace( '/\s+/u', ' ', (string) $title );
		$title = preg_replace( '/(?:\s*[-\x{2013}\x{2014}|\/,:;]\s*){2,}/u', ' - ', $title );

		return trim( trim( $title ), self::SEPARATORS );
	}

	/**
	 * Split into whitespace-delimited tokens, dropping empties.
	 *
	 * @param string $title Title.
	 * @return string[]
	 */
	private static function tokens( $title ) {
		return (array) preg_split( '/\s+/u', (string) $title, -1, PREG_SPLIT_NO_EMPTY );
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter RulesTest`
Expected: `OK (12 tests, 12 assertions)`

`test_defaults_change_nothing` will fail if `tidy()` alters an untouched title. It must not: `tidy()` only collapses runs of two or more separators and trims the ends. If the assertion fails on the trailing `SKU 4823-BLK`, check that `SEPARATORS` is not trimming alphanumerics.

- [ ] **Step 5: Commit**

```bash
git add src/Rules.php tests/RulesTest.php
git commit -m "feat: slug rules engine with full unit coverage"
```

---

### Task 10: Transliteration for non-Latin titles

**Goal:** Turn `Кофеварка Bosch` into `kofevarka-bosch` instead of a percent-encoded URL.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/Latinise.php`
- Create: `~/Desktop/slug-sync-pro/tests/LatiniseTest.php`

**Naming note:** the class is `Latinise`, not `Transliterator`, because PHP's `intl` extension already owns the global `\Transliterator` class name and having both in scope is a readability trap.

**Acceptance Criteria:**
- [ ] Uses `intl`'s `Any-Latin; Latin-ASCII` when the extension is present
- [ ] Falls back to a bundled Cyrillic and Greek map when it is not
- [ ] `Latinise::fallback()` is public and tested against exact expected strings
- [ ] `Latinise::convert()` output is ASCII-only for Cyrillic and Greek input under either path
- [ ] Latin text passes through untouched

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit --filter LatiniseTest` → `OK (6 tests, ...)`

**Steps:**

- [ ] **Step 1: Write the failing tests**

`tests/LatiniseTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use SlugSync\Pro\Latinise;

final class LatiniseTest extends TestCase {

	public function test_latin_passes_through() {
		$this->assertSame( 'Blue Cotton Shirt', Latinise::convert( 'Blue Cotton Shirt' ) );
	}

	public function test_fallback_maps_cyrillic() {
		$this->assertSame( 'Kofevarka', Latinise::fallback( 'Кофеварка' ) );
	}

	public function test_fallback_maps_cyrillic_digraphs() {
		$this->assertSame( 'shchi', Latinise::fallback( 'щи' ) );
	}

	public function test_fallback_maps_greek() {
		$this->assertSame( 'Kafes', Latinise::fallback( 'Καφες' ) );
	}

	public function test_convert_produces_ascii_for_cyrillic() {
		$out = Latinise::convert( 'Кофеварка Bosch' );

		$this->assertSame( 1, preg_match( '/^[\x20-\x7E]+$/', $out ), 'Expected ASCII-only output, got: ' . $out );
	}

	public function test_non_string_is_safe() {
		$this->assertSame( '', Latinise::convert( null ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter LatiniseTest`
Expected: FAIL — `Error: Class "SlugSync\Pro\Latinise" not found`

- [ ] **Step 3: Write the implementation**

`src/Latinise.php`:

```php
<?php
/**
 * Non-Latin script to Latin.
 *
 * Slug Sync builds slugs with sanitize_title(), which folds Latin diacritics
 * through remove_accents() but percent-encodes anything outside the Latin
 * script. This runs before that, so Cyrillic and Greek titles produce readable
 * URLs instead of %D0%9A%D0%BE sequences.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Latinises text.
 */
final class Latinise {

	/**
	 * Convert using intl when available, the bundled map otherwise.
	 *
	 * @param string $text Text in any script.
	 * @return string
	 */
	public static function convert( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		if ( class_exists( '\Transliterator' ) ) {
			$intl = \Transliterator::create( 'Any-Latin; Latin-ASCII' );

			if ( $intl ) {
				$out = $intl->transliterate( $text );

				if ( is_string( $out ) && '' !== $out ) {
					return $out;
				}
			}
		}

		return self::fallback( $text );
	}

	/**
	 * Map-based transliteration, used when intl is absent.
	 *
	 * Public so it can be tested against exact strings regardless of whether the
	 * test machine has intl installed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fallback( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		return strtr( $text, self::map() );
	}

	/**
	 * Character map. Multi-character replacements are listed first because
	 * strtr() with an array prefers the longest match, not the first.
	 *
	 * @return array<string,string>
	 */
	private static function map() {
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
			// Serbian and Macedonian Cyrillic extras.
			'ђ' => 'dj', 'ј' => 'j', 'љ' => 'lj', 'њ' => 'nj', 'ћ' => 'c',
			'џ' => 'dz', 'ѓ' => 'g', 'ќ' => 'k', 'ѕ' => 'dz',
			'Ђ' => 'Dj', 'Ј' => 'J', 'Љ' => 'Lj', 'Њ' => 'Nj', 'Ћ' => 'C',
			'Џ' => 'Dz', 'Ѓ' => 'G', 'Ќ' => 'K', 'Ѕ' => 'Dz',
			// Ukrainian.
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter LatiniseTest`
Expected: `OK (6 tests, 6 assertions)`

If `test_fallback_maps_greek` fails, check the expected string against the map: `Κ`→`K`, `α`→`a`, `φ`→`f`, `ε`→`e`, `ς`→`s` gives `Kafes`. Fix the map, not the test.

- [ ] **Step 5: Commit**

```bash
git add src/Latinise.php tests/LatiniseTest.php
git commit -m "feat: transliteration with intl fast path and bundled fallback"
```

---

### Task 11: Wire the rules into Slug Sync

**Goal:** Connect the engine to the free plugin's filter, gated on a valid licence.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/Rewriter.php`
- Modify: `~/Desktop/slug-sync-pro/src/Settings.php` (add `rules()` and `RULES_OPTION`)
- Modify: `~/Desktop/slug-sync-pro/slug-sync-pro.php` (register `Rewriter::init()`)

**Acceptance Criteria:**
- [ ] With no licence, the filter is not registered and previews are byte-identical to free
- [ ] With a licence and default rules, previews are still byte-identical to free
- [ ] With a licence and `strip_codes` on, SKU-bearing titles produce shorter slugs
- [ ] The free plugin's `upsell_card()` stops rendering, because `has_filter( 'slug_sync_source_title' )` is now true

**Verify:** On a local site with both plugins active and a licence saved, a preview of a post titled `Wireless Mouse B09XYZ123` produces the slug `wireless-mouse`.

**Steps:**

- [ ] **Step 1: Add rule storage to Settings**

In `src/Settings.php`, add the constant beside `KEY_OPTION`:

```php
	const RULES_OPTION = 'slug_sync_pro_rules';
```

And add this method after `is_licensed()`:

```php
	/**
	 * The stored rule configuration, merged over the defaults.
	 *
	 * @return array
	 */
	public static function rules() {
		$stored = get_option( self::RULES_OPTION, array() );

		return array_merge( Rules::defaults(), is_array( $stored ) ? $stored : array() );
	}
```

- [ ] **Step 2: Create `src/Rewriter.php`**

```php
<?php
/**
 * Connects the rules engine to Slug Sync.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Registers the slug_sync_source_title filter.
 */
final class Rewriter {

	/**
	 * Hook the filter, but only on a licensed site.
	 *
	 * An unlicensed Pro does nothing at all: it does not degrade Slug Sync, and
	 * Slug Sync remains uncapped whether Pro is present or not.
	 */
	public static function init() {
		if ( ! Settings::is_licensed() ) {
			return;
		}

		add_filter( 'slug_sync_source_title', array( __CLASS__, 'rewrite' ), 10, 1 );
	}

	/**
	 * Rewrite one title.
	 *
	 * @param string $title Title as stored.
	 * @return string
	 */
	public static function rewrite( $title ) {
		return Rules::apply( $title, Settings::rules() );
	}
}
```

- [ ] **Step 3: Register it**

In `slug-sync-pro.php`, inside the `plugins_loaded` closure, add after `\SlugSync\Pro\Updater::init();`:

```php
		\SlugSync\Pro\Rewriter::init();
```

- [ ] **Step 4: Verify unlicensed behaviour**

On the local site, clear the licence key. Create a post titled `Wireless Mouse B09XYZ123` with the slug `old-slug`. Run a Preview on Posts.
Expected: `new_slug` is `wireless-mouse-b09xyz123`, and the free plugin's "About these titles" card appears.

- [ ] **Step 5: Verify licensed behaviour**

Save a valid licence key. Set the rules option directly for now:

```bash
wp option update slug_sync_pro_rules '{"strip_codes":true}' --format=json
```

Run the preview again.
Expected: `new_slug` is `wireless-mouse`, and the "About these titles" card no longer appears.

- [ ] **Step 6: Commit**

```bash
git add src/Rewriter.php src/Settings.php slug-sync-pro.php
git commit -m "feat: apply rules through slug_sync_source_title when licensed"
```

---

### Task 12: Rules screen

**Goal:** Let the buyer configure the rules without WP-CLI, on the same screen as the licence.

**Files:**
- Modify: `~/Desktop/slug-sync-pro/src/Settings.php` (`render()` and a new `save_rules()`)

**Acceptance Criteria:**
- [ ] The rules form only renders when licensed
- [ ] Saving persists `transliterate`, `strip_codes`, `stopwords`, `max_words` and `replacements`
- [ ] Stop words are entered one per line and stored as a clean array
- [ ] `max_words` is clamped to 0–20
- [ ] The form is nonce-protected and capability-checked
- [ ] The screen links back to Tools → Slug Sync so the buyer's next step is to run a preview

**Verify:** Save rules on the screen, then `wp option get slug_sync_pro_rules --format=json` → matches what was entered.

**Steps:**

- [ ] **Step 1: Add the save handler to `src/Settings.php`**

Add this method after `rules()`:

```php
	/**
	 * Persist the submitted rule configuration.
	 *
	 * Nonce and capability are checked by render() before this runs.
	 */
	private static function save_rules() {
		$stopwords = array();
		$raw       = isset( $_POST['stopwords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['stopwords'] ) ) : '';

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $word ) {
			$word = trim( $word );

			if ( '' !== $word ) {
				$stopwords[] = $word;
			}
		}

		$replacements = array();
		$from_list    = isset( $_POST['replace_from'] ) ? (array) wp_unslash( $_POST['replace_from'] ) : array();
		$to_list      = isset( $_POST['replace_to'] ) ? (array) wp_unslash( $_POST['replace_to'] ) : array();

		foreach ( $from_list as $index => $from ) {
			$from = sanitize_text_field( $from );

			if ( '' === $from ) {
				continue;
			}

			$replacements[] = array(
				'from' => $from,
				'to'   => isset( $to_list[ $index ] ) ? sanitize_text_field( $to_list[ $index ] ) : '',
			);
		}

		update_option(
			self::RULES_OPTION,
			array(
				'transliterate' => ! empty( $_POST['transliterate'] ),
				'strip_codes'   => ! empty( $_POST['strip_codes'] ),
				'stopwords'     => $stopwords,
				'max_words'     => min( 20, max( 0, isset( $_POST['max_words'] ) ? absint( $_POST['max_words'] ) : 0 ) ),
				'replacements'  => $replacements,
			),
			false
		);
	}
```

- [ ] **Step 2: Make the licence cache clearable, then call the save handler**

`license()` as written in Task 7 caches in a function-local `static`, which cannot be cleared. A key saved earlier in the same request would therefore not licence the rules save in that request. Replace the cache with a class property.

In `src/Settings.php`, add the property below the constants:

```php
	/**
	 * Verified licence payload for this request: array, false, or null when the
	 * option has not been read yet.
	 *
	 * @var array|false|null
	 */
	private static $cached = null;
```

Replace the whole `license()` method with:

```php
	/**
	 * The stored license payload, or false.
	 *
	 * Re-verified from the option rather than cached as a boolean, so editing
	 * the option in the database does not licence the plugin.
	 *
	 * @return array|false
	 */
	public static function license() {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$key = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $key ) || '' === $key ) {
			self::$cached = false;
			return self::$cached;
		}

		$public       = base64_decode( SLUG_SYNC_PRO_PUBLIC_KEY, true );
		self::$cached = $public ? License::verify( $key, $public ) : false;

		return self::$cached;
	}

	/**
	 * Forget the cached licence so the next call re-reads the option.
	 */
	private static function flush() {
		self::$cached = null;
	}
```

Then, inside the existing `if ( isset( $_POST['slug_sync_pro_save'] ) ) { ... }` block in `render()`, after the licence key handling and before the closing brace, add:

```php
			self::flush();

			if ( self::is_licensed() ) {
				self::save_rules();
			}
```

- [ ] **Step 3: Add the form to `render()`**

Immediately before the final `echo '</form></div>';`, insert:

```php
		if ( $license ) {
			$rules = self::rules();

			echo '<h2>' . esc_html__( 'Title rules', 'slug-sync-pro' ) . '</h2>';
			echo '<p class="description">' .
				esc_html__( 'These rules rewrite the title before Slug Sync builds a slug from it. Nothing is changed on your site until you run Apply in Slug Sync — preview first.', 'slug-sync-pro' ) .
				'</p>';

			echo '<table class="form-table">';

			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="strip_codes" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
				esc_html__( 'Product codes', 'slug-sync-pro' ),
				checked( ! empty( $rules['strip_codes'] ), true, false ),
				esc_html__( 'Remove SKUs, model codes and bracketed references', 'slug-sync-pro' ),
				esc_html__( 'Removes tokens like SKU 4823-BLK, B09XYZ123 and (AB-1234). Short model numbers such as “15” in “iPhone 15” are kept. Check the preview before applying.', 'slug-sync-pro' )
			);

			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="transliterate" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
				esc_html__( 'Non-Latin titles', 'slug-sync-pro' ),
				checked( ! empty( $rules['transliterate'] ), true, false ),
				esc_html__( 'Transliterate to Latin characters', 'slug-sync-pro' ),
				esc_html__( 'Turns Кофеварка into kofevarka rather than a percent-encoded URL.', 'slug-sync-pro' )
			);

			printf(
				'<tr><th scope="row"><label for="slug-sync-pro-stopwords">%s</label></th><td><textarea id="slug-sync-pro-stopwords" name="stopwords" rows="5" class="large-text code">%s</textarea><p class="description">%s</p></td></tr>',
				esc_html__( 'Filler words', 'slug-sync-pro' ),
				esc_textarea( implode( "\n", (array) $rules['stopwords'] ) ),
				esc_html__( 'One per line. Removed from the title before the slug is built. A title made only of these words is left alone.', 'slug-sync-pro' )
			);

			printf(
				'<tr><th scope="row"><label for="slug-sync-pro-max-words">%s</label></th><td><input type="number" id="slug-sync-pro-max-words" name="max_words" min="0" max="20" value="%d" class="small-text"><p class="description">%s</p></td></tr>',
				esc_html__( 'Maximum words', 'slug-sync-pro' ),
				absint( $rules['max_words'] ),
				esc_html__( '0 means no limit.', 'slug-sync-pro' )
			);

			echo '<tr><th scope="row">' . esc_html__( 'Find and replace', 'slug-sync-pro' ) . '</th><td>';

			$pairs = (array) $rules['replacements'];
			$pairs[] = array( 'from' => '', 'to' => '' ); // One blank row to add another.

			foreach ( $pairs as $pair ) {
				printf(
					'<p><input type="text" name="replace_from[]" value="%s" placeholder="%s" class="regular-text"> &rarr; <input type="text" name="replace_to[]" value="%s" placeholder="%s" class="regular-text"></p>',
					esc_attr( isset( $pair['from'] ) ? $pair['from'] : '' ),
					esc_attr__( 'Find', 'slug-sync-pro' ),
					esc_attr( isset( $pair['to'] ) ? $pair['to'] : '' ),
					esc_attr__( 'Replace with (leave empty to delete)', 'slug-sync-pro' )
				);
			}

			echo '<p class="description">' . esc_html__( 'Case insensitive. Applied before the other rules. Save to add another row.', 'slug-sync-pro' ) . '</p>';
			echo '</td></tr></table>';

			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'tools.php?page=slug-sync' ) ),
				esc_html__( 'Preview these rules in Slug Sync', 'slug-sync-pro' )
			);
		}
```

- [ ] **Step 4: Verify manually**

Go to Tools → Slug Sync Pro with a licence saved. Tick "Remove SKUs", enter `the` and `of` as filler words, set maximum words to 6, add a find/replace of `Deluxe` → empty. Save.

Run: `wp option get slug_sync_pro_rules --format=json`
Expected: `{"transliterate":false,"strip_codes":true,"stopwords":["the","of"],"max_words":6,"replacements":[{"from":"Deluxe","to":""}]}`

Then follow the "Preview these rules in Slug Sync" link and run a preview.
Expected: the new slugs reflect every rule.

- [ ] **Step 5: Commit**

```bash
git add src/Settings.php
git commit -m "feat: rules configuration screen"
```

---

---

## Phase D — Term and taxonomy slug sync (Pro v1.0)

**Why this is in Pro and not free:** the free plugin touches only `wp_posts`. Everything here is new code against `wp_terms` and `wp_term_taxonomy`, so nothing is being removed from or locked in the free plugin.

**Why it matters more than post slug sync:** WordPress records a post's previous slug in `_wp_old_slug` and quietly 301s the old URL. **There is no equivalent for terms.** Change a category slug and the old URL 404s — core will not redirect it. So the exported redirect map is not a convenience here, it is the only thing standing between the user and a wall of dead category URLs. Every screen in this phase must say so.

### Task 13: Term slug claim ledger

**Goal:** Collision-accurate slug claiming across a whole term run, so a preview matches what applying will actually produce.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/TermClaims.php`
- Create: `~/Desktop/slug-sync-pro/tests/TermClaimsTest.php`

**Why a ledger:** two categories named "Shoes" cannot both take `shoes`. `wp_unique_term_slug()` only knows what is already in the database, not what an earlier row in the same run has claimed, so a naive preview shows both taking `shoes` and the redirect map points at a URL that will never exist. The ledger tracks claims across the run, exactly as the free plugin does for posts.

**Acceptance Criteria:**
- [ ] First claimant of a slug gets it unchanged
- [ ] Second claimant gets `-2`, third gets `-3`
- [ ] A term re-claiming its own slug is idempotent, so resuming a run does not shift slugs
- [ ] A suffix already present in the ledger is skipped rather than reused
- [ ] An empty desired slug returns empty and claims nothing
- [ ] No WordPress function is called

**Verify:** `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit --filter TermClaimsTest` → `OK (7 tests, ...)`

**Steps:**

- [ ] **Step 1: Write the failing tests**

`tests/TermClaimsTest.php`:

```php
<?php

use PHPUnit\Framework\TestCase;
use SlugSync\Pro\TermClaims;

final class TermClaimsTest extends TestCase {

	public function test_first_claim_is_unchanged() {
		$claimed = array();

		$this->assertSame( 'shoes', TermClaims::claim( 'shoes', 10, $claimed ) );
		$this->assertSame( array( 'shoes' => 10 ), $claimed );
	}

	public function test_second_claimant_is_suffixed() {
		$claimed = array();

		TermClaims::claim( 'shoes', 10, $claimed );

		$this->assertSame( 'shoes-2', TermClaims::claim( 'shoes', 11, $claimed ) );
	}

	public function test_third_claimant_continues_the_sequence() {
		$claimed = array();

		TermClaims::claim( 'shoes', 10, $claimed );
		TermClaims::claim( 'shoes', 11, $claimed );

		$this->assertSame( 'shoes-3', TermClaims::claim( 'shoes', 12, $claimed ) );
	}

	public function test_reclaiming_own_slug_is_idempotent() {
		$claimed = array();

		TermClaims::claim( 'shoes', 10, $claimed );

		$this->assertSame( 'shoes', TermClaims::claim( 'shoes', 10, $claimed ) );
		$this->assertCount( 1, $claimed );
	}

	public function test_existing_suffix_is_skipped_not_reused() {
		$claimed = array( 'shoes' => 10, 'shoes-2' => 99 );

		$this->assertSame( 'shoes-3', TermClaims::claim( 'shoes', 11, $claimed ) );
	}

	public function test_empty_desired_claims_nothing() {
		$claimed = array();

		$this->assertSame( '', TermClaims::claim( '', 10, $claimed ) );
		$this->assertSame( array(), $claimed );
	}

	public function test_unrelated_slugs_do_not_collide() {
		$claimed = array();

		$this->assertSame( 'shoes', TermClaims::claim( 'shoes', 10, $claimed ) );
		$this->assertSame( 'boots', TermClaims::claim( 'boots', 11, $claimed ) );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter TermClaimsTest`
Expected: FAIL — `Error: Class "SlugSync\Pro\TermClaims" not found`

- [ ] **Step 3: Write the implementation**

`src/TermClaims.php`:

```php
<?php
/**
 * Slug claim ledger for a term run.
 *
 * wp_unique_term_slug() only sees the database. It cannot see a slug claimed by
 * an earlier row in the same run, so without this a preview reports two terms
 * taking the same slug and the redirect map points somewhere that never exists.
 *
 * Pure by design: no WordPress, so the collision behaviour is unit tested.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Tracks which slugs a run has already handed out.
 */
final class TermClaims {

	/**
	 * Claim a slug for a term, suffixing it when it is already taken.
	 *
	 * @param string             $desired Desired slug, already sanitised.
	 * @param int                $term_id Term claiming it.
	 * @param array<string,int> $claimed Ledger of slug => term_id, modified in place.
	 * @return string The slug actually claimed, or an empty string.
	 */
	public static function claim( $desired, $term_id, array &$claimed ) {
		$desired = (string) $desired;
		$term_id = (int) $term_id;

		if ( '' === $desired ) {
			return '';
		}

		if ( ! isset( $claimed[ $desired ] ) || $claimed[ $desired ] === $term_id ) {
			$claimed[ $desired ] = $term_id;

			return $desired;
		}

		$n = 2;

		do {
			$try = $desired . '-' . $n;
			$n++;
		} while ( isset( $claimed[ $try ] ) && $claimed[ $try ] !== $term_id );

		$claimed[ $try ] = $term_id;

		return $try;
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter TermClaimsTest`
Expected: `OK (7 tests, 9 assertions)`

---

### Task 14: Term sync engine

**Goal:** Scan a taxonomy in batches, build new slugs through the same rules engine, and write a changes report plus a redirect map — preview first, apply second.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/TermSync.php`
- Create: `~/Desktop/slug-sync-pro/src/Reports.php`

**Acceptance Criteria:**
- [ ] Preview writes no changes and produces a CSV of every term it would alter
- [ ] The same `Rules` configuration used for posts applies to term names
- [ ] Claims are tracked across the whole run via `TermClaims`, so the preview matches what apply produces
- [ ] A redirect map CSV is always produced, with no header row
- [ ] Reports live in a directory with a random name, with `index.html` and `.htaccess` guards, mirroring how the free plugin protects its reports
- [ ] Keyset pagination on `term_id`, so a term added mid-run cannot cause a skip
- [ ] Runs only when licensed

**Verify:** Manual — Preview on Categories produces a CSV whose `new_slug` column matches the applied result on a second run. (No local WordPress on this machine; see the deferred verification checklist.)

**Steps:**

- [ ] **Step 1: Write `src/Reports.php`**

```php
<?php
/**
 * Report files for term runs.
 *
 * The directory name carries a random token so the CSVs are not enumerable,
 * matching how the free plugin protects its own reports.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Creates and locates report files.
 */
final class Reports {

	const TOKEN_OPTION = 'slug_sync_pro_token';

	/**
	 * Absolute path to the report directory, creating it when needed.
	 *
	 * @return string Path with a trailing slash, or an empty string on failure.
	 */
	public static function dir() {
		$token = get_option( self::TOKEN_OPTION );

		if ( ! $token ) {
			$token = strtolower( wp_generate_password( 12, false, false ) );
			update_option( self::TOKEN_OPTION, $token, false );
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'slug-sync-pro-' . $token . '/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Directory listing and direct access guards, written once.
		if ( ! is_file( $dir . 'index.html' ) ) {
			file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( ! is_file( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Options -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Path to one report file for a run.
	 *
	 * @param string $run_id Run identifier.
	 * @param string $which  'changes' or 'redirects'.
	 * @return string Empty string when the directory is unavailable.
	 */
	public static function path( $run_id, $which ) {
		$dir = self::dir();

		if ( '' === $dir ) {
			return '';
		}

		$which  = ( 'redirects' === $which ) ? 'redirects' : 'changes';
		$run_id = sanitize_key( $run_id );

		return $dir . 'terms-' . $which . '-' . $run_id . '.csv';
	}
}
```

- [ ] **Step 2: Write `src/TermSync.php`**

```php
<?php
/**
 * Term and taxonomy slug sync.
 *
 * Core records a post's old slug in _wp_old_slug and 301s the old URL. There is
 * no such mechanism for terms: renaming a category breaks its URL outright. The
 * redirect map produced here is therefore the only protection the user has, and
 * every screen that triggers a run has to say so.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Runs term slug rewrites in batches.
 */
final class TermSync {

	const BATCH = 100;

	/**
	 * Selectable taxonomies.
	 *
	 * @return \WP_Taxonomy[] Keyed by taxonomy name.
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		// Formats have no user-facing slug worth rewriting.
		unset( $taxonomies['post_format'] );

		return $taxonomies;
	}

	/**
	 * Process one batch of a run.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $run_id   Run identifier.
	 * @param int    $after    Last term_id processed; 0 starts a run.
	 * @param bool   $apply    True to write, false to preview.
	 * @param array  $claimed  Claim ledger, modified in place.
	 * @return array{last:int,done:int,changed:int,errors:int,finished:bool,log:string[]}
	 */
	public static function batch( $taxonomy, $run_id, $after, $apply, array &$claimed ) {
		global $wpdb;

		$taxonomy = sanitize_key( $taxonomy );
		$result   = array(
			'last'     => (int) $after,
			'done'     => 0,
			'changed'  => 0,
			'errors'   => 0,
			'finished' => true,
			'log'      => array(),
		);

		$changes_path   = Reports::path( $run_id, 'changes' );
		$redirects_path = Reports::path( $run_id, 'redirects' );

		if ( '' === $changes_path || '' === $redirects_path ) {
			$result['errors'] = 1;
			$result['log'][]  = __( 'The uploads directory is not writable, so no report could be created.', 'slug-sync-pro' );

			return $result;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug
				 FROM {$wpdb->terms} t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = %s AND t.term_id > %d
				 ORDER BY t.term_id ASC
				 LIMIT %d",
				$taxonomy,
				(int) $after,
				self::BATCH
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$first   = ( 0 === (int) $after );
		$mode    = $first ? 'w' : 'a';
		$changes = fopen( $changes_path, $mode );   // phpcs:ignore WordPress.WP.AlternativeFunctions
		$redirs  = fopen( $redirects_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $changes || ! $redirs ) {
			if ( $changes ) {
				fclose( $changes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( $redirs ) {
				fclose( $redirs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			$result['errors'] = 1;
			$result['log'][]  = __( 'The report files could not be opened.', 'slug-sync-pro' );

			return $result;
		}

		if ( $first ) {
			fwrite( $changes, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fputcsv( $changes, array( 'term_id', 'taxonomy', 'name', 'old_slug', 'new_slug', 'old_url', 'new_url', 'note' ), ',', '"', '' );
			// No header on the redirect file: some importers read one as a live redirect.
		}

		$rules = Settings::rules();

		foreach ( (array) $rows as $row ) {
			$result['last'] = (int) $row->term_id;
			$result['done']++;

			$target = sanitize_title( Rules::apply( $row->name, $rules ) );

			if ( '' === $target || $row->slug === $target ) {
				continue;
			}

			$term = get_term( (int) $row->term_id, $taxonomy );

			if ( ! $term || is_wp_error( $term ) ) {
				$result['errors']++;
				continue;
			}

			// Database uniqueness first, then uniqueness against this run.
			$unique = wp_unique_term_slug( $target, $term );
			$new    = TermClaims::claim( $unique, (int) $row->term_id, $claimed );

			if ( '' === $new || $new === $row->slug ) {
				continue;
			}

			$old_url = get_term_link( $term );
			$old_url = is_wp_error( $old_url ) ? '' : $old_url;
			$note    = ( $new !== $target ) ? __( 'duplicate name, suffixed', 'slug-sync-pro' ) : '';

			if ( $apply ) {
				$updated = wp_update_term( (int) $row->term_id, $taxonomy, array( 'slug' => $new ) );

				if ( is_wp_error( $updated ) ) {
					$result['errors']++;
					/* translators: 1: term ID, 2: error message. */
					$result['log'][] = sprintf( __( '#%1$d failed: %2$s', 'slug-sync-pro' ), $row->term_id, $updated->get_error_message() );
					continue;
				}

				$new_url = get_term_link( (int) $row->term_id, $taxonomy );
				$new_url = is_wp_error( $new_url ) ? '' : $new_url;
			} else {
				$new_url = self::preview_url( $old_url, $row->slug, $new );
			}

			fputcsv(
				$changes,
				array( $row->term_id, $taxonomy, $row->name, $row->slug, $new, $old_url, $new_url, $note ),
				',',
				'"',
				''
			);

			if ( '' !== $old_url && '' !== $new_url ) {
				fputcsv( $redirs, array( wp_make_link_relative( $old_url ), wp_make_link_relative( $new_url ) ), ',', '"', '' );
			}

			$result['changed']++;
			$result['log'][] = sprintf( '#%d  %s  ->  %s%s', $row->term_id, $row->slug, $new, $note ? '   [' . $note . ']' : '' );
		}

		fclose( $changes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $redirs );  // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result['finished'] = count( (array) $rows ) < self::BATCH;

		return $result;
	}

	/**
	 * Work out the URL a term would get, without writing anything.
	 *
	 * Only the final path segment is substituted, so a child term that repeats
	 * its parent's slug does not rewrite the parent as well.
	 *
	 * @param string $old_url  Current term link.
	 * @param string $old_slug Current slug.
	 * @param string $new_slug Proposed slug.
	 * @return string
	 */
	private static function preview_url( $old_url, $old_slug, $new_slug ) {
		if ( '' === $old_url || '' === $old_slug ) {
			return $old_url;
		}

		$trailing = ( '/' === substr( $old_url, -1 ) );
		$padded   = $trailing ? $old_url : $old_url . '/';
		$needle   = '/' . $old_slug . '/';
		$position = strrpos( $padded, $needle );

		if ( false === $position ) {
			return $old_url;
		}

		$swapped = substr_replace( $padded, '/' . $new_slug . '/', $position, strlen( $needle ) );

		return $trailing ? $swapped : rtrim( $swapped, '/' );
	}
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/TermSync.php && php -l src/Reports.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Record the manual verification**

No local WordPress exists on this machine. Append these to `docs/manual-verification.md` in the Pro repo, creating it if needed:

```markdown
## Task 14 — term sync engine

1. Create two categories both named "Shoes"; give each a slug that does not match.
2. Preview on Categories. The changes CSV must show the lower term_id taking `shoes` and the other `shoes-2`, both flagged in the note column.
3. Apply. The resulting slugs must be exactly what the preview reported.
4. Open the redirect CSV. It must have no header row and contain relative source/target pairs.
5. Visit an old category URL. It must 404 — core does not redirect terms. Import the redirect CSV into a redirect plugin, then confirm the old URL resolves.
```

---

### Task 15: Term sync screen and undo

**Goal:** Drive the term engine from an admin screen, with the redirect warning made unmissable and a per-run undo.

**Files:**
- Create: `~/Desktop/slug-sync-pro/src/TermScreen.php`
- Modify: `~/Desktop/slug-sync-pro/slug-sync-pro.php` (register `TermScreen::init()`)

**Acceptance Criteria:**
- [ ] Screen at Tools → Slug Sync Pro, in a "Categories and tags" section, licensed users only
- [ ] Taxonomy picker, Preview and Apply, batched with automatic continuation
- [ ] Apply is gated behind a confirmation that states plainly that WordPress does not redirect old term URLs
- [ ] Claim ledger persists between batches so resuming does not shift slugs
- [ ] Both report CSVs are downloadable through an `admin_post` handler with a nonce and capability check, never by direct file URL
- [ ] Undo restores the slugs recorded in a run's changes CSV, skipping any term edited since

**Verify:** Manual — see the checklist appended in Step 4.

**Steps:**

- [ ] **Step 1: Write `src/TermScreen.php`**

Implement a class with this exact shape. Each method's contract:

```php
<?php
/**
 * Admin screen for term slug sync.
 *
 * @package Slug_Sync_Pro
 */

namespace SlugSync\Pro;

/**
 * Renders and drives term runs.
 */
final class TermScreen {

	const CAP         = 'manage_options';
	const STATE_OPTION = 'slug_sync_pro_term_run';
	const CLAIM_PREFIX = 'slug_sync_pro_term_claims_';

	/**
	 * Hook the download handler. The screen itself is rendered by Settings.
	 */
	public static function init() {
		add_action( 'admin_post_slug_sync_pro_term_download', array( __CLASS__, 'download' ) );
	}

	/**
	 * Current run state, or an empty array.
	 *
	 * Shape: array{id:string,taxonomy:string,apply:bool,last:int,done:int,
	 *              changed:int,errors:int,status:string,created:int}
	 *
	 * @return array
	 */
	public static function state() { /* get_option( self::STATE_OPTION, array() ) */ }

	/**
	 * Start a run, or continue the active one, and render progress.
	 *
	 * Verifies the slug_sync_pro_terms nonce and self::CAP before doing anything.
	 * Loads the claim ledger from a transient keyed by run id, calls
	 * TermSync::batch(), writes the ledger back, persists state, and renders a
	 * progress bar plus the batch log. When the run is not finished it prints a
	 * self-submitting form so the next batch starts automatically, matching how
	 * the free plugin continues its own runs.
	 */
	public static function run() { /* ... */ }

	/**
	 * Render the taxonomy picker, mode choice and the redirect warning.
	 *
	 * The warning is not a description paragraph; it is a notice-warning block
	 * reading: "WordPress does not redirect old category or tag URLs. Unlike
	 * posts, there is no built-in fallback. Import the redirect map this run
	 * produces into a redirect plugin, or the old URLs will 404."
	 */
	public static function form() { /* ... */ }

	/**
	 * Undo one completed run by restoring the old slugs from its changes CSV.
	 *
	 * Reads the CSV row by row. For each row, re-reads the term and skips it
	 * when its current slug no longer matches the new_slug the run recorded,
	 * so a term edited since the run is left alone rather than overwritten.
	 * Reports how many were restored and how many were skipped.
	 */
	public static function undo() { /* ... */ }

	/**
	 * Stream a report file to the browser.
	 *
	 * Checks self::CAP and the slug_sync_pro_download nonce, resolves the path
	 * through Reports::path() rather than accepting one from the request, and
	 * sends Content-Type: text/csv with Content-Disposition: attachment.
	 */
	public static function download() { /* ... */ }
}
```

Write the full bodies following the free plugin's equivalents in `slug-sync.php` as the reference for style and safety: `run_history()` for the progress markup, `download_report()` for the streaming handler, and `rollback()` for the skip-if-edited undo logic. Match its escaping discipline — every echoed value passes through `esc_html()`, `esc_attr()` or `esc_url()`.

- [ ] **Step 2: Register the screen**

In `slug-sync-pro.php`, inside the `plugins_loaded` closure, after `\SlugSync\Pro\Rewriter::init();`:

```php
		\SlugSync\Pro\TermScreen::init();
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/TermScreen.php`
Expected: `No syntax errors detected in src/TermScreen.php`

- [ ] **Step 4: Record the manual verification**

Append to `docs/manual-verification.md`:

```markdown
## Task 15 — term screen and undo

1. Unlicensed: the Categories and tags section must not render at all.
2. Licensed: pick Categories, run Preview. Progress advances in batches without clicking.
3. The Apply confirmation must state that WordPress does not redirect old term URLs.
4. Apply, then use Undo. Old slugs are restored.
5. Apply again, hand-edit one category's slug, then Undo. That one term must be skipped and reported as skipped, not overwritten.
6. Request a report URL while logged out. It must be refused, not served.
```

## Release checklist

- [ ] `cd ~/Desktop/slug-sync-premium && vendor/bin/phpunit` → all green
- [ ] `cd ~/Desktop/slug-sync-pro && vendor/bin/phpunit` → all green (License, Updater, Rules, Latinise, TermClaims)
- [ ] `docs/manual-verification.md` in the Pro repo worked through on a real WordPress install
- [ ] `grep -rnE "wp_remote_|curl_" slug-sync.php includes/` in the free plugin → no matches
- [ ] `grep -rn "license\|licence" slug-sync.php includes/` in the free plugin → no matches
- [ ] Free plugin zip built with `.distignore` applied; confirm `docs/`, `tests/`, `vendor/`, `composer.json` are absent from the zip
- [ ] Free plugin submitted to wordpress.org and **approved** before Pro is announced anywhere
- [ ] `keys/secret.key` backed up offline and confirmed absent from `git log --all --stat`
- [ ] Pro zip built and uploaded; `build/slug-sync-pro.json` published at the endpoint with a matching version
- [ ] A test purchase mints a key that verifies on a clean site

## Pro feature roster

Every feature considered for the paid tier, with its verdict. Recorded here so a future change is a deliberate decision rather than a rediscovery.

### Shipping in Pro v1.0

| Feature | Tasks | Note |
|---|---|---|
| Slug rules engine — code/SKU stripping, transliteration, filler words, word cap, find/replace | 9–12 | **Code and SKU stripping is the only genuinely differentiated rule.** Nothing free does it, and the "keep `iPhone 15`, drop `B09XYZ123`" distinction is the fiddly part people won't snippet correctly. Lead the marketing on this one. |
| Term and taxonomy slug sync | 13–15 | Core has no `_wp_old_slug` equivalent for terms, so renaming a category 404s its URL with no fallback. The redirect map is mandatory here, not a bonus. Free touches only `wp_posts`, so this is new code, not a removal. |

### v1.1 candidates, ranked by how much each broadens who would pay

| Feature | Why | Effort |
|---|---|---|
| Redirect delivery — write redirects directly into Redirection, Rank Math or Yoast Premium, plus `.htaccess` and nginx map output | Additive. The free CSV export stays free; Pro removes the round-trip. Pairs naturally with term sync, where redirects are mandatory. | Medium |
| Slug templates from custom fields — `{title}-{sku}`, `{brand}-{title}` | The inverse of code stripping: some stores want the SKU *in* the URL. Reads post meta, so genuinely new code, and it reuses the rules pipeline. | Medium |
| CSV slug overrides — upload an ID → slug mapping | Anyone migrating off Shopify or Magento already has that file. Strong for the agency segment, cleanly separate code. | Small |
| Media and attachment slugs | Free explicitly does `unset( $types['attachment'] )` at `slug-sync.php:165`, so this is an existing gap rather than a removal. Attachments carry file-path concerns posts don't. | Medium |
| WP-CLI command | Small audience, but the one that buys without hesitating. Needed above roughly 50k items where the browser loop is the wrong tool. | Small |
| Multisite network-wide runs | Agencies running networks. Free is per-site by design. | Medium |

### Deliberately excluded

| Feature | Why not |
|---|---|
| Scheduled / automatic sync | The only feature here with genuine recurring value — feed-driven stores re-import weekly and re-break their slugs every time. Selling it once, forever, at $6.99 is the decision you would regret in a year. It is a separate, higher-priced product later, not a bullet in this one. |

### Stays free permanently — do not move these to Pro

The window to make a scope removal is open only until first publication on wordpress.org, and under Guideline 5 a pre-publication removal would be legal. The reason not to is **commercial, not legal**: free's job is to rank, earn reviews and build trust, and every item below is load-bearing for that or for the plugin's core promise.

| Feature | Why it must stay free |
|---|---|
| Preview and its changes CSV | The trust builder, and the surface the upsell lives on. Metering it kills the funnel at its source. |
| `_wp_old_slug` redirects | This *is* the promise — "rewrite slugs without breaking indexed URLs". Removing it makes the free plugin dangerous. |
| Undo / rollback | Most tempting to move, and the worst idea. Removing it makes free more dangerous, contradicts the readme's whole "safe on a live site" argument, and invites one-star reviews plus a support queue. |
| Redirect map export | Derivable from the changes CSV with a spreadsheet filter and a domain strip. Paywalling data you already handed over is bypassable by the competent, who resent it, and obstructive to everyone else. Upgrade it in Pro instead — see redirect delivery above. |
| Quiet writes | Only matters on large catalogues, which is the paying segment — but without it a 5,000-product run fires 5,000 webhooks and restamps every `post_modified`. Free would actively harm the exact audience whose reviews you need. |
| All public post types, including WooCommerce products | Products are the main audience. Once products are free, restricting only exotic CPTs affects few people and gains almost nothing. |
| Batching, resume and the run lock | Without these the free plugin fails on precisely the sites that need it most. |

### Competitive context

Checked on wordpress.org while scoping the paid tier. This is why the rules engine leads on code stripping rather than transliteration.

| Plugin | Overlap | Consequence |
|---|---|---|
| [Cyr-To-Lat](https://wordpress.org/plugins/cyr2lat/) | Free transliteration with a fully editable conversion table across Russian, Ukrainian, Bulgarian, Macedonian, Serbian, Greek, Armenian, Georgian, Kazakh, Hebrew and Chinese. The editable table also covers the find/replace use case. | Do not sell transliteration as a headline feature — it loses the comparison. Keep it as a supporting bullet. |
| [Cyrlitera](https://en-gb.wordpress.org/plugins/cyrlitera/) | Free transliteration **plus** automatic redirects **plus** rollback. | Closest competitor in the Cyrillic niche; it has the safety story too. Avoid competing there. |
| [Simple Regenerate Slug](https://wordpress.org/plugins/simple-regenerate-slug/) | Free bulk slug regeneration from titles via bulk actions. | No preview, no redirect map, no undo, no batching. This is what free Slug Sync beats on safety. |
| [Permalink Manager Lite](https://wordpress.org/plugins/permalink-manager/) | Freemium bulk URL editing. | Different problem — manual URL control rather than title-derived slugs. |

**The moat is the combination, not the rules.** Cyr-To-Lat transliterates on save and will not rewrite 3,000 existing products; Simple Regenerate Slug bulk-rewrites with no preview, redirect map or undo. Nobody ships rules *and* a safe bulk engine. That sentence is what the website should be built around.

## Deferred infrastructure

| Item | Why it is not here |
|---|---|
| slugsync.com | Being built later. Until it exists, keys are minted by hand with `bin/sign-key.php` and emailed. |
| Lemon Squeezy checkout and purchase webhook | Depends on the site. The webhook is one function that shells out to `bin/sign-key.php`. |
