<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the admin settings page (API keys, limits, caching).
 */
class WAG_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_settings_page() {
		add_options_page(
			__( 'Website Audit Generator', 'website-audit-generator' ),
			__( 'Audit Generator', 'website-audit-generator' ),
			'manage_options',
			'wag-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'wag_settings_group', 'wag_settings', array( __CLASS__, 'sanitize_settings' ) );
	}

	public static function sanitize_settings( $input ) {
		$clean                       = array();
		$clean['pagespeed_api_key']  = isset( $input['pagespeed_api_key'] ) ? sanitize_text_field( $input['pagespeed_api_key'] ) : '';
		$clean['claude_api_key']     = isset( $input['claude_api_key'] ) ? sanitize_text_field( $input['claude_api_key'] ) : '';
		$clean['claude_model']       = isset( $input['claude_model'] ) ? sanitize_text_field( $input['claude_model'] ) : 'claude-sonnet-5';
		$clean['daily_audit_limit']  = isset( $input['daily_audit_limit'] ) ? absint( $input['daily_audit_limit'] ) : 25;
		$clean['cache_hours']        = isset( $input['cache_hours'] ) ? absint( $input['cache_hours'] ) : 24;
		return $clean;
	}

	public static function get( $key, $default = '' ) {
		$settings = get_option( 'wag_settings', array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Website Audit Generator Settings', 'website-audit-generator' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wag_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wag_pagespeed_key"><?php esc_html_e( 'Google PageSpeed API Key', 'website-audit-generator' ); ?></label></th>
						<td>
							<input type="text" id="wag_pagespeed_key" name="wag_settings[pagespeed_api_key]" value="<?php echo esc_attr( self::get( 'pagespeed_api_key' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional but recommended (higher rate limits). Free from Google Cloud Console — enable the PageSpeed Insights API.', 'website-audit-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wag_claude_key"><?php esc_html_e( 'Claude API Key', 'website-audit-generator' ); ?></label></th>
						<td>
							<input type="password" id="wag_claude_key" name="wag_settings[claude_api_key]" value="<?php echo esc_attr( self::get( 'claude_api_key' ) ); ?>" class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Required for the AEO, Content, and Design qualitative commentary. Get one at console.anthropic.com.', 'website-audit-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wag_claude_model"><?php esc_html_e( 'Claude Model', 'website-audit-generator' ); ?></label></th>
						<td>
							<input type="text" id="wag_claude_model" name="wag_settings[claude_model]" value="<?php echo esc_attr( self::get( 'claude_model', 'claude-sonnet-5' ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wag_daily_limit"><?php esc_html_e( 'Daily Audit Limit (site-wide)', 'website-audit-generator' ); ?></label></th>
						<td>
							<input type="number" id="wag_daily_limit" name="wag_settings[daily_audit_limit]" value="<?php echo esc_attr( self::get( 'daily_audit_limit', 25 ) ); ?>" class="small-text" min="1" />
							<p class="description"><?php esc_html_e( 'Caps total audits per day across all visitors, to control API cost.', 'website-audit-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wag_cache_hours"><?php esc_html_e( 'Result Cache (hours)', 'website-audit-generator' ); ?></label></th>
						<td>
							<input type="number" id="wag_cache_hours" name="wag_settings[cache_hours]" value="<?php echo esc_attr( self::get( 'cache_hours', 24 ) ); ?>" class="small-text" min="0" />
							<p class="description"><?php esc_html_e( 'If the same URL is audited again within this window, cached results are served instead of re-calling the APIs. Set to 0 to disable caching.', 'website-audit-generator' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
