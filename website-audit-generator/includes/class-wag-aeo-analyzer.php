<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEO = Answer Engine Optimization: how citable/extractable this page is
 * for AI assistants, featured snippets, and voice search.
 * Combines deterministic structural checks with a Claude qualitative pass.
 */
class WAG_Aeo_Analyzer {

	/**
	 * Builds the prompt/schema for the Claude call, without making it.
	 * The engine collects this alongside the other analyzers' prompts
	 * and fires all of them concurrently.
	 */
	public function prepare( WAG_Html_Parser $parser ) {
		$text_excerpt = mb_substr( $parser->get_visible_text(), 0, 6000 );
		$title        = $parser->get_title();

		$schema_hint = '{"aeo_readiness_score": <integer 0-100>, "summary": "<2-3 sentence plain-English summary>", "strengths": ["<short strength>", ...], "improvements": ["<short actionable improvement>", ...]}';

		$prompt = "Evaluate this webpage's readiness to be cited or summarized by AI answer engines (like AI Overviews, ChatGPT, Perplexity) and voice assistants. "
			. "Consider: does the content directly answer likely user questions in the first 1-2 sentences of relevant sections? Is it factual and extractable, or vague/marketing-heavy? Is it structured for easy quoting?\n\n"
			. "Page title: {$title}\n\nVisible text excerpt:\n{$text_excerpt}";

		return array(
			'prompt' => $prompt,
			'schema' => $schema_hint,
		);
	}

	/**
	 * @param WAG_Html_Parser $parser
	 * @param array $claude_outcome Already-parsed result from WAG_Claude_Api::parse_response()
	 *              for the request built in prepare(), e.g. ['success'=>bool,'data'=>array|null,'error'=>string|null]
	 */
	public function analyze( WAG_Html_Parser $parser, $claude_outcome ) {
		$findings = array();
		$points   = 0;
		$max      = 0;

		// Question-phrased headings (great AEO signal — these get pulled into snippets/AI answers).
		$question_headings = $parser->count_question_headings();
		$max              += 20;
		if ( $question_headings >= 2 ) {
			$points     += 20;
			$findings[] = array( 'type' => 'good', 'text' => "Found {$question_headings} question-style headings — these are prime candidates for AI-generated answers and featured snippets." );
		} elseif ( 1 === $question_headings ) {
			$points     += 10;
			$findings[] = array( 'type' => 'warning', 'text' => 'Only one question-style heading found — consider adding more direct-answer Q&A sections.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No question-phrased headings found. AI answer engines favor content structured as direct questions and answers.' );
		}

		// FAQ schema specifically.
		$schema_blocks = $parser->get_schema_blocks();
		$has_faq       = false;
		$has_howto     = false;
		foreach ( $schema_blocks as $block ) {
			$type = isset( $block['@type'] ) ? $block['@type'] : '';
			$type = is_array( $type ) ? implode( ',', $type ) : $type;
			if ( false !== stripos( $type, 'FAQPage' ) ) {
				$has_faq = true;
			}
			if ( false !== stripos( $type, 'HowTo' ) ) {
				$has_howto = true;
			}
		}
		$max += 15;
		if ( $has_faq ) {
			$points     += 15;
			$findings[] = array( 'type' => 'good', 'text' => 'FAQPage schema detected — directly eligible for AI Overview and voice-answer citation.' );
		} else {
			$findings[] = array( 'type' => 'issue', 'text' => 'No FAQPage schema found. Adding one for common questions can significantly boost AI citation odds.' );
		}

		if ( $has_howto ) {
			$max        += 5;
			$points     += 5;
			$findings[] = array( 'type' => 'good', 'text' => 'HowTo schema detected — useful for step-by-step AI answers.' );
		}

		// Fold in the already-fetched Claude qualitative read (fetched concurrently by the engine).
		$claude_result = $claude_outcome;

		$max += 60;
		if ( $claude_result['success'] ) {
			$claude_score = isset( $claude_result['data']['aeo_readiness_score'] ) ? intval( $claude_result['data']['aeo_readiness_score'] ) : 50;
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
			// Fall back gracefully — still show a score from structural signals alone.
			$points     += 30; // neutral midpoint fallback
			$findings[] = array( 'type' => 'warning', 'text' => 'AI-based content analysis unavailable (' . esc_html( $claude_result['error'] ) . '). Score reflects structural signals only.' );
		}

		$score = $max > 0 ? round( ( $points / $max ) * 100 ) : 0;

		return array(
			'score'    => min( 100, max( 0, $score ) ),
			'findings' => $findings,
		);
	}
}
