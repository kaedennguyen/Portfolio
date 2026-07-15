<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates a full audit run: fetch, analyze all four categories,
 * compute weighted overall score, cache result, respect daily limits.
 *
 * IMPORTANT: PageSpeed + the three Claude calls all run CONCURRENTLY via
 * WAG_Concurrent_Http, not one after another. Running them sequentially
 * could take up to ~3 minutes combined, which exceeds most hosts' PHP
 * execution timeout and is what caused audits to hang or return nothing.
 */
class WAG_Audit_Engine {

	// Category weights — must sum to 100. Rebalanced to include GEO as its own category.
	const WEIGHT_SEO     = 25;
	const WEIGHT_AEO     = 20;
	const WEIGHT_GEO     = 20;
	const WEIGHT_CONTENT = 20;
	const WEIGHT_DESIGN  = 15;

	/**
	 * Runs (or retrieves cached) audit for a URL.
	 * Returns array( 'success' => bool, 'data' => array|null, 'error' => string|null )
	 */
	public function run( $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
			return array(
				'success' => false,
				'error'   => 'Please enter a valid URL starting with http:// or https://',
			);
		}

		// Serve cached result if within cache window.
		$cache_hours = intval( WAG_Settings::get( 'cache_hours', 24 ) );
		$cache_key   = 'wag_cache_' . md5( $url );
		if ( $cache_hours > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached['from_cache'] = true;
				return array(
					'success' => true,
					'data'    => $cached,
				);
			}
		}

		// Daily site-wide rate limit, to control API cost.
		$limit       = intval( WAG_Settings::get( 'daily_audit_limit', 25 ) );
		$today_key   = 'wag_audit_count_' . gmdate( 'Y-m-d' );
		$today_count = intval( get_transient( $today_key ) );
		if ( $limit > 0 && $today_count >= $limit ) {
			return array(
				'success' => false,
				'error'   => 'Daily audit limit reached. Please try again tomorrow.',
			);
		}

		// Give ourselves headroom on hosts that allow it; silently ignored otherwise.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 );
		}

		// Fetch and parse the target page. This has to happen first and
		// sequentially — everything else depends on its output.
		$parser = new WAG_Html_Parser( $url );
		if ( ! $parser->fetch() ) {
			return array(
				'success' => false,
				'error'   => 'Could not fetch the target URL: ' . implode( ' ', $parser->errors ),
			);
		}

		$claude_key = WAG_Settings::get( 'claude_api_key' );

		$seo_analyzer     = new WAG_Seo_Analyzer();
		$aeo_analyzer     = new WAG_Aeo_Analyzer();
		$geo_analyzer     = new WAG_Geo_Analyzer();
		$content_analyzer = new WAG_Content_Analyzer();
		$design_analyzer  = new WAG_Design_Analyzer();
		$claude           = new WAG_Claude_Api();

		// Build every independent external request up front...
		$requests = array();
		$requests[] = WAG_Pagespeed_Api::build_request( $url, 'mobile' );

		$aeo_prep     = $aeo_analyzer->prepare( $parser );
		$geo_prep     = $geo_analyzer->prepare( $parser );
		$content_prep = $content_analyzer->prepare( $parser );
		$design_prep  = $design_analyzer->prepare( $parser );

		if ( ! empty( $claude_key ) ) {
			$requests[] = $claude->build_request( $aeo_prep['prompt'], $aeo_prep['schema'], 'aeo_claude' );
			$requests[] = $claude->build_request( $geo_prep['prompt'], $geo_prep['schema'], 'geo_claude' );
			$requests[] = $claude->build_request( $content_prep['prompt'], $content_prep['schema'], 'content_claude' );
			$requests[] = $claude->build_request( $design_prep['prompt'], $design_prep['schema'], 'design_claude' );
		}

		// ...then fire them all at once. This is the key fix: total wait time
		// is roughly the SLOWEST single call, not the sum of all four.
		$http_results = WAG_Concurrent_Http::run( $requests, 60 );

		// Unpack PageSpeed.
		$pagespeed_data  = null;
		$pagespeed_error = null;
		if ( isset( $http_results['pagespeed'] ) ) {
			$parsed = WAG_Pagespeed_Api::parse_response( $http_results['pagespeed'] );
			if ( $parsed['success'] ) {
				$pagespeed_data = $parsed['data'];
			} else {
				$pagespeed_error = $parsed['error'];
			}
		}

		// Unpack Claude outcomes (or a config-missing stand-in if no key set).
		$no_key_outcome = array(
			'success' => false,
			'error'   => 'No Claude API key configured. Add one under Settings → Audit Generator.',
		);

		$aeo_outcome     = isset( $http_results['aeo_claude'] ) ? $claude->parse_response( $http_results['aeo_claude'] ) : $no_key_outcome;
		$geo_outcome     = isset( $http_results['geo_claude'] ) ? $claude->parse_response( $http_results['geo_claude'] ) : $no_key_outcome;
		$content_outcome = isset( $http_results['content_claude'] ) ? $claude->parse_response( $http_results['content_claude'] ) : $no_key_outcome;
		$design_outcome  = isset( $http_results['design_claude'] ) ? $claude->parse_response( $http_results['design_claude'] ) : $no_key_outcome;

		// Score everything now that all data has arrived.
		$seo_result     = $seo_analyzer->analyze( $parser, $pagespeed_data );
		$aeo_result     = $aeo_analyzer->analyze( $parser, $aeo_outcome );
		$geo_result     = $geo_analyzer->analyze( $parser, $geo_outcome );
		$content_result = $content_analyzer->analyze( $parser, $content_outcome );
		$design_result  = $design_analyzer->analyze( $parser, $pagespeed_data, $design_outcome );

		$overall_score = round(
			( $seo_result['score'] * self::WEIGHT_SEO
			+ $aeo_result['score'] * self::WEIGHT_AEO
			+ $geo_result['score'] * self::WEIGHT_GEO
			+ $content_result['score'] * self::WEIGHT_CONTENT
			+ $design_result['score'] * self::WEIGHT_DESIGN )
			/ 100
		);

		$data = array(
			'url'             => $url,
			'audited_at'      => current_time( 'mysql' ),
			'overall_score'   => $overall_score,
			'overall_grade'   => $this->score_to_grade( $overall_score ),
			'seo'             => $seo_result,
			'aeo'             => $aeo_result,
			'geo'             => $geo_result,
			'content'         => $content_result,
			'design'          => $design_result,
			'pagespeed_error' => $pagespeed_error,
			'from_cache'      => false,
		);

		// Cache + increment daily counter.
		if ( $cache_hours > 0 ) {
			set_transient( $cache_key, $data, $cache_hours * HOUR_IN_SECONDS );
		}
		set_transient( $today_key, $today_count + 1, DAY_IN_SECONDS );

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	public function score_to_grade( $score ) {
		if ( $score >= 90 ) return 'A';
		if ( $score >= 80 ) return 'B';
		if ( $score >= 70 ) return 'C';
		if ( $score >= 60 ) return 'D';
		return 'F';
	}
}
