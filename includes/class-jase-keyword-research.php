<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Keyword_Research {

    /**
     * Fetch keyword suggestions using Google Autocomplete.
     *
     * @param string $keyword  Seed keyword.
     * @param string $location Country code (e.g. US, KE, GB).
     * @param string $lang     Language code (e.g. en).
     * @return array|WP_Error  Array of keyword objects or WP_Error.
     */
    public function get_keyword_suggestions( $keyword, $location = 'US', $lang = 'en' ) {
        $all_suggestions = [];

        // Base query suggestions
        $results = $this->fetch_autocomplete( $keyword, $location, $lang );
        if ( ! is_wp_error( $results ) ) {
            $all_suggestions = array_merge( $all_suggestions, $results );
        }

        // Expand with alphabet suffixes for more keyword ideas
        $suffixes = [ 'a', 'b', 'c', 'd', 'e' ];
        foreach ( $suffixes as $suffix ) {
            usleep( 100000 ); // 100ms delay to avoid rate limiting
            $results = $this->fetch_autocomplete( $keyword . ' ' . $suffix, $location, $lang );
            if ( ! is_wp_error( $results ) ) {
                $all_suggestions = array_merge( $all_suggestions, $results );
            }
        }

        // Deduplicate
        $all_suggestions = array_values( array_unique( $all_suggestions ) );

        if ( empty( $all_suggestions ) ) {
            error_log( 'Google Autocomplete: no suggestions found for "' . $keyword . '" in ' . $location );
            return [];
        }

        // Format as keyword objects
        $keywords = [];
        foreach ( $all_suggestions as $suggestion ) {
            $keywords[] = [
                'text'              => $suggestion,
                'volume'            => 0,
                'competition_level' => '',
                'source'            => 'google_autocomplete',
            ];
        }

        error_log( 'Google Autocomplete suggestions for "' . $keyword . '" (location: ' . $location . '): ' . count( $keywords ) . ' keywords found' );

        return $keywords;
    }

    /**
     * Fetch questions using Google Autocomplete with question prefixes.
     *
     * @param string $keyword  Seed keyword.
     * @param string $location Country code.
     * @param string $lang     Language code.
     * @return array|WP_Error  Array of question objects or WP_Error.
     */
    public function get_questions( $keyword, $location = 'US', $lang = 'en' ) {
        $prefixes = [
            'how to ',
            'what is ',
            'what are ',
            'why ',
            'when to ',
            'where to ',
            'who ',
            'which ',
            'can ',
            'does ',
            'is ',
        ];

        $all_questions = [];

        foreach ( $prefixes as $prefix ) {
            usleep( 100000 ); // 100ms delay
            $results = $this->fetch_autocomplete( $prefix . $keyword, $location, $lang );
            if ( ! is_wp_error( $results ) ) {
                $all_questions = array_merge( $all_questions, $results );
            }
        }

        // Deduplicate
        $all_questions = array_values( array_unique( $all_questions ) );

        if ( empty( $all_questions ) ) {
            error_log( 'Google Autocomplete: no questions found for "' . $keyword . '" in ' . $location );
            return [];
        }

        // Format as question objects
        $questions = [];
        foreach ( $all_questions as $question ) {
            $questions[] = [
                'text'   => $question,
                'volume' => 0,
            ];
        }

        error_log( 'Google Autocomplete questions for "' . $keyword . '" (location: ' . $location . '): ' . count( $questions ) . ' questions found' );

        return $questions;
    }

    /**
     * Fetch suggestions from Google Autocomplete API.
     *
     * @param string $query    Search query.
     * @param string $location Country code for gl parameter.
     * @param string $lang     Language code for hl parameter.
     * @return array|WP_Error  Array of suggestion strings or WP_Error.
     */
    private function fetch_autocomplete( $query, $location = 'US', $lang = 'en' ) {
        // Use the XML endpoint - more reliable for server-side requests
        $url = 'https://www.google.com/complete/search?' . http_build_query( [
            'client'  => 'gws-wiz',
            'xssi'    => 't',
            'q'       => $query,
            'hl'      => $lang,
            'gl'      => $location,
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => '*/*',
                'Referer'    => 'https://www.google.com/',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'Google Autocomplete error for "' . $query . '": ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            error_log( 'Google Autocomplete returned status ' . $code . ' for "' . $query . '" - body: ' . substr( $raw, 0, 300 ) );
            return new WP_Error( 'api_error', 'Google Autocomplete returned status ' . $code );
        }

        // The gws-wiz endpoint prefixes response with ")]}'\n" - strip it
        $raw = preg_replace( '/^\)\]\}\'\s*\n?/', '', $raw );

        $body = json_decode( $raw, true );

        if ( ! is_array( $body ) ) {
            // Fallback: try the firefox client endpoint
            return $this->fetch_autocomplete_fallback( $query, $location, $lang );
        }

        // Extract suggestion strings from the nested array structure
        $suggestions = [];
        if ( isset( $body[0] ) && is_array( $body[0] ) ) {
            foreach ( $body[0] as $item ) {
                if ( is_array( $item ) && isset( $item[0] ) && is_string( $item[0] ) ) {
                    // Strip any HTML tags from suggestions
                    $text = strip_tags( $item[0] );
                    if ( ! empty( $text ) ) {
                        $suggestions[] = $text;
                    }
                }
            }
        }

        if ( empty( $suggestions ) ) {
            error_log( 'Google Autocomplete: could not parse suggestions for "' . $query . '", trying fallback' );
            return $this->fetch_autocomplete_fallback( $query, $location, $lang );
        }

        return $suggestions;
    }

    /**
     * Fallback autocomplete using the toolbar/firefox endpoint.
     */
    private function fetch_autocomplete_fallback( $query, $location = 'US', $lang = 'en' ) {
        $url = 'https://clients1.google.com/complete/search?' . http_build_query( [
            'client' => 'firefox',
            'q'      => $query,
            'hl'     => $lang,
            'gl'     => $location,
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'     => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'Google Autocomplete fallback returned status ' . $code . ' for "' . $query . '"' );
            return new WP_Error( 'api_error', 'Google Autocomplete fallback returned status ' . $code );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            return new WP_Error( 'parse_error', 'Invalid response from Google Autocomplete fallback' );
        }

        return $body[1];
    }
}
