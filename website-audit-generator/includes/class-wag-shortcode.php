<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [website_audit_generator] shortcode (form + results container)
 * and handles the AJAX request that runs the audit.
 */
class WAG_Shortcode {

	public static function init() {
		add_shortcode( 'website_audit_generator', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_wag_run_audit', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_wag_run_audit', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function render( $atts ) {
		ob_start();
		?>
		<div class="wag-container">
			<div class="wag-intro">
				<p><?php esc_html_e( 'Enter your website URL below to get a free automated audit — you\'ll get a score for SEO, AEO (Answer Engine Optimization), GEO (Generative Engine Optimization), Content, and Design & UX, along with plain-English recommendations on what\'s working and what to fix. Click any category below your results to expand the full breakdown.', 'website-audit-generator' ); ?></p>
			</div>
			<form id="wag-audit-form" class="wag-form">
				<label for="wag-url-input" class="wag-label"><?php esc_html_e( 'Enter your website URL', 'website-audit-generator' ); ?></label>
				<div class="wag-input-row">
					<input type="url" id="wag-url-input" name="wag_url" placeholder="https://example.com" required />
					<button type="submit" id="wag-submit-btn"><?php esc_html_e( 'Audit My Site', 'website-audit-generator' ); ?></button>
				</div>
			</form>
			<div id="wag-loading" class="wag-loading" style="display:none;">
				<div class="wag-spinner"></div>
				<p><?php esc_html_e( 'Running your audit — this can take 15–30 seconds…', 'website-audit-generator' ); ?></p>
			</div>
			<div id="wag-error" class="wag-error" style="display:none;"></div>
			<div id="wag-results" class="wag-results" style="display:none;"></div>
			<div class="wag-contact">
				<?php
				printf(
					/* translators: %s: contact email address */
					esc_html__( 'Questions, or interested in working together? Contact us at %s', 'website-audit-generator' ),
					'<a href="mailto:mediavinescorp@gmail.com">mediavinescorp@gmail.com</a>'
				);
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_ajax() {
		check_ajax_referer( 'wag_run_audit', 'nonce' );

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a URL.', 'website-audit-generator' ) ) );
		}

		// Belt-and-braces: if anything inside the engine throws (a parsing
		// edge case, an unexpected API shape, etc.) we still want to return
		// valid JSON instead of a blank/500 response, which is what made
		// failures look like "no answer at all" rather than a clear error.
		try {
			$engine = new WAG_Audit_Engine();
			$result = $engine->run( $url );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => 'Unexpected error while running the audit: ' . $e->getMessage() ) );
		}

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		wp_send_json_success( array( 'audit' => $result['data'] ) );
	}
}
