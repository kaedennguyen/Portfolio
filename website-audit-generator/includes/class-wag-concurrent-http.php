<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires multiple independent HTTP requests in parallel using curl_multi,
 * so a full audit doesn't pay the sum of every external API's latency.
 * Falls back to sequential wp_remote_* calls if curl_multi isn't available
 * (rare, but some locked-down hosts disable it).
 */
class WAG_Concurrent_Http {

	/**
	 * @param array $requests Array of ['id','method','url','headers','body','timeout']
	 * @param int   $overall_timeout Hard ceiling in seconds for the whole batch.
	 * @return array keyed by request id => ['success','code','body','error']
	 */
	public static function run( $requests, $overall_timeout = 60 ) {
		if ( empty( $requests ) ) {
			return array();
		}

		if ( ! function_exists( 'curl_multi_init' ) ) {
			return self::run_sequential_fallback( $requests );
		}

		$multi_handle = curl_multi_init();
		$handles      = array();

		foreach ( $requests as $req ) {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $req['url'] );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, isset( $req['timeout'] ) ? (int) $req['timeout'] : 30 );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );

			if ( ! empty( $req['headers'] ) ) {
				$header_lines = array();
				foreach ( $req['headers'] as $k => $v ) {
					$header_lines[] = $k . ': ' . $v;
				}
				curl_setopt( $ch, CURLOPT_HTTPHEADER, $header_lines );
			}

			if ( isset( $req['method'] ) && 'POST' === strtoupper( $req['method'] ) ) {
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, isset( $req['body'] ) ? $req['body'] : '' );
			}

			curl_multi_add_handle( $multi_handle, $ch );
			$handles[ $req['id'] ] = $ch;
		}

		$start   = microtime( true );
		$running = null;

		do {
			$status = curl_multi_exec( $multi_handle, $running );
			if ( $running > 0 ) {
				curl_multi_select( $multi_handle, 1 );
			}
			if ( ( microtime( true ) - $start ) > $overall_timeout ) {
				break; // Hard stop so a hung external API can't hang the whole audit.
			}
		} while ( $running > 0 && CURLM_OK === $status );

		$results = array();
		foreach ( $handles as $id => $ch ) {
			$body      = curl_multi_getcontent( $ch );
			$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error     = curl_error( $ch );

			curl_multi_remove_handle( $multi_handle, $ch );
			curl_close( $ch );

			if ( 0 === $http_code && empty( $error ) ) {
				$error = 'Request timed out.';
			}

			$results[ $id ] = array(
				'success' => empty( $error ) && $http_code > 0,
				'code'    => $http_code,
				'body'    => $body,
				'error'   => $error,
			);
		}
		curl_multi_close( $multi_handle );

		return $results;
	}

	private static function run_sequential_fallback( $requests ) {
		$results = array();
		foreach ( $requests as $req ) {
			$args = array(
				'timeout' => isset( $req['timeout'] ) ? (int) $req['timeout'] : 30,
				'headers' => isset( $req['headers'] ) ? $req['headers'] : array(),
			);

			if ( isset( $req['method'] ) && 'POST' === strtoupper( $req['method'] ) ) {
				$args['body'] = isset( $req['body'] ) ? $req['body'] : '';
				$response     = wp_remote_post( $req['url'], $args );
			} else {
				$response = wp_remote_get( $req['url'], $args );
			}

			if ( is_wp_error( $response ) ) {
				$results[ $req['id'] ] = array(
					'success' => false,
					'code'    => 0,
					'body'    => '',
					'error'   => $response->get_error_message(),
				);
			} else {
				$results[ $req['id'] ] = array(
					'success' => true,
					'code'    => wp_remote_retrieve_response_code( $response ),
					'body'    => wp_remote_retrieve_body( $response ),
					'error'   => '',
				);
			}
		}
		return $results;
	}
}
