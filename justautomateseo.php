<?php
/**
 * Plugin Name: JustAutomateSEO
 * Plugin URI: https://justautomateseo.com
 * Description: AI-powered SEO automation plugin - connects to Google Search Console, performs keyword research, generates optimized content, and publishes to WordPress.
 * Version: 1.0.2
 * Author: JustAutomateSEO
 * License: GPL v2 or later
 * Text Domain: justautomateseo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JASE_VERSION', '1.0.2' );
define( 'JASE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JASE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JASE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once JASE_PLUGIN_DIR . 'includes/class-jase-settings.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-google-search-console.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-keyword-research.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-ai-analysis.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-content-generator.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-ajax-handler.php';
require_once JASE_PLUGIN_DIR . 'includes/class-jase-admin.php';

final class JustAutomateSEO {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );

        new JASE_Admin();
        new JASE_Ajax_Handler();
    }

    public function init() {
        load_plugin_textdomain( 'justautomateseo', false, dirname( JASE_PLUGIN_BASENAME ) . '/languages' );
    }

    public function activate() {
        // Create DB table for storing keyword/content pipeline data
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'jase_keywords';

        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            query varchar(500) NOT NULL,
            clicks int(11) DEFAULT 0,
            impressions int(11) DEFAULT 0,
            ctr float DEFAULT 0,
            position float DEFAULT 0,
            volume int(11) DEFAULT 0,
            competition_level varchar(20) DEFAULT '',
            ai_score int(3) DEFAULT 0,
            status varchar(50) DEFAULT 'available',
            selected tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY query (query(191))
        ) $charset_collate;";

        $table2 = $wpdb->prefix . 'jase_questions';
        $sql2 = "CREATE TABLE $table2 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword_id bigint(20) NOT NULL,
            question varchar(500) NOT NULL,
            volume int(11) DEFAULT 0,
            competition_level varchar(20) DEFAULT '',
            ai_score int(3) DEFAULT 0,
            selected tinyint(1) DEFAULT 0,
            post_id bigint(20) DEFAULT 0,
            status varchar(50) DEFAULT 'available',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql2 );

        update_option( 'jase_version', JASE_VERSION );
        update_option( 'jase_setup_complete', false );
    }

    public function deactivate() {
        // Cleanup scheduled events
        wp_clear_scheduled_hook( 'jase_weekly_content_generation' );
    }
}

JustAutomateSEO::instance();
