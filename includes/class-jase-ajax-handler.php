<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Ajax_Handler {

    public function __construct() {
        // Settings
        add_action( 'wp_ajax_jase_save_settings', [ $this, 'save_settings' ] );

        // GSC
        add_action( 'wp_ajax_jase_gsc_get_auth_url', [ $this, 'gsc_get_auth_url' ] );
        add_action( 'wp_ajax_jase_gsc_get_sites', [ $this, 'gsc_get_sites' ] );
        add_action( 'wp_ajax_jase_gsc_select_site', [ $this, 'gsc_select_site' ] );
        add_action( 'wp_ajax_jase_gsc_fetch_queries', [ $this, 'gsc_fetch_queries' ] );

        // Keyword Research
        add_action( 'wp_ajax_jase_research_keywords', [ $this, 'research_keywords' ] );
        add_action( 'wp_ajax_jase_research_keywords_batch', [ $this, 'research_keywords_batch' ] );
        add_action( 'wp_ajax_jase_analyze_keywords', [ $this, 'analyze_keywords' ] );
        add_action( 'wp_ajax_jase_select_keywords', [ $this, 'select_keywords' ] );

        // Questions
        add_action( 'wp_ajax_jase_fetch_questions', [ $this, 'fetch_questions' ] );
        add_action( 'wp_ajax_jase_select_questions', [ $this, 'select_questions' ] );

        // Sitemap
        add_action( 'wp_ajax_jase_validate_sitemap', [ $this, 'validate_sitemap' ] );

        // Images
        add_action( 'wp_ajax_jase_suggest_images', [ $this, 'suggest_images' ] );

        // Content Generation
        add_action( 'wp_ajax_jase_generate_content', [ $this, 'generate_content' ] );
        add_action( 'wp_ajax_jase_generate_single_content', [ $this, 'generate_single_content' ] );
        add_action( 'wp_ajax_jase_check_cannibalization', [ $this, 'check_cannibalization' ] );
    }

    private function verify_nonce() {
        if ( ! check_ajax_referer( 'jase_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed' ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }
    }

    public function save_settings() {
        $this->verify_nonce();

        $fields = [ 'openai_api_key', 'gsc_client_id', 'gsc_client_secret' ];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                JASE_Settings::set( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        wp_send_json_success( [ 'message' => 'Settings saved' ] );
    }

    public function gsc_get_auth_url() {
        $this->verify_nonce();
        $gsc = new JASE_Google_Search_Console();
        wp_send_json_success( [ 'url' => $gsc->get_auth_url() ] );
    }

    public function gsc_get_sites() {
        $this->verify_nonce();
        $gsc   = new JASE_Google_Search_Console();
        $sites = $gsc->get_sites();
        if ( is_wp_error( $sites ) ) {
            wp_send_json_error( [ 'message' => $sites->get_error_message() ] );
        }
        wp_send_json_success( [ 'sites' => $sites ] );
    }

    public function gsc_select_site() {
        $this->verify_nonce();
        $site_url = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';
        if ( empty( $site_url ) ) {
            wp_send_json_error( [ 'message' => 'No site URL provided' ] );
        }
        JASE_Settings::set( 'gsc_site_url', $site_url );
        wp_send_json_success( [ 'message' => 'Site selected' ] );
    }

    public function gsc_fetch_queries() {
        $this->verify_nonce();
        $days  = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 90;
        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 40;

        $site_url = JASE_Settings::get_gsc_site_url();
        if ( empty( $site_url ) ) {
            wp_send_json_error( [ 'message' => 'No site selected' ] );
        }

        $gsc     = new JASE_Google_Search_Console();
        $queries = $gsc->get_queries( $site_url, $days, $limit );

        if ( is_wp_error( $queries ) ) {
            wp_send_json_error( [ 'message' => $queries->get_error_message() ] );
        }

        // Format results
        $formatted = [];
        foreach ( $queries as $row ) {
            $formatted[] = [
                'query'       => $row['keys'][0] ?? '',
                'clicks'      => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr'         => round( ( $row['ctr'] ?? 0 ) * 100, 2 ),
                'position'    => round( $row['position'] ?? 0, 1 ),
            ];
        }

        wp_send_json_success( [ 'queries' => $formatted ] );
    }

    public function research_keywords() {
        $this->verify_nonce();
        $query    = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : 'US';

        if ( empty( $query ) ) {
            wp_send_json_error( [ 'message' => 'No query provided' ] );
        }

        $research = new JASE_Keyword_Research();
        $results  = $research->get_keyword_suggestions( $query, $location );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( [ 'message' => $results->get_error_message() ] );
        }

        wp_send_json_success( [ 'keywords' => $results ] );
    }

    public function research_keywords_batch() {
        $this->verify_nonce();
        $queries  = isset( $_POST['queries'] ) ? json_decode( wp_unslash( $_POST['queries'] ), true ) : [];
        $location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : 'US';

        if ( empty( $queries ) || ! is_array( $queries ) ) {
            wp_send_json_error( [ 'message' => 'No queries provided. Please select at least one GSC query.' ] );
        }

        $research     = new JASE_Keyword_Research();
        $all_keywords = [];
        $errors       = [];
        $success_count = 0;

        error_log( 'Starting batch keyword research for ' . count( $queries ) . ' queries in location: ' . $location );

        foreach ( array_slice( $queries, 0, 10 ) as $query ) {
            $query = sanitize_text_field( $query );
            if ( empty( $query ) ) continue;

            error_log( 'Fetching keywords for: ' . $query );
            $results = $research->get_keyword_suggestions( $query, $location );

            if ( is_wp_error( $results ) ) {
                $errors[] = '"' . $query . '": ' . $results->get_error_message();
                error_log( 'API Error for "' . $query . '": ' . $results->get_error_message() );
                continue;
            }

            if ( is_array( $results ) && ! empty( $results ) ) {
                $count_before = count( $all_keywords );
                $all_keywords = array_merge( $all_keywords, $results );
                $added = count( $all_keywords ) - $count_before;
                error_log( 'Added ' . $added . ' keywords for "' . $query . '"' );
                $success_count++;
            } else {
                $errors[] = '"' . $query . '": API returned empty array';
                error_log( 'Empty result for "' . $query . '"' );
            }
        }

        error_log( 'Total keywords before dedup: ' . count( $all_keywords ) );

        // Deduplicate by keyword text
        $seen  = [];
        $deduped = [];
        foreach ( $all_keywords as $kw ) {
            $text = is_array( $kw ) ? ( $kw['text'] ?? ( $kw['keyword'] ?? '' ) ) : $kw;
            $key  = strtolower( trim( $text ) );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $deduped[]    = $kw;
            }
        }

        error_log( 'Keywords after dedup: ' . count( $deduped ) );

        if ( empty( $deduped ) ) {
            $msg = 'No keywords found for the selected queries.';
            $msg .= ' Tried ' . count( $queries ) . ' queries in location "' . $location . '", succeeded: ' . $success_count . '.';
            if ( ! empty( $errors ) ) {
                $msg .= ' Errors: ' . implode( ' | ', $errors );
            }
            $msg .= ' Check WordPress debug.log for details.';
            error_log( 'FINAL ERROR: ' . $msg );
            wp_send_json_error( [ 'message' => $msg ] );
        }

        wp_send_json_success( [
            'keywords' => $deduped,
            'errors'   => $errors,
        ] );
    }

    public function analyze_keywords() {
        $this->verify_nonce();
        $keywords = isset( $_POST['keywords'] ) ? json_decode( wp_unslash( $_POST['keywords'] ), true ) : [];

        if ( empty( $keywords ) ) {
            wp_send_json_error( [ 'message' => 'No keywords provided' ] );
        }

        $ai     = new JASE_AI_Analysis();
        $result = $ai->analyze_keywords( $keywords );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'analyzed' => $result ] );
    }

    public function select_keywords() {
        $this->verify_nonce();
        $selected = isset( $_POST['selected'] ) ? json_decode( wp_unslash( $_POST['selected'] ), true ) : [];

        if ( empty( $selected ) || count( $selected ) > 5 ) {
            wp_send_json_error( [ 'message' => 'Select between 1 and 5 keywords' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jase_keywords';

        foreach ( $selected as $kw ) {
            $wpdb->replace( $table, [
                'query'             => sanitize_text_field( $kw['text'] ),
                'volume'            => absint( $kw['volume'] ?? 0 ),
                'competition_level' => sanitize_text_field( $kw['competition_level'] ?? '' ),
                'ai_score'          => absint( $kw['score'] ?? 0 ),
                'status'            => 'selected',
                'selected'          => 1,
            ] );
        }

        // Store in transient for wizard flow
        set_transient( 'jase_selected_keywords', $selected, HOUR_IN_SECONDS );

        wp_send_json_success( [ 'message' => 'Keywords saved' ] );
    }

    public function fetch_questions() {
        $this->verify_nonce();
        $keywords = isset( $_POST['keywords'] ) ? json_decode( wp_unslash( $_POST['keywords'] ), true ) : [];
        $location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : 'US';

        if ( empty( $keywords ) ) {
            $keywords = get_transient( 'jase_selected_keywords' );
        }

        if ( empty( $keywords ) ) {
            wp_send_json_error( [ 'message' => 'No keywords available' ] );
        }

        $research = new JASE_Keyword_Research();
        $ai       = new JASE_AI_Analysis();
        $all_questions = [];

        foreach ( $keywords as $kw ) {
            $keyword_text = is_array( $kw ) ? ( $kw['text'] ?? '' ) : $kw;
            if ( empty( $keyword_text ) ) continue;

            $questions = $research->get_questions( $keyword_text, $location );
            if ( is_wp_error( $questions ) ) continue;

            $analyzed = $ai->analyze_questions( $questions, $keyword_text );
            if ( is_wp_error( $analyzed ) ) continue;

            foreach ( $analyzed as &$q ) {
                $q['parent_keyword'] = $keyword_text;
            }
            $all_questions = array_merge( $all_questions, $analyzed );
        }

        // Store for wizard flow
        set_transient( 'jase_analyzed_questions', $all_questions, HOUR_IN_SECONDS );

        wp_send_json_success( [ 'questions' => $all_questions ] );
    }

    public function select_questions() {
        $this->verify_nonce();
        $selected = isset( $_POST['selected'] ) ? json_decode( wp_unslash( $_POST['selected'] ), true ) : [];

        if ( empty( $selected ) || count( $selected ) > 5 ) {
            wp_send_json_error( [ 'message' => 'Select between 1 and 5 questions per week' ] );
        }

        set_transient( 'jase_selected_questions', $selected, HOUR_IN_SECONDS );

        global $wpdb;
        $table = $wpdb->prefix . 'jase_questions';

        foreach ( $selected as $q ) {
            $wpdb->insert( $table, [
                'keyword_id' => 0,
                'question'   => sanitize_text_field( $q['text'] ),
                'volume'     => absint( $q['volume'] ?? 0 ),
                'ai_score'   => absint( $q['score'] ?? 0 ),
                'selected'   => 1,
                'status'     => 'selected',
            ] );
        }

        wp_send_json_success( [ 'message' => 'Questions saved' ] );
    }

    public function validate_sitemap() {
        $this->verify_nonce();
        $url = isset( $_POST['sitemap_url'] ) ? esc_url_raw( wp_unslash( $_POST['sitemap_url'] ) ) : '';

        if ( empty( $url ) ) {
            wp_send_json_error( [ 'message' => 'No sitemap URL provided' ] );
        }

        $generator = new JASE_Content_Generator();
        $urls      = $generator->fetch_sitemap_urls( $url );

        if ( is_wp_error( $urls ) ) {
            wp_send_json_error( [ 'message' => $urls->get_error_message() ] );
        }

        JASE_Settings::set( 'sitemap_url', $url );

        wp_send_json_success( [
            'message'   => 'Sitemap validated',
            'url_count' => count( $urls ),
            'sample'    => array_slice( $urls, 0, 5 ),
        ] );
    }

    public function suggest_images() {
        $this->verify_nonce();
        $questions = isset( $_POST['questions'] ) ? json_decode( wp_unslash( $_POST['questions'] ), true ) : [];

        if ( empty( $questions ) ) {
            $questions = get_transient( 'jase_selected_questions' );
        }

        $ai          = new JASE_AI_Analysis();
        $suggestions = [];

        foreach ( $questions as $q ) {
            $text   = is_array( $q ) ? ( $q['text'] ?? '' ) : $q;
            $prompts = $ai->suggest_image_prompts( $text, $q['parent_keyword'] ?? $text );
            if ( ! is_wp_error( $prompts ) ) {
                $suggestions[] = [
                    'question' => $text,
                    'prompts'  => $prompts,
                ];
            }
        }

        wp_send_json_success( [ 'suggestions' => $suggestions ] );
    }

    public function generate_content() {
        $this->verify_nonce();

        $questions    = isset( $_POST['questions'] ) ? json_decode( wp_unslash( $_POST['questions'] ), true ) : [];
        $category_id  = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        $post_status  = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'draft';
        $auto_images  = isset( $_POST['auto_images'] ) && $_POST['auto_images'] === 'true';
        $image_prompts = isset( $_POST['image_prompts'] ) ? json_decode( wp_unslash( $_POST['image_prompts'] ), true ) : [];

        if ( empty( $questions ) ) {
            // Called from queue mode after all articles generated individually
            update_option( 'jase_setup_complete', true );
            wp_send_json_success( [ 'posts' => [] ] );
        }

        $ai        = new JASE_AI_Analysis();
        $generator = new JASE_Content_Generator();
        $sitemap_urls = $this->get_shuffled_sitemap_urls();

        $created_posts = [];

        foreach ( $questions as $index => $q ) {
            $question_text  = is_array( $q ) ? ( $q['text'] ?? '' ) : $q;
            $parent_keyword = is_array( $q ) ? ( $q['parent_keyword'] ?? $question_text ) : $question_text;

            if ( empty( $question_text ) ) continue;

            // Generate title
            $title = $ai->generate_title( $question_text );
            if ( is_wp_error( $title ) ) {
                $title = ucfirst( $question_text );
            }
            $title = trim( str_replace( '"', '', $title ) );

            // Generate content
            $content = $ai->generate_content( $title, $parent_keyword, [ $question_text ], $sitemap_urls );
            if ( is_wp_error( $content ) ) {
                $created_posts[] = [
                    'question' => $question_text,
                    'error'    => $content->get_error_message(),
                ];
                continue;
            }

            // Clean content
            $content = preg_replace( '/^```html\s*/s', '', $content );
            $content = preg_replace( '/\s*```$/s', '', $content );

            // Handle featured image
            $image_url = '';
            if ( $auto_images ) {
                $prompt = "Create a professional featured image for a blog article about \"{$question_text}\". Style: Modern, professional. Format: Landscape hero image. Avoid text and logos.";
                if ( ! empty( $image_prompts[ $index ]['prompt'] ) ) {
                    $prompt = $image_prompts[ $index ]['prompt'];
                }
                $image_url = $ai->generate_image( $prompt );
                if ( is_wp_error( $image_url ) ) {
                    $image_url = '';
                }
            }

            $post_id = $generator->create_post( $title, $content, $post_status, $category_id, $image_url );

            if ( is_wp_error( $post_id ) ) {
                $created_posts[] = [
                    'question' => $question_text,
                    'error'    => $post_id->get_error_message(),
                ];
            } else {
                // Store parent keyword as post meta for cannibalization tracking
                update_post_meta( $post_id, '_jase_parent_keyword', sanitize_text_field( $parent_keyword ) );

                $created_posts[] = [
                    'question' => $question_text,
                    'post_id'  => $post_id,
                    'title'    => $title,
                    'status'   => $post_status,
                    'edit_url' => get_edit_post_link( $post_id, 'raw' ),
                ];

                // Update question status in DB
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'jase_questions',
                    [ 'post_id' => $post_id, 'status' => 'used' ],
                    [ 'question' => $question_text ],
                    [ '%d', '%s' ],
                    [ '%s' ]
                );
            }
        }

        update_option( 'jase_setup_complete', true );

        wp_send_json_success( [ 'posts' => $created_posts ] );
    }

    /**
     * Generate a single article (called from frontend queue).
     */
    public function generate_single_content() {
        $this->verify_nonce();

        $question     = isset( $_POST['question'] ) ? json_decode( wp_unslash( $_POST['question'] ), true ) : null;
        $category_id  = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        $post_status  = isset( $_POST['post_status'] ) ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) ) : 'draft';
        $auto_images  = isset( $_POST['auto_images'] ) && $_POST['auto_images'] === 'true';

        if ( empty( $question ) ) {
            wp_send_json_error( [ 'message' => 'No question provided' ] );
        }

        $question_text  = is_array( $question ) ? ( $question['text'] ?? '' ) : $question;
        $parent_keyword = is_array( $question ) ? ( $question['parent_keyword'] ?? $question_text ) : $question_text;

        if ( empty( $question_text ) ) {
            wp_send_json_error( [ 'message' => 'Empty question text' ] );
        }

        // Cannibalization check
        $existing_count = $this->count_keyword_articles( $parent_keyword );
        if ( $existing_count >= 3 ) {
            wp_send_json_error( [
                'message'          => 'Keyword limit reached: "' . $parent_keyword . '" already has ' . $existing_count . ' articles. To avoid keyword cannibalization (competing with your own content in search results), choose a different topic.',
                'cannibalization'  => true,
                'existing_count'   => $existing_count,
                'keyword'          => $parent_keyword,
            ] );
        }

        $ai        = new JASE_AI_Analysis();
        $generator = new JASE_Content_Generator();
        $sitemap_urls = $this->get_shuffled_sitemap_urls();

        // Generate title
        $title = $ai->generate_title( $question_text );
        if ( is_wp_error( $title ) ) {
            $title = ucfirst( $question_text );
        }
        $title = trim( str_replace( '"', '', $title ) );

        // Generate content
        $content = $ai->generate_content( $title, $parent_keyword, [ $question_text ], $sitemap_urls );
        if ( is_wp_error( $content ) ) {
            wp_send_json_error( [ 'message' => $content->get_error_message(), 'question' => $question_text ] );
        }

        // Clean content
        $content = preg_replace( '/^```html\s*/s', '', $content );
        $content = preg_replace( '/\s*```$/s', '', $content );

        // Handle featured image
        $image_url = '';
        if ( $auto_images ) {
            $prompt = "Create a professional featured image for a blog article about \"{$question_text}\". Style: Modern, professional. Format: Landscape hero image. Avoid text and logos.";
            $image_url = $ai->generate_image( $prompt );
            if ( is_wp_error( $image_url ) ) {
                $image_url = '';
            }
        }

        $post_id = $generator->create_post( $title, $content, $post_status, $category_id, $image_url );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message(), 'question' => $question_text ] );
        }

        // Store parent keyword for cannibalization tracking
        update_post_meta( $post_id, '_jase_parent_keyword', sanitize_text_field( $parent_keyword ) );

        // Update question status in DB
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'jase_questions',
            [ 'post_id' => $post_id, 'status' => 'used' ],
            [ 'question' => $question_text ],
            [ '%d', '%s' ],
            [ '%s' ]
        );

        wp_send_json_success( [
            'post' => [
                'question' => $question_text,
                'post_id'  => $post_id,
                'title'    => $title,
                'status'   => $post_status,
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            ],
        ] );
    }

    /**
     * Check keyword cannibalization before generation.
     */
    public function check_cannibalization() {
        $this->verify_nonce();

        $questions = isset( $_POST['questions'] ) ? json_decode( wp_unslash( $_POST['questions'] ), true ) : [];
        $warnings  = [];

        foreach ( $questions as $q ) {
            $keyword = is_array( $q ) ? ( $q['parent_keyword'] ?? ( $q['text'] ?? '' ) ) : $q;
            if ( empty( $keyword ) ) continue;

            $count = $this->count_keyword_articles( $keyword );
            if ( $count > 0 ) {
                $warnings[] = [
                    'keyword'        => $keyword,
                    'existing_count' => $count,
                    'remaining'      => max( 0, 3 - $count ),
                    'at_limit'       => $count >= 3,
                ];
            }
        }

        wp_send_json_success( [ 'warnings' => $warnings ] );
    }

    /**
     * Count existing articles for a parent keyword.
     */
    private function count_keyword_articles( $keyword ) {
        global $wpdb;

        $keyword_lower = strtolower( trim( $keyword ) );

        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_jase_parent_keyword'
             AND LOWER(pm.meta_value) = %s
             AND p.post_status IN ('publish', 'draft', 'pending')",
            $keyword_lower
        ) );

        return absint( $count );
    }

    /**
     * Get shuffled sitemap URLs for internal linking.
     */
    private function get_shuffled_sitemap_urls() {
        $sitemap_url = JASE_Settings::get( 'sitemap_url', '' );
        if ( empty( $sitemap_url ) ) {
            return '';
        }

        $generator = new JASE_Content_Generator();
        $urls      = $generator->fetch_sitemap_urls( $sitemap_url );

        if ( is_wp_error( $urls ) || empty( $urls ) ) {
            return '';
        }

        // Shuffle for variety across articles, take up to 20
        shuffle( $urls );
        $selected = array_slice( $urls, 0, min( 20, count( $urls ) ) );

        return implode( "\n", $selected );
    }
}
