<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based SEO scoring. Deliberately deterministic (no API calls) so
 * this part of the audit is free and instant.
 */
class WAG_Seo_Analyzer {

	/**
	 * @param WAG_Html_Parser $parser
	 * @param array|null       $pagespeed_data
	 * @return array score + findings
	 */
	public function analyze( WAG_Html_Parser $parser, $pagespeed_data ) {
		$findings = array();
		$points   = 0;
		$max      = 0;

		// Title tag.
		$title = $parser->get_title();
		$max   += 10;
		if ( '' === $title ) {
			$findings[] = array( 'type' => 'issue', 'text' => 'Missing <title> tag entirely.' );
		} elseif ( strlen( $title ) < 15 || strlen( $title ) > 65 ) {
			$points     += 5;
			$findings[] = array( 'type' => 'warning', 'text' => "Title tag length (" . strlen( $title ) . " chars) is outside the ideal 15–65 character range." );
		} else {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Title tag is present and well-sized for search results.' );
		}

		// Meta description.
		$description = $parser->get_meta_description();
		$max        += 10;
		if ( '' === $description ) {
			$findings[] = array( 'type' => 'issue', 'text' => 'Missing meta description — search engines will auto-generate a snippet instead.' );
		} elseif ( strlen( $description ) < 50 || strlen( $description ) > 160 ) {
			$points     += 5;
			$findings[] = array( 'type' => 'warning', 'text' => 'Meta description length is outside the ideal 50–160 character range.' );
		} else {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Meta description is present and well-sized.' );
		}

		// H1 usage.
		$headings = $parser->get_headings();
		$h1_count = count( $headings['h1'] );
		$max     += 10;
		if ( 0 === $h1_count ) {
			$findings[] = array( 'type' => 'issue', 'text' => 'No H1 heading found on the page.' );
		} elseif ( $h1_count > 1 ) {
			$points     += 4;
			$findings[] = array( 'type' => 'warning', 'text' => "Multiple H1 tags found ({$h1_count}) — best practice is exactly one per page." );
		} else {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Exactly one H1 heading found.' );
		}

		// Heading hierarchy (has H2s beneath H1).
		$max += 5;
		if ( count( $headings['h2'] ) > 0 ) {
			$points     += 5;
			$findings[] = array( 'type' => 'good', 'text' => 'Page uses H2 subheadings to structure content.' );
		} else {
			$findings[] = array( 'type' => 'warning', 'text' => 'No H2 subheadings found — content may be a wall of text for both readers and crawlers.' );
		}

		// Canonical tag.
		$max += 5;
		if ( '' !== $parser->get_canonical() ) {
			$points     += 5;
			$findings[] = array( 'type' => 'good', 'text' => 'Canonical tag is set.' );
		} else {
			$points     += 2;
			$findings[] = array( 'type' => 'warning', 'text' => 'No canonical tag found — recommended to avoid duplicate-content issues.' );
		}

		// Image alt text.
		$alt_stats = $parser->get_images_alt_stats();
		$max      += 10;
		if ( 0 === $alt_stats['total'] ) {
			$points += 10; // No images, nothing to penalize.
		} elseif ( $alt_stats['with_alt_ratio'] >= 0.9 ) {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Nearly all images have alt text.' );
		} else {
			$points     += round( $alt_stats['with_alt_ratio'] * 10 );
			$findings[] = array(
				'type' => 'warning',
				'text' => "{$alt_stats['missing_alt']} of {$alt_stats['total']} images are missing alt text.",
			);
		}

		// Schema markup presence.
		$schema_blocks = $parser->get_schema_blocks();
		$max          += 10;
		if ( count( $schema_blocks ) > 0 ) {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Structured data (JSON-LD schema) detected on the page.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No structured data (schema.org JSON-LD) found — a missed opportunity for rich results.' );
		}

		// Internal linking.
		$link_stats = $parser->get_links_stats();
		$max       += 5;
		if ( $link_stats['internal'] >= 3 ) {
			$points     += 5;
			$findings[] = array( 'type' => 'good', 'text' => "Good internal linking ({$link_stats['internal']} internal links found)." );
		} else {
			$points     += 2;
			$findings[] = array( 'type' => 'warning', 'text' => 'Few internal links found — internal linking helps both SEO and user navigation.' );
		}

		// Mobile viewport.
		$max += 5;
		if ( $parser->has_viewport_meta() ) {
			$points     += 5;
			$findings[] = array( 'type' => 'good', 'text' => 'Mobile viewport meta tag is present.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No mobile viewport meta tag — page may not render correctly on mobile devices.' );
		}

		// PageSpeed SEO + Performance signals.
		if ( $pagespeed_data ) {
			if ( null !== $pagespeed_data['seo_score'] ) {
				$max    += 20;
				$points += round( ( $pagespeed_data['seo_score'] / 100 ) * 20 );
				$findings[] = array(
					'type' => $pagespeed_data['seo_score'] >= 80 ? 'good' : 'warning',
					'text' => "Google PageSpeed SEO score: {$pagespeed_data['seo_score']}/100.",
				);
			}
			if ( null !== $pagespeed_data['performance_score'] ) {
				$max    += 20;
				$points += round( ( $pagespeed_data['performance_score'] / 100 ) * 20 );
				$findings[] = array(
					'type' => $pagespeed_data['performance_score'] >= 70 ? 'good' : 'issue',
					'text' => "Page performance score: {$pagespeed_data['performance_score']}/100 (page speed strongly affects SEO ranking).",
				);
			}
		}

		$score = $max > 0 ? round( ( $points / $max ) * 100 ) : 0;

		return array(
			'score'    => min( 100, max( 0, $score ) ),
			'findings' => $findings,
			'raw'      => array(
				'title'       => $title,
				'description' => $description,
				'h1_count'    => $h1_count,
				'alt_stats'   => $alt_stats,
				'link_stats'  => $link_stats,
				'has_schema'  => count( $schema_blocks ) > 0,
			),
		);
	}
}
