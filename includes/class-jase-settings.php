<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Settings {

    public static function get( $key, $default = '' ) {
        $settings = get_option( 'jase_settings', [] );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    public static function set( $key, $value ) {
        $settings = get_option( 'jase_settings', [] );
        $settings[ $key ] = $value;
        update_option( 'jase_settings', $settings );
    }

    public static function get_all() {
        return get_option( 'jase_settings', [] );
    }

    public static function get_openai_key() {
        return self::get( 'openai_api_key', '' );
    }

    public static function get_gsc_tokens() {
        return get_option( 'jase_gsc_tokens', [] );
    }

    public static function set_gsc_tokens( $tokens ) {
        update_option( 'jase_gsc_tokens', $tokens );
    }

    public static function get_gsc_site_url() {
        return self::get( 'gsc_site_url', '' );
    }
}
