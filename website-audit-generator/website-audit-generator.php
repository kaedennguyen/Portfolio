<?php
/**
 * Plugin Name: Website Audit Generator
 * Plugin URI:  https://example.com
 * Description: Lets visitors enter a URL and receive an automated SEO, AEO, Content, and Design audit with a weighted score and plain-English recommendations.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL v2 or later
 * Text Domain: website-audit-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WAG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WAG_VERSION', '1.0.0' );

// Core includes.
require_once WAG_PLUGIN_DIR . 'includes/class-wag-settings.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-html-parser.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-concurrent-http.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-pagespeed-api.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-claude-api.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-seo-analyzer.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-aeo-analyzer.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-geo-analyzer.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-content-analyzer.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-design-analyzer.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-audit-engine.php';
require_once WAG_PLUGIN_DIR . 'includes/class-wag-shortcode.php';

/**
 * Main plugin bootstrap class.
 */
final class Website_Audit_Generator {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Boot subsystems.
		WAG_Settings::init();
		WAG_Shortcode::init();

		register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'website-audit-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function enqueue_assets() {
		// Only load on pages containing the shortcode to keep footprint light.
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'website_audit_generator' ) ) {
			wp_enqueue_style( 'wag-style', WAG_PLUGIN_URL . 'assets/css/audit-style.css', array(), WAG_VERSION );
			wp_enqueue_script( 'wag-script', WAG_PLUGIN_URL . 'assets/js/audit-script.js', array( 'jquery' ), WAG_VERSION, true );
			wp_localize_script(
				'wag-script',
				'wagAjax',
				array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'wag_run_audit' ),
				)
			);
		}
	}

	public static function on_activate() {
		// Set sensible defaults on first activation.
		if ( false === get_option( 'wag_settings' ) ) {
			add_option(
				'wag_settings',
				array(
					'pagespeed_api_key' => '',
					'claude_api_key'    => '',
					'claude_model'      => 'claude-sonnet-5',
					'daily_audit_limit' => 25,
					'cache_hours'       => 24,
				)
			);
		}
	}
}

Website_Audit_Generator::instance();
