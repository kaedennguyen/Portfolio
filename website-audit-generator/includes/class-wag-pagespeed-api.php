<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the free Google PageSpeed Insights API.
 * https://developers.google.com/speed/docs/insights/v5/get-started
 *
 * Refactored to a "build request / parse response" shape (rather than
 * making the call itself) so it can run concurrently with the Claude
 * API calls via WAG_Concurrent_Http instead of blocking sequentially.
 */
class WAG_Pagespeed_Api {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Builds a request descriptor for WAG_Concurrent_Http — does not fire it.
	 */
	public static function build_request( $url, $strategy = 'mobile' ) {
		$api_key = WAG_Settings::get( 'pagespeed_api_key' );

		$params = array(
			'url'      => $url,
			'strategy' => $strategy,
		);
		if ( $api_key ) {
			$params['key'] = $api_key;
		}

		$request_url = self::ENDPOINT . '?' . http_build_query( $params );
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $cat ) {
			$request_url .= '&category=' . rawurlencode( $cat );
		}

		return array(
			'id'      => 'pagespeed',
			'method'  => 'GET',
			'url'     => $request_url,
			'headers' => array(),
			'timeout' => 25,
		);
	}

	/**
	 * Parses the raw result returned by WAG_Concurrent_Http for this request.
	 */
	public static function parse_response( $http_result ) {
		if ( ! empty( $http_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $http_result['error'],
			);
		}

		$code = isset( $http_result['code'] ) ? (int) $http_result['code'] : 0;
		$body = json_decode( isset( $http_result['body'] ) ? $http_result['body'] : '', true );

		if ( 429 === $code ) {
			return array(
				'success' => false,
				'error'   => 'PageSpeed API rate limit reached (HTTP 429). Add an API key under Settings, or wait a bit before retrying.',
			);
		}

		if ( 200 !== $code ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown PageSpeed API error (HTTP ' . $code . ')';
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		return array(
			'success' => true,
			'data'    => self::extract_scores( $body ),
		);
	}

	/**
	 * Pulls out just the numbers we care about, so downstream code
	 * doesn't have to deal with the full raw Lighthouse JSON blob.
	 */
	private static function extract_scores( $body ) {
		$categories = isset( $body['lighthouseResult']['categories'] ) ? $body['lighthouseResult']['categories'] : array();
		$audits     = isset( $body['lighthouseResult']['audits'] ) ? $body['lighthouseResult']['audits'] : array();

		$get_score = function ( $key ) use ( $categories ) {
			return isset( $categories[ $key ]['score'] ) ? round( $categories[ $key ]['score'] * 100 ) : null;
		};

		$get_metric = function ( $key, $field = 'displayValue' ) use ( $audits ) {
			return isset( $audits[ $key ][ $field ] ) ? $audits[ $key ][ $field ] : null;
		};

		return array(
			'performance_score'        => $get_score( 'performance' ),
			'accessibility_score'      => $get_score( 'accessibility' ),
			'best_practices_score'     => $get_score( 'best-practices' ),
			'seo_score'                => $get_score( 'seo' ),
			'first_contentful_paint'   => $get_metric( 'first-contentful-paint' ),
			'largest_contentful_paint' => $get_metric( 'largest-contentful-paint' ),
			'cumulative_layout_shift'  => $get_metric( 'cumulative-layout-shift' ),
			'total_blocking_time'      => $get_metric( 'total-blocking-time' ),
			'speed_index'              => $get_metric( 'speed-index' ),
		);
	}
}
