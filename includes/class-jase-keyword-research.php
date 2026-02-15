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
        $url = 'https://suggestqueries.google.com/complete/search?' . http_build_query( [
            'client' => 'firefox',
            'q'      => $query,
            'hl'     => $lang,
            'gl'     => $location,
        ] );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'Google Autocomplete error for "' . $query . '": ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $error_msg = 'Google Autocomplete returned status ' . $code;
            error_log( $error_msg );
            return new WP_Error( 'api_error', $error_msg );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            return new WP_Error( 'parse_error', 'Invalid response from Google Autocomplete' );
        }

        return $body[1];
    }
}
