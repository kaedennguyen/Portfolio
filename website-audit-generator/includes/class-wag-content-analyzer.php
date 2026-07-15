<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content quality scoring: local readability math (free) + Claude
 * qualitative pass on what's working / what's missing.
 */
class WAG_Content_Analyzer {

	/**
	 * Builds the prompt/schema for the Claude call, without making it.
	 */
	public function prepare( WAG_Html_Parser $parser ) {
		$excerpt = mb_substr( $parser->get_visible_text(), 0, 6000 );
		$title   = $parser->get_title();

		$schema_hint = '{"content_quality_score": <integer 0-100>, "whats_working": ["<short point>", ...], "whats_not_working": ["<short point>", ...], "content_gaps": ["<short suggestion for missing topics/sections>", ...]}';

		$prompt = "Analyze this webpage's content quality from a marketing/copywriting perspective. Identify what's genuinely working (clarity, persuasiveness, specificity, trust signals) and what's not (vagueness, jargon, missing proof points, weak calls to action). Also suggest content gaps — topics or sections a visitor would expect but that are missing.\n\n"
			. "Page title: {$title}\n\nVisible text:\n{$excerpt}";

		return array(
			'prompt' => $prompt,
			'schema' => $schema_hint,
		);
	}

	/**
	 * @param array $claude_outcome Already-parsed result from WAG_Claude_Api::parse_response()
	 */
	public function analyze( WAG_Html_Parser $parser, $claude_outcome ) {
		$findings = array();
		$points   = 0;
		$max      = 0;

		$text       = $parser->get_visible_text();
		$word_count = $parser->count_words( $text );

		// Word count / content depth.
		$max += 20;
		if ( $word_count < 150 ) {
			$findings[] = array( 'type' => 'issue', 'text' => "Only {$word_count} words of visible content — likely too thin for search engines or AI systems to extract meaningful value." );
		} elseif ( $word_count < 400 ) {
			$points     += 10;
			$findings[] = array( 'type' => 'warning', 'text' => "{$word_count} words found — on the light side. Competitive pages in most niches run 500+ words." );
		} else {
			$points     += 20;
			$findings[] = array( 'type' => 'good', 'text' => "{$word_count} words of substantive content found — solid depth." );
		}

		// Readability: Flesch Reading Ease, computed locally (no API cost).
		$flesch = $this->flesch_reading_ease( $text );
		$max   += 20;
		if ( null === $flesch ) {
			$findings[] = array( 'type' => 'warning', 'text' => 'Not enough text to calculate a readability score.' );
		} else {
			if ( $flesch >= 60 ) {
				$points     += 20;
				$findings[] = array( 'type' => 'good', 'text' => "Flesch Reading Ease score: {$flesch} — easy for a general audience to read." );
			} elseif ( $flesch >= 30 ) {
				$points     += 12;
				$findings[] = array( 'type' => 'warning', 'text' => "Flesch Reading Ease score: {$flesch} — fairly difficult; consider shorter sentences and simpler words." );
			} else {
				$points     += 5;
				$findings[] = array( 'type' => 'issue', 'text' => "Flesch Reading Ease score: {$flesch} — very difficult to read for most visitors." );
			}
		}

		// Heading-to-content ratio (structure signal).
		$headings     = $parser->get_headings();
		$total_headings = array_sum( array_map( 'count', $headings ) );
		$max          += 10;
		if ( $word_count > 0 && $total_headings > 0 ) {
			$words_per_heading = $word_count / $total_headings;
			if ( $words_per_heading <= 300 ) {
				$points     += 10;
				$findings[] = array( 'type' => 'good', 'text' => 'Content is well broken up with subheadings, making it scannable.' );
			} else {
				$points     += 5;
				$findings[] = array( 'type' => 'warning', 'text' => 'Large blocks of text between headings — consider breaking up long sections.' );
			}
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No subheadings found to break up the content.' );
		}

		// Fold in the already-fetched Claude qualitative read (fetched concurrently by the engine).
		$claude_result = $claude_outcome;

		$max += 50;
		if ( $claude_result['success'] ) {
			$claude_score = isset( $claude_result['data']['content_quality_score'] ) ? intval( $claude_result['data']['content_quality_score'] ) : 50;
			$points      += round( ( $claude_score / 100 ) * 50 );

			foreach ( (array) ( $claude_result['data']['whats_working'] ?? array() ) as $w ) {
				$findings[] = array( 'type' => 'good', 'text' => sanitize_text_field( $w ) );
			}
			foreach ( (array) ( $claude_result['data']['whats_not_working'] ?? array() ) as $w ) {
				$findings[] = array( 'type' => 'issue', 'text' => sanitize_text_field( $w ) );
			}
			foreach ( (array) ( $claude_result['data']['content_gaps'] ?? array() ) as $g ) {
				$findings[] = array( 'type' => 'improvement', 'text' => sanitize_text_field( $g ) );
			}
		} else {
			$points    += 25;
			$findings[] = array( 'type' => 'warning', 'text' => 'AI-based content analysis unavailable (' . esc_html( $claude_result['error'] ) . '). Score reflects readability/structure only.' );
		}

		$score = $max > 0 ? round( ( $points / $max ) * 100 ) : 0;

		return array(
			'score'    => min( 100, max( 0, $score ) ),
			'findings' => $findings,
			'raw'      => array(
				'word_count' => $word_count,
				'flesch'     => $flesch,
			),
		);
	}

	/**
	 * Standard Flesch Reading Ease formula, computed locally.
	 * 206.835 - 1.015*(words/sentences) - 84.6*(syllables/words)
	 */
	private function flesch_reading_ease( $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$sentence_count = preg_match_all( '/[.!?]+/', $text );
		$sentence_count = max( 1, $sentence_count );

		$words = preg_split( '/\s+/', $text );
		$words = array_filter( $words );
		$word_count = count( $words );
		if ( 0 === $word_count ) {
			return null;
		}

		$syllable_count = 0;
		foreach ( $words as $word ) {
			$syllable_count += $this->count_syllables( $word );
		}

		$score = 206.835 - 1.015 * ( $word_count / $sentence_count ) - 84.6 * ( $syllable_count / $word_count );
		return round( $score, 1 );
	}

	/**
	 * Rough heuristic syllable counter (vowel-group based). Not linguistically
	 * perfect but standard practice for Flesch approximations without a dictionary.
	 */
	private function count_syllables( $word ) {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( '' === $word ) {
			return 0;
		}
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', $word );
		preg_match_all( '/[aeiouy]{1,2}/', $word, $matches );
		return max( 1, count( $matches[0] ) );
	}
}
