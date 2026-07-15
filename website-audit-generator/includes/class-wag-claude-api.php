<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the Anthropic Messages API, used for the
 * qualitative portions of the audit (AEO read, content-gap commentary,
 * structural design critique).
 *
 * Refactored to a "build request / parse response" shape so all three
 * Claude calls in an audit can run concurrently (via WAG_Concurrent_Http)
 * instead of blocking one after another.
 */
class WAG_Claude_Api {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Builds a request descriptor for WAG_Concurrent_Http — does not fire it.
	 * $id is a caller-chosen key (e.g. 'aeo_claude') used to match the result back up.
	 */
	public function build_request( $user_prompt, $schema_hint, $id ) {
		$api_key = WAG_Settings::get( 'claude_api_key' );
		$model   = WAG_Settings::get( 'claude_model', 'claude-sonnet-5' );

		$system_prompt = "You are a website auditing assistant. Respond ONLY with valid JSON matching this shape, and nothing else — no markdown fences, no preamble, no explanation outside the JSON: \n" . $schema_hint;

		$body = array(
			'model'      => $model,
			'max_tokens' => 1200,
			'system'     => $system_prompt,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		return array(
			'id'      => $id,
			'method'  => 'POST',
			'url'     => self::ENDPOINT,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 45,
		);
	}

	/**
	 * Parses the raw result returned by WAG_Concurrent_Http for a Claude request.
	 * Returns array( 'success' => bool, 'data' => array|null, 'error' => string|null )
	 */
	public function parse_response( $http_result ) {
		if ( ! empty( $http_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $http_result['error'],
			);
		}

		$code = isset( $http_result['code'] ) ? (int) $http_result['code'] : 0;

		if ( 429 === $code ) {
			return array(
				'success' => false,
				'error'   => 'Claude API rate limit reached (HTTP 429). Wait a moment and try again, or check your plan\'s rate limits at console.anthropic.com.',
			);
		}

		$response_body = json_decode( isset( $http_result['body'] ) ? $http_result['body'] : '', true );

		if ( 200 !== $code ) {
			$message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : 'Claude API error (HTTP ' . $code . ')';
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$text = '';
		if ( isset( $response_body['content'] ) && is_array( $response_body['content'] ) ) {
			foreach ( $response_body['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		// Strip stray markdown fences just in case the model adds them.
		$text = preg_replace( '/^```json\s*|\s*```$/m', '', trim( $text ) );

		$parsed = json_decode( $text, true );
		if ( null === $parsed ) {
			return array(
				'success' => false,
				'error'   => 'Could not parse Claude response as JSON.',
			);
		}

		return array(
			'success' => true,
			'data'    => $parsed,
		);
	}
}
