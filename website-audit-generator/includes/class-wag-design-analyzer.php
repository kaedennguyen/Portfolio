<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural design/UX analysis — no screenshots, works purely from
 * HTML structure + PageSpeed's accessibility/best-practices categories.
 * This critiques structural design signals, not visual aesthetics.
 */
class WAG_Design_Analyzer {

	/**
	 * Builds the prompt/schema for the Claude call, without making it.
	 * Needs no pagespeed data — purely HTML-structure based.
	 */
	public function prepare( WAG_Html_Parser $parser ) {
		$dom          = $parser->get_dom();
		$headings     = $parser->get_headings();
		$has_nav      = $dom->getElementsByTagName( 'nav' )->length > 0;
		$button_count = $dom->getElementsByTagName( 'button' )->length;
		$form_count   = $dom->getElementsByTagName( 'form' )->length;
		$title        = $parser->get_title();
		$excerpt      = mb_substr( $parser->get_visible_text(), 0, 3000 );

		$structure_summary = 'Has <nav> element: ' . ( $has_nav ? 'yes' : 'no' ) . ". Button elements: {$button_count}. Form elements: {$form_count}. "
			. 'Heading counts — H1: ' . count( $headings['h1'] ) . ', H2: ' . count( $headings['h2'] ) . ', H3: ' . count( $headings['h3'] ) . '.';

		$schema_hint = '{"design_structure_score": <integer 0-100>, "whats_working": ["<short point>", ...], "whats_not_working": ["<short point>", ...], "recommendations": ["<short actionable recommendation>", ...]}';

		$prompt = "Based only on this page's HTML structure (no visual screenshot available), critique its structural design and UX: navigation clarity, presence of clear calls to action, form usability, and overall information architecture implied by the heading structure. Be clear that this is a structural read, not a visual design judgment.\n\n"
			. "Page title: {$title}\nStructure summary: {$structure_summary}\n\nVisible text excerpt (for context on likely page purpose):\n{$excerpt}";

		return array(
			'prompt' => $prompt,
			'schema' => $schema_hint,
		);
	}

	/**
	 * @param array $claude_outcome Already-parsed result from WAG_Claude_Api::parse_response()
	 */
	public function analyze( WAG_Html_Parser $parser, $pagespeed_data, $claude_outcome ) {
		$findings = array();
		$points   = 0;
		$max      = 0;

		// PageSpeed accessibility score.
		$max += 25;
		if ( $pagespeed_data && null !== $pagespeed_data['accessibility_score'] ) {
			$points += round( ( $pagespeed_data['accessibility_score'] / 100 ) * 25 );
			$findings[] = array(
				'type' => $pagespeed_data['accessibility_score'] >= 80 ? 'good' : 'warning',
				'text' => "Google accessibility score: {$pagespeed_data['accessibility_score']}/100.",
			);
		} else {
			$points += 12; // neutral fallback
		}

		// PageSpeed best-practices score.
		$max += 20;
		if ( $pagespeed_data && null !== $pagespeed_data['best_practices_score'] ) {
			$points += round( ( $pagespeed_data['best_practices_score'] / 100 ) * 20 );
			$findings[] = array(
				'type' => $pagespeed_data['best_practices_score'] >= 80 ? 'good' : 'warning',
				'text' => "Google best-practices score: {$pagespeed_data['best_practices_score']}/100 (covers image aspect ratios, console errors, HTTPS use, etc.).",
			);
		} else {
			$points += 10;
		}

		// Heading hierarchy sanity (skipped levels = poor structural design).
		$headings = $parser->get_headings();
		$max     += 10;
		$skip_found = false;
		$order      = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		$present    = array();
		foreach ( $order as $tag ) {
			$present[ $tag ] = count( $headings[ $tag ] ) > 0;
		}
		// A crude check: h3 present but h2 absent, etc.
		for ( $i = 1; $i < count( $order ); $i++ ) {
			if ( $present[ $order[ $i ] ] && ! $present[ $order[ $i - 1 ] ] ) {
				$skip_found = true;
			}
		}
		if ( $skip_found ) {
			$points     += 3;
			$findings[] = array( 'type' => 'warning', 'text' => 'Heading levels appear to skip a level (e.g. H3 used without an H2) — hurts both accessibility and visual hierarchy consistency.' );
		} else {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Heading hierarchy is logically ordered.' );
		}

		// Mobile viewport (also a design fundamental).
		$max += 10;
		if ( $parser->has_viewport_meta() ) {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Responsive viewport meta tag present.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No responsive viewport meta tag — layout is likely not mobile-optimized.' );
		}

		// Image alt text overlap (accessibility = design quality).
		$alt_stats = $parser->get_images_alt_stats();
		$max      += 10;
		if ( $alt_stats['total'] > 0 && $alt_stats['with_alt_ratio'] < 0.7 ) {
			$points     += round( $alt_stats['with_alt_ratio'] * 10 );
			$findings[] = array( 'type' => 'warning', 'text' => 'Missing alt text on multiple images affects both accessibility and how inclusive the design feels.' );
		} else {
			$points += 10;
		}

		// Fold in the already-fetched Claude structural critique (fetched concurrently by the engine).
		$claude_result = $claude_outcome;

		$max += 25;
		if ( $claude_result['success'] ) {
			$claude_score = isset( $claude_result['data']['design_structure_score'] ) ? intval( $claude_result['data']['design_structure_score'] ) : 50;
			$points      += round( ( $claude_score / 100 ) * 25 );

			foreach ( (array) ( $claude_result['data']['whats_working'] ?? array() ) as $w ) {
				$findings[] = array( 'type' => 'good', 'text' => sanitize_text_field( $w ) );
			}
			foreach ( (array) ( $claude_result['data']['whats_not_working'] ?? array() ) as $w ) {
				$findings[] = array( 'type' => 'issue', 'text' => sanitize_text_field( $w ) );
			}
			foreach ( (array) ( $claude_result['data']['recommendations'] ?? array() ) as $r ) {
				$findings[] = array( 'type' => 'improvement', 'text' => sanitize_text_field( $r ) );
			}
		} else {
			$points    += 12;
			$findings[] = array( 'type' => 'warning', 'text' => 'AI-based structural critique unavailable (' . esc_html( $claude_result['error'] ) . ').' );
		}

		$score = $max > 0 ? round( ( $points / $max ) * 100 ) : 0;

		return array(
			'score'    => min( 100, max( 0, $score ) ),
			'findings' => $findings,
			'note'     => 'Design analysis is structural (HTML-based) only — no visual/screenshot review is performed.',
		);
	}
}
