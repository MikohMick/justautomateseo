<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Keyword_Research {

    private $api_key;
    private $api_host = 'google-keyword-insight1.p.rapidapi.com';

    public function __construct() {
        $this->api_key = JASE_Settings::get_rapidapi_key();
    }

    public function get_keyword_suggestions( $keyword, $location = 'US', $lang = 'en' ) {
        $url = 'https://' . $this->api_host . '/urlkeysuggest/?' . http_build_query( [
            'keyword'  => $keyword,
            'location' => $location,
            'lang'     => $lang,
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'x-rapidapi-host' => $this->api_host,
                'x-rapidapi-key'  => $this->api_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            // Include response body in error for debugging
            $error_msg = 'Keyword API returned status ' . $code;
            if ( ! empty( $raw_body ) ) {
                $error_msg .= ' - Response: ' . substr( $raw_body, 0, 200 );
            }
            return new WP_Error( 'api_error', $error_msg );
        }

        $body = json_decode( $raw_body, true );

        // Log for debugging
        error_log( 'RapidAPI Keyword Response for "' . $keyword . '" (location: ' . $location . '): ' . print_r( $body, true ) );

        if ( ! is_array( $body ) ) {
            return new WP_Error( 'parse_error', 'Invalid response from keyword API. Response: ' . substr( $raw_body, 0, 200 ) );
        }

        return $body;
    }

    public function get_questions( $keyword, $location = 'US', $lang = 'en' ) {
        $url = 'https://' . $this->api_host . '/questions/?' . http_build_query( [
            'keyword'  => $keyword,
            'location' => $location,
            'lang'     => $lang,
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'x-rapidapi-host' => $this->api_host,
                'x-rapidapi-key'  => $this->api_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'api_error', 'Questions API returned status ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'parse_error', 'Invalid response from questions API' );
        }

        return $body;
    }
}
