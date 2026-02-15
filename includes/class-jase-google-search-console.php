<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Google_Search_Console {

    private $client_id;
    private $client_secret;
    private $redirect_uri;

    public function __construct() {
        $this->client_id     = JASE_Settings::get( 'gsc_client_id', '' );
        $this->client_secret = JASE_Settings::get( 'gsc_client_secret', '' );
        $this->redirect_uri  = admin_url( 'admin.php?page=justautomateseo&gsc_callback=1' );
    }

    public function get_auth_url() {
        $params = [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
    }

    public function exchange_code( $code ) {
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['access_token'] ) ) {
            $body['expires_at'] = time() + (int) $body['expires_in'];
            JASE_Settings::set_gsc_tokens( $body );
            return $body;
        }

        return new WP_Error( 'gsc_auth_failed', $body['error_description'] ?? 'Authentication failed' );
    }

    public function refresh_token() {
        $tokens = JASE_Settings::get_gsc_tokens();
        if ( empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'no_refresh_token', 'No refresh token available' );
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $tokens['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['access_token'] ) ) {
            $tokens['access_token'] = $body['access_token'];
            $tokens['expires_at']   = time() + (int) $body['expires_in'];
            JASE_Settings::set_gsc_tokens( $tokens );
            return $tokens;
        }

        return new WP_Error( 'refresh_failed', 'Token refresh failed' );
    }

    public function get_access_token() {
        $tokens = JASE_Settings::get_gsc_tokens();
        if ( empty( $tokens['access_token'] ) ) {
            return new WP_Error( 'not_connected', 'Not connected to GSC' );
        }

        if ( isset( $tokens['expires_at'] ) && time() > $tokens['expires_at'] - 60 ) {
            $result = $this->refresh_token();
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $tokens = $result;
        }

        return $tokens['access_token'];
    }

    public function get_sites() {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_get( 'https://www.googleapis.com/webmasters/v3/sites', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['siteEntry'] ?? [];
    }

    public function get_queries( $site_url, $days = 90, $limit = 40 ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $end_date   = gmdate( 'Y-m-d' );
        $start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        $response = wp_remote_post(
            "https://www.googleapis.com/webmasters/v3/sites/" . rawurlencode( $site_url ) . "/searchAnalytics/query",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'startDate'  => $start_date,
                    'endDate'    => $end_date,
                    'dimensions' => [ 'query' ],
                    'rowLimit'   => (int) $limit,
                    'type'       => 'web',
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['rows'] ?? [];
    }
}
