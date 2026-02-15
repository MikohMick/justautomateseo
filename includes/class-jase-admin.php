<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'handle_gsc_callback' ] );
    }

    public function add_menu() {
        add_menu_page(
            'JustAutomateSEO',
            'JustAutomateSEO',
            'manage_options',
            'justautomateseo',
            [ $this, 'render_wizard_page' ],
            'dashicons-chart-area',
            30
        );

        add_submenu_page(
            'justautomateseo',
            'Setup Wizard',
            'Setup Wizard',
            'manage_options',
            'justautomateseo',
            [ $this, 'render_wizard_page' ]
        );

        add_submenu_page(
            'justautomateseo',
            'Settings',
            'Settings',
            'manage_options',
            'justautomateseo-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'justautomateseo' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'jase-admin',
            JASE_PLUGIN_URL . 'admin/css/admin.css',
            [],
            JASE_VERSION
        );

        wp_enqueue_script(
            'jase-admin',
            JASE_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            JASE_VERSION,
            true
        );

        wp_localize_script( 'jase-admin', 'jaseAdmin', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'jase_nonce' ),
            'isConnected'  => ! empty( JASE_Settings::get_gsc_tokens() ),
            'siteUrl'      => JASE_Settings::get_gsc_site_url(),
            'setupComplete' => get_option( 'jase_setup_complete', false ),
        ] );
    }

    public function handle_gsc_callback() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'justautomateseo' ) {
            return;
        }
        if ( ! isset( $_GET['gsc_callback'] ) || ! isset( $_GET['code'] ) ) {
            return;
        }

        $gsc    = new JASE_Google_Search_Console();
        $result = $gsc->exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );

        if ( is_wp_error( $result ) ) {
            add_action( 'admin_notices', function() use ( $result ) {
                echo '<div class="notice notice-error"><p>GSC Connection failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
            } );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=justautomateseo&gsc_connected=1' ) );
            exit;
        }
    }

    public function render_settings_page() {
        $settings = JASE_Settings::get_all();
        ?>
        <div class="wrap jase-settings-wrap">
            <h1>JustAutomateSEO Settings</h1>
            <div class="jase-settings-card">
                <h2>API Keys</h2>
                <p class="description">Configure your API keys. These will be moved to a remote server in a future update.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="rapidapi_key">RapidAPI Key</label></th>
                        <td>
                            <input type="password" id="rapidapi_key" name="rapidapi_key" class="regular-text"
                                value="<?php echo esc_attr( $settings['rapidapi_key'] ?? '' ); ?>" />
                            <p class="description">For Google Keyword Insight API</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="openai_api_key">OpenAI API Key</label></th>
                        <td>
                            <input type="password" id="openai_api_key" name="openai_api_key" class="regular-text"
                                value="<?php echo esc_attr( $settings['openai_api_key'] ?? '' ); ?>" />
                            <p class="description">For AI analysis and content generation</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gsc_client_id">Google OAuth Client ID</label></th>
                        <td>
                            <input type="text" id="gsc_client_id" name="gsc_client_id" class="regular-text"
                                value="<?php echo esc_attr( $settings['gsc_client_id'] ?? '' ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gsc_client_secret">Google OAuth Client Secret</label></th>
                        <td>
                            <input type="password" id="gsc_client_secret" name="gsc_client_secret" class="regular-text"
                                value="<?php echo esc_attr( $settings['gsc_client_secret'] ?? '' ); ?>" />
                        </td>
                    </tr>
                </table>
                <p><button type="button" class="button button-primary" id="jase-save-settings">Save Settings</button>
                <span class="jase-save-spinner spinner"></span></p>
            </div>
        </div>
        <?php
    }

    public function render_wizard_page() {
        $categories = get_categories( [ 'hide_empty' => false ] );
        ?>
        <div class="wrap jase-wrap">
            <div class="jase-header">
                <h1>JustAutomateSEO</h1>
                <p class="jase-subtitle">AI-Powered SEO Content Automation</p>
            </div>

            <div class="jase-wizard">
                <!-- Step 1: Connect Google Search Console -->
                <div class="jase-accordion" data-step="1">
                    <div class="jase-accordion-header" data-step="1">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">1</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Connect Google Search Console</h3>
                            <p>Link your website to fetch ranking data</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="1">
                        <div class="jase-step-content">
                            <div id="jase-gsc-not-connected">
                                <p>Connect your Google Search Console to automatically fetch queries your website ranks for.</p>
                                <div class="jase-gsc-prereqs">
                                    <p><strong>Prerequisites:</strong> Make sure you have configured your Google OAuth credentials in
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=justautomateseo-settings' ) ); ?>">Settings</a> first.</p>
                                </div>
                                <button type="button" class="button button-primary jase-btn" id="jase-connect-gsc">
                                    <span class="dashicons dashicons-google"></span> Connect to Google Search Console
                                </button>
                            </div>
                            <div id="jase-gsc-connected" style="display:none;">
                                <div class="jase-success-badge">
                                    <span class="dashicons dashicons-yes-alt"></span> Connected to Google Search Console
                                </div>
                                <div id="jase-site-selector" style="display:none;">
                                    <label for="jase-site-select"><strong>Select your website:</strong></label>
                                    <select id="jase-site-select" class="jase-select"></select>
                                    <button type="button" class="button button-primary jase-btn" id="jase-select-site">Select Site</button>
                                </div>
                                <div id="jase-site-selected" style="display:none;">
                                    <p>Site: <strong id="jase-selected-site-url"></strong></p>
                                </div>
                                <div class="jase-query-filters" id="jase-query-filters" style="display:none;">
                                    <h4>Fetch Ranking Queries</h4>
                                    <div class="jase-filter-row">
                                        <label>Time period:
                                            <select id="jase-days-filter" class="jase-select">
                                                <option value="7">Last 7 days</option>
                                                <option value="28">Last 28 days</option>
                                                <option value="90" selected>Last 3 months</option>
                                                <option value="180">Last 6 months</option>
                                            </select>
                                        </label>
                                        <label>Limit:
                                            <select id="jase-limit-filter" class="jase-select">
                                                <option value="20">20 queries</option>
                                                <option value="40" selected>40 queries</option>
                                                <option value="100">100 queries</option>
                                            </select>
                                        </label>
                                        <button type="button" class="button button-primary jase-btn" id="jase-fetch-queries">
                                            Fetch Queries
                                        </button>
                                    </div>
                                </div>
                                <div id="jase-queries-result" style="display:none;">
                                    <div class="jase-spinner-wrap" id="jase-queries-loading">
                                        <div class="jase-spinner"></div>
                                        <p>Fetching queries from Google Search Console...</p>
                                    </div>
                                    <div id="jase-queries-table-wrap" style="display:none;">
                                        <p class="jase-query-select-hint">Select the queries you want to use as seeds for keyword research (up to 10):</p>
                                        <table class="jase-table" id="jase-queries-table">
                                            <thead>
                                                <tr>
                                                    <th class="jase-th-check"><input type="checkbox" id="jase-query-select-all" title="Select all" /></th>
                                                    <th>Query</th>
                                                    <th>Clicks</th>
                                                    <th>Impressions</th>
                                                    <th>CTR</th>
                                                    <th>Position</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                        <p id="jase-query-select-count" style="display:none;">
                                            <strong><span id="jase-gsc-selected-count">0</span></strong> queries selected
                                        </p>
                                        <button type="button" class="button button-primary jase-btn jase-next-step" data-next="2">
                                            Continue to Keyword Research <span class="dashicons dashicons-arrow-right-alt"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Keyword Research -->
                <div class="jase-accordion" data-step="2">
                    <div class="jase-accordion-header" data-step="2">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">2</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Keyword Research &amp; AI Analysis</h3>
                            <p>Discover high-potential keywords and let AI select the best ones</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="2">
                        <div class="jase-step-content">
                            <div id="jase-keyword-research">
                                <p>We'll research keywords based on your GSC queries and use AI to identify the top 5 opportunities.</p>
                                <div class="jase-filter-row">
                                    <label>Country:
                                        <select id="jase-kw-location" class="jase-select">
                                            <option value="US">United States</option>
                                            <option value="KE">Kenya</option>
                                            <option value="GB">United Kingdom</option>
                                            <option value="CA">Canada</option>
                                            <option value="AU">Australia</option>
                                            <option value="IN">India</option>
                                            <option value="ZA">South Africa</option>
                                            <option value="NG">Nigeria</option>
                                        </select>
                                    </label>
                                    <button type="button" class="button button-primary jase-btn" id="jase-start-keyword-research">
                                        <span class="dashicons dashicons-search"></span> Start Keyword Research
                                    </button>
                                </div>
                                <div class="jase-spinner-wrap" id="jase-kw-loading" style="display:none;">
                                    <div class="jase-spinner"></div>
                                    <p>Researching keywords and running AI analysis... This may take a moment.</p>
                                </div>
                                <div id="jase-kw-results" style="display:none;">
                                    <h4>AI-Recommended Keywords (select up to 5)</h4>
                                    <div id="jase-kw-cards"></div>
                                    <button type="button" class="button button-primary jase-btn" id="jase-confirm-keywords">
                                        Confirm Selected Keywords (<span id="jase-kw-count">0</span>/5)
                                    </button>
                                </div>
                                <div id="jase-kw-confirmed" style="display:none;">
                                    <div class="jase-success-badge">
                                        <span class="dashicons dashicons-yes-alt"></span> Keywords confirmed
                                    </div>
                                    <button type="button" class="button button-primary jase-btn jase-next-step" data-next="3">
                                        Continue to Question Research <span class="dashicons dashicons-arrow-right-alt"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Question Research -->
                <div class="jase-accordion" data-step="3">
                    <div class="jase-accordion-header" data-step="3">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">3</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Question Research</h3>
                            <p>Find the best questions to answer for content creation</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="3">
                        <div class="jase-step-content">
                            <p>We'll find questions people ask about your selected keywords, then AI picks the best ones for content.</p>
                            <button type="button" class="button button-primary jase-btn" id="jase-fetch-questions">
                                <span class="dashicons dashicons-editor-help"></span> Find Questions
                            </button>
                            <div class="jase-spinner-wrap" id="jase-q-loading" style="display:none;">
                                <div class="jase-spinner"></div>
                                <p>Fetching questions and running AI analysis for each keyword...</p>
                            </div>
                            <div id="jase-q-results" style="display:none;">
                                <h4>Select up to 5 questions for content generation this week</h4>
                                <div id="jase-q-cards"></div>
                                <button type="button" class="button button-primary jase-btn" id="jase-confirm-questions">
                                    Confirm Selected Questions (<span id="jase-q-count">0</span>/5)
                                </button>
                            </div>
                            <div id="jase-q-confirmed" style="display:none;">
                                <div class="jase-success-badge">
                                    <span class="dashicons dashicons-yes-alt"></span> Questions confirmed
                                </div>
                                <button type="button" class="button button-primary jase-btn jase-next-step" data-next="4">
                                    Continue to Sitemap Setup <span class="dashicons dashicons-arrow-right-alt"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Sitemap & Internal Linking -->
                <div class="jase-accordion" data-step="4">
                    <div class="jase-accordion-header" data-step="4">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">4</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Sitemap &amp; Internal Linking</h3>
                            <p>Add your post sitemap for intelligent internal linking</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="4">
                        <div class="jase-step-content">
                            <p>Provide your post sitemap XML URL so we can add relevant internal links to your generated content.</p>
                            <div class="jase-filter-row">
                                <input type="url" id="jase-sitemap-url" class="regular-text" placeholder="https://yoursite.com/post-sitemap.xml"
                                    value="<?php echo esc_attr( JASE_Settings::get( 'sitemap_url', '' ) ); ?>" />
                                <button type="button" class="button button-primary jase-btn" id="jase-validate-sitemap">
                                    Validate Sitemap
                                </button>
                            </div>
                            <div class="jase-spinner-wrap" id="jase-sitemap-loading" style="display:none;">
                                <div class="jase-spinner"></div>
                                <p>Validating sitemap...</p>
                            </div>
                            <div id="jase-sitemap-result" style="display:none;"></div>
                            <div id="jase-sitemap-confirmed" style="display:none;">
                                <button type="button" class="button button-primary jase-btn jase-next-step" data-next="5">
                                    Continue to Image Options <span class="dashicons dashicons-arrow-right-alt"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Image Options -->
                <div class="jase-accordion" data-step="5">
                    <div class="jase-accordion-header" data-step="5">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">5</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Featured Images</h3>
                            <p>Choose how featured images are handled</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="5">
                        <div class="jase-step-content">
                            <h4>How should featured images be created?</h4>
                            <div class="jase-image-options">
                                <label class="jase-radio-card">
                                    <input type="radio" name="jase_image_mode" value="auto" checked />
                                    <div class="jase-radio-card-content">
                                        <span class="dashicons dashicons-format-image"></span>
                                        <strong>Automatic (AI Generated)</strong>
                                        <p>DALL-E will generate professional featured images for each post</p>
                                    </div>
                                </label>
                                <label class="jase-radio-card">
                                    <input type="radio" name="jase_image_mode" value="manual" />
                                    <div class="jase-radio-card-content">
                                        <span class="dashicons dashicons-upload"></span>
                                        <strong>Manual Upload</strong>
                                        <p>You'll upload featured images manually after posts are created</p>
                                    </div>
                                </label>
                                <label class="jase-radio-card">
                                    <input type="radio" name="jase_image_mode" value="none" />
                                    <div class="jase-radio-card-content">
                                        <span class="dashicons dashicons-no"></span>
                                        <strong>No Featured Image</strong>
                                        <p>Posts will be created without featured images</p>
                                    </div>
                                </label>
                            </div>
                            <div id="jase-image-suggestions" style="display:none;">
                                <div class="jase-spinner-wrap" id="jase-img-loading" style="display:none;">
                                    <div class="jase-spinner"></div>
                                    <p>Generating image suggestions...</p>
                                </div>
                                <div id="jase-img-prompts"></div>
                            </div>
                            <button type="button" class="button button-primary jase-btn jase-next-step" data-next="6">
                                Continue to Content Generation <span class="dashicons dashicons-arrow-right-alt"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 6: Generate Content -->
                <div class="jase-accordion" data-step="6">
                    <div class="jase-accordion-header" data-step="6">
                        <div class="jase-step-indicator">
                            <span class="jase-step-number">6</span>
                            <span class="jase-step-check dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="jase-step-info">
                            <h3>Generate &amp; Publish Content</h3>
                            <p>Create SEO-optimized articles and publish to WordPress</p>
                        </div>
                        <span class="jase-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                    <div class="jase-accordion-body" data-step="6">
                        <div class="jase-step-content">
                            <div class="jase-publish-options">
                                <div class="jase-filter-row">
                                    <label>Post Category:
                                        <select id="jase-category" class="jase-select">
                                            <option value="0">Uncategorized</option>
                                            <?php foreach ( $categories as $cat ) : ?>
                                                <option value="<?php echo esc_attr( $cat->term_id ); ?>">
                                                    <?php echo esc_html( $cat->name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Post Status:
                                        <select id="jase-post-status" class="jase-select">
                                            <option value="draft">Draft</option>
                                            <option value="publish">Published</option>
                                            <option value="pending">Pending Review</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                            <div id="jase-generation-summary">
                                <h4>Content to Generate</h4>
                                <ul id="jase-gen-questions-list"></ul>
                            </div>
                            <button type="button" class="button button-primary button-hero jase-btn" id="jase-generate-content">
                                <span class="dashicons dashicons-edit-large"></span> Generate Content Now
                            </button>
                            <div class="jase-spinner-wrap" id="jase-gen-loading" style="display:none;">
                                <div class="jase-spinner"></div>
                                <p id="jase-gen-status">Generating content... This may take several minutes.</p>
                            </div>
                            <div id="jase-gen-results" style="display:none;">
                                <h4>Generated Posts</h4>
                                <div id="jase-gen-posts"></div>
                                <div class="jase-success-badge jase-final-success">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    Setup complete! Your SEO content pipeline is ready.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
