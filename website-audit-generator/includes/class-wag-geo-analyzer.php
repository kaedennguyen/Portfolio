<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GEO = Generative Engine Optimization: distinct from AEO. AEO asks
 * "is this structured to be pulled into a direct-answer box?" GEO asks
 * "is this the kind of source a generative AI would actually cite when
 * synthesizing an answer?" — driven by authority/citability signals:
 * freshness, bylines/expertise, specificity vs. generic marketing copy,
 * and original data or insight rather than rehashed boilerplate.
 */
class WAG_Geo_Analyzer {

	/**
	 * Builds the prompt/schema for the Claude call, without making it.
	 */
	public function prepare( WAG_Html_Parser $parser ) {
		$excerpt = mb_substr( $parser->get_visible_text(), 0, 6000 );
		$title   = $parser->get_title();

		$schema_hint = '{"geo_citability_score": <integer 0-100>, "summary": "<2-3 sentence plain-English summary of how citable/authoritative this reads to a generative AI>", "strengths": ["<short strength>", ...], "improvements": ["<short actionable improvement>", ...]}';

		$prompt = "Evaluate this webpage's likelihood of being cited as a SOURCE by a generative AI system (ChatGPT, Perplexity, Google AI Overviews) when synthesizing an answer to a related query — this is different from being pulled into a featured snippet. "
			. "Consider: does it contain original data, specific numbers/stats, named expertise, or unique insight worth citing? Or is it generic, interchangeable marketing copy that offers nothing an AI couldn't get from ten other sites? Does it read as authoritative and trustworthy, or vague and promotional?\n\n"
			. "Page title: {$title}\n\nVisible text excerpt:\n{$excerpt}";

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

		// Freshness signal — generative engines favor demonstrably current content.
		$modified  = $parser->get_meta_content( 'article:modified_time' );
		$published = $parser->get_meta_content( 'article:published_time' );
		$og_updated = $parser->get_meta_content( 'og:updated_time' );
		$has_date_signal = ( '' !== $modified ) || ( '' !== $published ) || ( '' !== $og_updated );

		$max += 15;
		if ( $has_date_signal ) {
			$points     += 15;
			$findings[] = array( 'type' => 'good', 'text' => 'Page exposes a published/modified date signal — generative engines weigh content freshness when choosing sources.' );
		} else {
			$findings[] = array( 'type' => 'warning', 'text' => 'No published/modified date metadata found. Undated content is harder for AI systems to trust as current.' );
		}

		// Author/byline or organizational authorship signal.
		$schema_blocks = $parser->get_schema_blocks();
		$has_author    = false;
		foreach ( $schema_blocks as $block ) {
			if ( isset( $block['author'] ) ) {
				$has_author = true;
				break;
			}
			// Some sites nest schema under @graph.
			if ( isset( $block['@graph'] ) && is_array( $block['@graph'] ) ) {
				foreach ( $block['@graph'] as $node ) {
					if ( isset( $node['author'] ) ) {
						$has_author = true;
						break 2;
					}
				}
			}
		}
		$max += 15;
		if ( $has_author ) {
			$points     += 15;
			$findings[] = array( 'type' => 'good', 'text' => 'Author/organization schema found — named authorship is an authority signal generative engines use to judge trustworthiness.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No author/organization schema found. Attributing content to a named person or org strengthens citability.' );
		}

		// External link diversity — pages that reference/cite other sources tend
		// to read as more researched/authoritative than pages that only link internally.
		$link_stats = $parser->get_links_stats();
		$max       += 10;
		if ( $link_stats['external'] >= 1 ) {
			$points     += 10;
			$findings[] = array( 'type' => 'good', 'text' => 'Page references at least one external source — citing outside sources is a mild trust/authority signal.' );
		} else {
			$points     += 4;
			$findings[] = array( 'type' => 'warning', 'text' => 'No outbound citations/references found. Purely self-referential content reads as less researched.' );
		}

		// Claude qualitative citability read — the bulk of the GEO score,
		// since "would an AI actually cite this" is fundamentally a judgment call.
		$claude_result = $claude_outcome;

		$max += 60;
		if ( $claude_result['success'] ) {
			$claude_score = isset( $claude_result['data']['geo_citability_score'] ) ? intval( $claude_result['data']['geo_citability_score'] ) : 50;
			$points      += round( ( $claude_score / 100 ) * 60 );

			if ( ! empty( $claude_result['data']['summary'] ) ) {
				$findings[] = array( 'type' => 'summary', 'text' => sanitize_text_field( $claude_result['data']['summary'] ) );
			}
			foreach ( (array) ( $claude_result['data']['strengths'] ?? array() ) as $s ) {
				$findings[] = array( 'type' => 'good', 'text' => sanitize_text_field( $s ) );
			}
			foreach ( (array) ( $claude_result['data']['improvements'] ?? array() ) as $imp ) {
				$findings[] = array( 'type' => 'improvement', 'text' => sanitize_text_field( $imp ) );
			}
		} else {
			$points     += 30; // neutral midpoint fallback
			$findings[] = array( 'type' => 'warning', 'text' => 'AI-based citability analysis unavailable (' . esc_html( $claude_result['error'] ) . '). Score reflects structural signals only.' );
		}

		$score = $max > 0 ? round( ( $points / $max ) * 100 ) : 0;

		return array(
			'score'    => min( 100, max( 0, $score ) ),
			'findings' => $findings,
		);
	}
}
