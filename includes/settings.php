<?php
class AlfredsToolboxSettings {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_init', [$this, 'handle_redirect'], 1);
        add_action('admin_menu', [$this, 'register_menu_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('update_option_alfreds_toolbox_license_key', [$this, 'validate_and_save_license'], 10, 0);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_clear_spotify_cache', [$this, 'handle_clear_cache']);
        add_action('wp_ajax_save_alfreds_toolbox_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_get_analytics_data', [$this, 'handle_analytics_data']);
        add_action('wp_ajax_clear_analytics_cache', [$this, 'handle_analytics_cache_clear']);

        // Standardwerte bei Aktivierung setzen
        $this->ensure_options_exist();
    }

    private function ensure_options_exist() {
        $default_options = [
            'spotify_client_id' => '',
            'spotify_client_secret' => '',
            'spotify_cache_duration' => 3600,
            'support_package' => 1,
            'support_id' => '',
            'intro_video' => '',
            'replace_dashboard' => false,
            'ga_property_id' => '',
            'ga_api_secret' => '',
            'tutorial_videos' => [],
            'alfreds_toolbox_active_widgets' => []
        ];
    
        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    public function handle_redirect() {
        global $pagenow;
        if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === 'alfreds-toolbox') {
            $replace_dashboard = get_option('replace_dashboard', false);
            if ($replace_dashboard) {
                wp_redirect(admin_url('index.php?page=alfreds-toolbox'));
                exit;
            }
        }
    }

    public function register_menu_pages() {
        $replace_dashboard = get_option('replace_dashboard', false);
        
        if ($replace_dashboard) {
            // Dashboard ersetzen
            remove_menu_page('index.php');
            add_menu_page(
                'Dashboard',
                'Dashboard', 
                'manage_options',
                'alfreds-toolbox',
                [$this, 'render_settings_page'],
                'dashicons-dashboard',
                2
            );
            add_submenu_page(
                'alfreds-toolbox',
                'Startseite',
                'Startseite',
                'manage_options',
                'alfreds-toolbox'
            );
            add_submenu_page(
                'alfreds-toolbox',
                'Aktualisierungen',
                'Aktualisierungen',
                'update_core',
                'update-core.php'
            );
        } else {
            add_options_page(
                'Alfreds Toolbox',
                'Alfreds Toolbox',
                'manage_options',
                'alfreds-toolbox',
                [$this, 'render_settings_page']
            );
        }
    }

    public function handle_save_settings() {
        check_ajax_referer('alfreds_toolbox_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    
        $settings = $_POST['settings'] ?? [];
        $update_results = [];
    
        // Option Mapping für korrekte Typen
        $option_types = [
            'spotify_client_id' => 'string',
            'spotify_client_secret' => 'string',
            'spotify_cache_duration' => 'integer',
            'support_package' => 'integer',
            'support_id' => 'string',
            'intro_video' => 'string',
            'ga_property_id' => 'string',
            'ga_api_secret' => 'string',
            'replace_dashboard' => 'boolean',
            'alfreds_toolbox_license_key' => 'string'
        ];
    
        // Speziell den Lizenzschlüssel behandeln
        if (isset($settings['alfreds_toolbox_license_key'])) {
            $new_key = sanitize_text_field($settings['alfreds_toolbox_license_key']);
            $old_key = get_option('alfreds_toolbox_license_key');
    
            if ($new_key !== $old_key) {
                $api_client = new AlfredsToolboxAPI();
                
                if (empty($new_key)) {
                    // Key wurde gelöscht
                    delete_option('alfreds_toolbox_license_key');
                    $api_client->clear_cache();
                    $update_results['alfreds_toolbox_license_key'] = true;
                } else {
                    // Erst Key speichern
                    update_option('alfreds_toolbox_license_key', $new_key);
                    
                    // Dann validieren
                    $validation = $api_client->validate_license();
                    if (!$validation['success']) {
                        // Bei ungültigem Key: Alten Key wiederherstellen
                        if ($old_key) {
                            update_option('alfreds_toolbox_license_key', $old_key);
                        } else {
                            delete_option('alfreds_toolbox_license_key');
                        }
                        wp_send_json_error([
                            'message' => $validation['message'],
                            'results' => $update_results
                        ]);
                        return;
                    }
                    $update_results['alfreds_toolbox_license_key'] = true;
                }
            }
            // Key aus den settings entfernen, damit er nicht in der normalen Verarbeitung landet
            unset($settings['alfreds_toolbox_license_key']);
        }
    
        // Alle anderen Optionen verarbeiten
        foreach ($option_types as $key => $type) {
            if (isset($settings[$key])) {
                $value = $settings[$key];
                
                switch ($type) {
                    case 'integer':
                        $value = (int) $value;
                        break;
                    case 'boolean':
                        $value = (bool) $value;
                        break;
                    case 'string':
                    default:
                        $value = sanitize_text_field($value);
                        break;
                }
    
                if (get_option($key) === false) {
                    add_option($key, '');
                }
    
                $update_result = update_option($key, $value);
                $update_results[$key] = $update_result;
            }
        }
    
        // Überprüfen, ob mindestens ein Update erfolgreich war
        $any_successful = in_array(true, $update_results, true);
    
        if ($any_successful) {
            wp_send_json_success([
                'message' => 'Einstellungen wurden gespeichert',
                'results' => $update_results
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Fehler beim Speichern der Einstellungen',
                'results' => $update_results
            ]);
        }
    }

    public function get_base_domain() {
        return parse_url(get_site_url(), PHP_URL_HOST);
    }

    private function get_icon_svg($icon_name, $class = '') {
        $icon_path = plugin_dir_path(dirname(__FILE__)) . 'assets/icons/' . $icon_name . '.svg';
        if (file_exists($icon_path)) {
            $svg = file_get_contents($icon_path);
            if ($class) {
                $svg = str_replace('<svg', '<svg class="' . esc_attr($class) . '"', $svg);
            }
            return $svg;
        }
        return '';
    }

    public function register_settings() {
        $settings_to_register = [
            'spotify_client_id' => ['type' => 'string'],
            'spotify_client_secret' => ['type' => 'string'],
            'spotify_cache_duration' => ['type' => 'integer', 'default' => 3600],
            'support_package' => ['type' => 'integer', 'default' => 1],
            'support_id' => ['type' => 'string'],
            'intro_video' => ['type' => 'string'],
            'ga_property_id' => ['type' => 'string'],
            'alfreds_toolbox_license_key' => ['type' => 'string'],
            'replace_dashboard' => ['type' => 'boolean', 'default' => false],
            'tutorial_videos' => ['type' => 'array', 'default' => []],
            'alfreds_toolbox_active_widgets' => ['type' => 'array', 'default' => []]
        ];
    
        foreach ($settings_to_register as $option_name => $args) {
            register_setting('alfreds_toolbox_settings', $option_name, $args);
            
            if (get_option($option_name) === false) {
                add_option($option_name, $args['default'] ?? '');
            }
        }
    }

    public function sanitize_tutorial_videos($videos) {
        if (!is_array($videos)) {
            return [];
        }
        
        $sanitized = [];
        foreach ($videos as $video) {
            if (!empty($video['title']) && !empty($video['loom_id'])) {
                $sanitized[] = [
                    'title' => sanitize_text_field($video['title']),
                    'loom_id' => sanitize_text_field($video['loom_id'])
                ];
            }
        }
        return $sanitized;
    }

    public function render_tutorial_videos_section() {
        $videos = get_option('tutorial_videos', []);
        ?>
        <div class="at-section">
            <h2 class="at-section-title">Tutorial Videos</h2>
            <p class="at-section-description">Füge weitere individuelle Tutorial Videos hinzu.</p>
            
            <div id="tutorial-videos-container" class="at-additional-videos">
                <?php
                if (!empty($videos)) {
                    foreach ($videos as $index => $video) {
                        $this->render_video_item($index, $video);
                    }
                }
                ?>
            </div>
            
            <?php if (empty($videos)): ?>
                <div id="tutorial-videos-fallback" class="at-video-item-row at-empty-state">
                    <div class="at-empty-state-icon">
                        <?php echo $this->get_icon_svg('cloud'); ?>
                    </div>
                    <div class="at-empty-title">Klicke, um Dein erstes Video hinzuzufügen</div>
                    <div class="at-empty-description">Du benötigst die Loom Video ID</div>
                </div>
            <?php endif; ?>
    
            <template id="cloud-icon-template">
                <?php echo $this->get_icon_svg('cloud'); ?>
            </template>
    
            <button type="button" id="add-video" class="button at-button is-secondary" style="<?php echo empty($videos) ? 'display: none;' : ''; ?>">
                Weiteres Video hinzufügen
            </button>
            
            <!-- Template for new video items -->
            <template id="video-item-template">
                <?php $this->render_video_item('{{INDEX}}', ['title' => '', 'loom_id' => '']); ?>
            </template>
        </div>
        <?php
    }

    public function handle_analytics_cache_clear() {
        check_ajax_referer('alfreds_toolbox_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    
        $analytics_api = new GoogleAnalyticsAPI(
            get_option('ga_property_id'),
            get_option('ga_api_secret')
        );
        
        $analytics_api->clear_cache();
        wp_send_json_success();
    }
    
    public function handle_analytics_data() {
        check_ajax_referer('alfreds_toolbox_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    
        $date_range = isset($_REQUEST['date_range']) ? sanitize_text_field($_REQUEST['date_range']) : 'last30days';
        $from_cache_only = isset($_REQUEST['from_cache_only']) && $_REQUEST['from_cache_only'] === 'true';
        
        $property_id = get_option('ga_property_id');
        $api_secret = get_option('ga_api_secret');
        
        if (empty($property_id) || empty($api_secret)) {
            wp_send_json_error('Missing configuration');
            return;
        }
        
        // Try to get cached data first
        $cache_key = 'ga_data_' . $property_id . '_' . $date_range;
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            wp_send_json_success($cached_data);
            return;
        }
        
        if ($from_cache_only) {
            wp_send_json_error('No cached data available');
            return;
        }
        
        $analytics_api = new GoogleAnalyticsAPI($property_id, $api_secret);
        $data = $analytics_api->get_analytics_data($date_range);
        
        if (is_wp_error($data)) {
            wp_send_json_error($data->get_error_message());
            return;
        }
        
        wp_send_json_success($data);
    }

    private function render_video_item($index, $video) {
        ?>
        <div class="at-video-item-row">
            <div class="at-form-group">
                <label class="at-label">Video Titel</label>
                <input type="text" 
                       name="tutorial_videos[<?php echo esc_attr($index); ?>][title]"
                       value="<?php echo esc_attr($video['title']); ?>"
                       class="regular-text at-input"
                       placeholder="Team Mitglieder verwalten">
            </div>
            
            <div class="at-form-group">
                <label class="at-label">Loom ID</label>
                <div class="at-input-group">
                    <input type="text" 
                           name="tutorial_videos[<?php echo esc_attr($index); ?>][loom_id]"
                           value="<?php echo esc_attr($video['loom_id']); ?>"
                           class="regular-text at-input"
                           placeholder="0d69a8e5-e695-487a-9c8b-b4261eb4f165">
                </div>
            </div>

            <button type="button" class="at-remove-video at-button is-icon" title="Video entfernen">
                <?php echo $this->get_icon_svg('trash'); ?>
            </button>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="at-dashboard-wrapper">
            <!-- Linke Sidebar -->
            <div class="at-sidebar">
                <div class="at-logo">
                    <img src="<?php echo plugins_url('assets/images/vierless-gmbh-logo.svg', dirname(__FILE__)); ?>" alt="VIERLESS" class="at-logo-desktop">
                    <img src="<?php echo plugins_url('assets/images/vierless-gmbh-logo-icon.svg', dirname(__FILE__)); ?>" alt="VIERLESS" class="at-logo-mobile">
                </div>
                <nav class="at-nav-menu">
                    <a href="#dashboard" class="at-nav-item <?php echo !isset($_GET['tab']) ? 'active' : ''; ?>">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('house'); ?>
                        </span>
                        <span class="at-nav-item-label">Dashboard</span>
                    </a>

                    <a href="#widgets" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('layers'); ?>
                        </span>
                        <span class="at-nav-item-label">Widgets</span>
                    </a>

                    <a href="#integrationen" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('repeat'); ?>
                        </span>
                        <span class="at-nav-item-label">Integrationen</span>
                    </a>

                    <a href="#statistiken" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('analytics'); ?>
                        </span>
                        <span class="at-nav-item-label">Statistiken</span>
                    </a>

                    <a href="#add-ons" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('add-on'); ?>
                        </span>
                        <span class="at-nav-item-label">Add-Ons</span>
                    </a>

                    <a href="#support" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('team'); ?>
                        </span>
                        <span class="at-nav-item-label">Support</span>
                    </a>

                    <a href="#einstellungen" class="at-nav-item">
                        <span class="at-nav-item-icon">
                            <?php echo $this->get_icon_svg('gear'); ?>
                        </span>
                        <span class="at-nav-item-label">Einstellungen</span>
                    </a>
                </nav>
                <div class="at-meta">
                    <div class="at-copyright">© 2024 VIERLESS GmbH</div>
                    <div class="at-version">V.1.0.8</div>
                </div>
            </div>
    
            <!-- Hauptinhalt -->
            <div class="at-main-content">
                <!-- Titlebar -->
                <div class="at-titlebar">
                    <div class="at-titlebar-icon" id="current-section-icon">
                        <?php echo $this->get_icon_svg('house'); ?>
                    </div>
                    <h1 class="at-titlebar-title" id="current-section-title">Dashboard</h1>
                </div>

                <form action="options.php" method="post" id="at-settings-form" autocomplete="off">
                    <?php settings_fields('alfreds_toolbox_settings'); ?>
    
                    <!-- Dashboard Tab -->
                    <div id="dashboard" class="at-tab-content active">
                        <div class="at-tab-inner">
                            <div class="at-main-col">
                                <div class="at-greeting">
                                    <div class="at-greeting-title">
                                        Hallo <?php 
                                        $first_name = get_user_meta(get_current_user_id(), 'first_name', true);
                                        if (!$first_name) {
                                            $first_name = wp_get_current_user()->display_name;
                                        }
                                        echo esc_html($first_name);
                                        ?>!
                                    </div>
                                    <div class="at-greeting-description">Willkommen zurück!</div>
                                </div>
                                <div class="at-section">
                                    <h2 class="at-section-title">VIERLESS Wartung</h2>
                                    <div class="at-section-row">
                                        <?php 
                                        $value = get_option('support_package', 1);
                                        $support_id = get_option('support_id');
                                        $options = $this->get_support_package_options();
                                        $status_class = $value == 1 ? 'is-error' : ($value ? 'is-success' : '');
                                        $icon = $value == 1 ? $this->get_icon_svg('cross', 'at-status-icon') : ($value ? $this->get_icon_svg('check', 'at-status-icon') : '');
                                        ?>

                                        <div class="at-section-description <?php echo $status_class; ?>">
                                            <?php if ($icon): ?>
                                                <?php echo $icon; ?>
                                            <?php endif; ?>
                                            <?php echo esc_html($options[$value] ?? 'Nicht festgelegt'); ?>
                                            <?php if ($value > 1 && $support_id): ?>
                                                <div class="at-support-id"><?php echo 'Support ID: ' . esc_html($support_id); ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($value == 1): ?>
                                            <a href="https://vierless.de/anfrage?utm_campaign=wordpress_alfreds_toolbox&utm_medium=activate_support&utm_source=<?php echo esc_attr($this->get_base_domain()); ?>" target="_blank" class="button at-button is-primary">Vertrieb kontaktieren</a>
                                        <?php elseif ($value == 2): ?>
                                            <a href="https://vierless.de/anfrage?utm_campaign=wordpress_alfreds_toolbox&utm_medium=upgrade_support&utm_source=<?php echo esc_attr($this->get_base_domain()); ?>" target="_blank" class="button at-button is-primary">Jetzt upgraden</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="at-section">
                                    <h2 class="at-section-title">Starthilfe</h2>
                                    <p class="at-section-description">Finde Antworten auf Deine Fragen.</p>
                                    <div class="at-starthilfe">
                                        <?php 
                                        $video_id = get_option('intro_video');
                                        if (!empty($video_id)): ?>
                                            <div class="at-intro-video">
                                                <div class="at-video-embed">
                                                    <div style="position: relative; padding-bottom: 62.5%; height: 0;">
                                                        <iframe src="https://www.loom.com/embed/<?php echo esc_attr($video_id); ?>?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed" 
                                                                frameborder="0" 
                                                                webkitallowfullscreen 
                                                                mozallowfullscreen 
                                                                allowfullscreen 
                                                                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                                                        </iframe>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="at-faq">
                                            <!-- FAQ item -->
                                            <div class="at-faq-item">
                                                <div class="at-faq-title">
                                                    <p>Frage steht hier oben</p>
                                                    <div class="at-faq-icon">
                                                        <?php echo $this->get_icon_svg('chevron-down') ?>
                                                    </div>
                                                </div>
                                                <div class="at-faq-description">
                                                    Antwort kommt hier rein
                                                </div>
                                            </div>

                                            <!-- FAQ item -->
                                            <div class="at-faq-item">
                                                <div class="at-faq-title">
                                                    <p>Frage steht hier oben</p>
                                                    <div class="at-faq-icon">
                                                        <?php echo $this->get_icon_svg('chevron-down') ?>
                                                    </div>
                                                </div>
                                                <div class="at-faq-description">
                                                    Antwort kommt hier rein
                                                </div>
                                            </div>

                                            <!-- FAQ item -->
                                            <div class="at-faq-item">
                                                <div class="at-faq-title">
                                                    <p>Frage steht hier oben</p>
                                                    <div class="at-faq-icon">
                                                        <?php echo $this->get_icon_svg('chevron-down') ?>
                                                    </div>
                                                </div>
                                                <div class="at-faq-description">
                                                    Antwort kommt hier rein
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="at-section">
                                    <h2 class="at-section-title">Ihr Ansprechpartner</h2>
                                    <div class="at-brand-box">
                                        <div class="at-brand-box-content">
                                            <p class="at-brand-box-quote">"Ich hoffe Sie haben viel Freude mit Ihrer Webseite! Bei Fragen stehen ich und mein Team Ihnen selbstverständlich zur Verfügung."</p>
                                            <div class="at-author-box">
                                                <div class="at-author-profile">
                                                    <img src="<?php echo plugins_url('assets/images/leonardo-lemos-rilk-profile.webp', dirname(__FILE__)); ?>" alt="Leonardo Lemos Rilk" />
                                                </div>
                                                <div class="at-author-meta">
                                                    <div class="at-author-name">Leonardo Lemos Rilk</div>
                                                    <div class="at-author-role">Projektmanager VIERLESS GmbH</div>
                                                </div>
                                            </div>
                                            <div class="at-brand-box-bottom">
                                                <div class="at-contact">
                                                    <a href="tel:+49015165186177">+49 (0) 1516 5186177</a>
                                                    <a href="mailto:leonardo@vierless.de">leonardo@vierless.de</a>
                                                </div>
                                                <div class="at-signature">
                                                    <img src="<?php echo plugins_url('assets/images/leonardo-lemos-rilk-unterschrift.svg', dirname(__FILE__)); ?>" alt="Unterschrift Leonardo Lemos Rilk" />
                                                </div>
                                            </div>
                                        </div>
                                        <div class="at-brand-box-badge">
                                            <img src="<?php echo plugins_url('assets/images/vierless-siegel.webp', dirname(__FILE__)); ?>" alt="VIERLESS Badge" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <aside class="at-main-col is-aside">
                                <div class="at-section">
                                    <h2 class="at-section-title">VIERLESS empfehlen</h2>
                                    <a class="at-cta-banner" href="https://vierless.de/affiliates?utm_campaign=wordpress_alfreds_toolbox&utm_medium=affiliate_banner&utm_source=<?php echo esc_attr($this->get_base_domain()); ?>" target="_blank">
                                        <img src="<?php echo plugins_url('assets/images/partner-program.webp', dirname(__FILE__)); ?>" alt="Jetzt Affiliate werden" />
                                    </a>
                                </div>
                                <div class="at-section">
                                    <h2 class="at-section-title">Unser Newsletter</h2>
                                    <div id="at-newsletter-container" class="at-newsletter-container">
                                        <input type="hidden" 
                                            id="newsletter-domain" 
                                            value="<?php echo esc_attr($this->get_base_domain()); ?>">
                                        <input type="hidden"
                                            id="newsletter-language"
                                            value="<?php echo esc_attr(str_replace('_', '-', get_user_locale())); ?>">
                                        <div class="at-form-group">
                                            <input type="email" 
                                                id="newsletter-email" 
                                                class="at-input" 
                                                placeholder="max.muster@abc.de"
                                                autocomplete="off"
                                                autocapitalize="off"
                                                autocorrect="off"
                                                spellcheck="false"
                                                form="newsletter-only">
                                        </div>
                                        <div class="at-form-group at-checkbox-group">
                                            <label class="at-checkbox-label">
                                                <input type="checkbox" id="newsletter-privacy">
                                                <span>Den Hinweisen zum <a href="https://vierless.de/datenschutz" target="_blank">Datenschutz</a> stimme ich zum Erhalt von Newsletter-Mailings zu.</span>
                                            </label>
                                        </div>
                                        <button type="button" id="newsletter-submit" class="button at-button is-primary">Jetzt eintragen</button>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </div>

                    <!-- Widgets Tab -->
                    <div id="widgets" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Alle Widgets</h2>
                            <p class="at-section-description">Erweitere Elementor Pro mit diesen tollen Widgets.</p>
                            <div class="at-widget-grid">
                                <?php 
                                global $alfreds_toolbox;
                                foreach ($alfreds_toolbox->get_widgets() as $widget_id => $widget) : 
                                    $widget_class = $widget['class'];
                                    if (class_exists($widget_class)) {
                                        $widget_instance = new $widget_class();
                                        $widget_icon = $widget_instance->get_icon();
                                    }
                                    $is_active = in_array($widget_id, $alfreds_toolbox->get_active_widgets());
                                ?>
                                    <div class="at-widget-card <?php echo $is_active ? 'is-active' : ''; ?>">
                                        <div class="at-widget-card-header">
                                            <div class="at-widget-icon">
                                                <?php if (strpos($widget_icon, 'fab ') === 0 || strpos($widget_icon, 'fas ') === 0): ?>
                                                    <i class="<?php echo esc_attr($widget_icon); ?>"></i>
                                                <?php else: ?>
                                                    <img src="<?php echo plugins_url('assets/icons/layers.svg', dirname(__FILE__)); ?>" 
                                                        alt="<?php echo esc_attr($widget['name']); ?> Icon">
                                                <?php endif; ?>
                                            </div>
                                            <div class="at-widget-info">
                                                <h3 class="at-widget-title"><?php echo esc_html($widget['name']); ?></h3>
                                                <p class="at-widget-description"><?php echo esc_html($widget['description']); ?></p>
                                            </div>
                                            <div class="at-widget-toggle">
                                                <label class="at-switch">
                                                    <input type="checkbox"
                                                        name="alfreds_toolbox_active_widgets[]"
                                                        value="<?php echo esc_attr($widget_id); ?>"
                                                        <?php checked($is_active); ?>>
                                                    <span class="at-switch-slider"></span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="at-submit-wrapper">
                            <?php submit_button('Änderungen speichern', 'at-button is-primary'); ?>
                        </div>
                    </div>

                    <!-- Integrationen Tab (bereits vorhanden) -->
                    <div id="integrationen" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Spotify API</h2>
                            <p class="at-section-description">Verbinde hier deine Spotify Web App für das Podcasts Widget.</p>
                            <fieldset class="at-form-fieldset">
                                <div class="at-form-group">
                                    <label class="at-label" for="spotify-client-id">Client ID</label>
                                    <?php $this->render_client_id_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label" for="spotify-client-secret">Client Secret</label>
                                    <?php $this->render_client_secret_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label" for="spotify-cache-duration">Cache Dauer</label>
                                    <?php $this->render_cache_duration_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label">Cache Control</label>
                                    <?php $this->render_cache_control_field(); ?>
                                </div>
                            </fieldset>
                        </div>
                        <div class="at-submit-wrapper">
                            <?php submit_button('Änderungen speichern', 'at-button is-primary'); ?>
                        </div>
                    </div>

                    <!-- Statistiken Tab -->
                    <div id="statistiken" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Google Analytics</h2>
                            <?php
                            // Render fallbacks and check if we should show analytics
                            if ($this->render_analytics_fallbacks()):
                            ?>
                                <!-- Analytics Content -->
                                <div id="analytics-data-container">
                                    <!-- Date Range Selector -->
                                    <div class="at-analytics-header">
                                        <div class="at-date-select">
                                            <select id="analytics-date-range" class="at-select">
                                                <option value="today">Heute</option>
                                                <option value="yesterday">Gestern</option>
                                                <option value="last7days">Letzte 7 Tage</option>
                                                <option value="last30days" selected>Letzte 30 Tage</option>
                                                <option value="thisMonth">Dieser Monat</option>
                                                <option value="lastMonth">Letzter Monat</option>
                                            </select>
                                            <button type="button" id="load-analytics-data" class="button at-button is-primary">
                                                Anwenden
                                            </button>
                                        </div>
                                        <div class="at-analytics-actions">
                                            <button type="button" id="clear-analytics-cache" class="at-button is-secondary is-icon">
                                                <?php echo $this->get_icon_svg('refresh'); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Overview Cards -->
                                    <div class="at-analytics-grid">
                                        <!-- Visitors Card -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('eye'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Besucher</h3>
                                                    <p class="at-widget-description">0</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Pageviews Card -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('cursor'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Seitenaufrufe</h3>
                                                    <p class="at-widget-description">0</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Average Duration Card -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('time'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Durchschn. Dauer</h3>
                                                    <p class="at-widget-description">0:00</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Bounce Rate Card -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('exit'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Absprungrate</h3>
                                                    <p class="at-widget-description">0%</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Top Pages -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('flame'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Top Seiten</h3>
                                                    <div class="at-widget-list"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Devices -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('devices'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Geräte</h3>
                                                    <div class="at-widget-list"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Browsers -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('world'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Browser</h3>
                                                    <div class="at-widget-list"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Countries -->
                                        <div class="at-widget-card">
                                            <div class="at-widget-card-header">
                                                <div class="at-widget-icon">
                                                    <?php echo $this->get_icon_svg('flag'); ?>
                                                </div>
                                                <div class="at-widget-info">
                                                    <h3 class="at-widget-title">Länder</h3>
                                                    <div class="at-widget-list"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    $property_id = get_option('ga_property_id');
                                    $cache_key = 'ga_data_' . $property_id . '_' . (isset($_GET['date_range']) ? $_GET['date_range'] : 'last30days');
                                    $cache_data = get_transient($cache_key);
                                    ?>
                                    <div class="at-analytics-meta">
                                        <div class="at-analytics-meta-item">
                                            <span class="at-analytics-meta-label">Zuletzt aktualisiert:</span>
                                            <span class="at-analytics-meta-value">
                                                <?php echo esc_html($this->get_cache_timestamp($cache_key)); ?>
                                            </span>
                                        </div>
                                        <div class="at-analytics-meta-item">
                                            <span class="at-analytics-meta-label">Property ID:</span>
                                            <span class="at-analytics-meta-value"><?php echo esc_html($property_id); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Add-Ons -->
                    <div id="add-ons" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Wartungspakete</h2>
                            <p class="at-section-description">Ihre Webseite in besten Händen.</p>
                            <div class="at-add-ons">
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Basis Paket</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Ihr Einstieg in die digitale Welt – leistungsstarke Webseitenlösungen für jedes Budget.</strong></p>
                                            <p>Modernes Design und zuverlässige Technik – ideal für kleine Unternehmen und Start-ups.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Performance Wartung</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Schnell, sicher, zuverlässig – regelmäßige Updates und Optimierungen für Ihre Website.</strong></p>
                                            <p>Wir halten Ihre Website auf dem neuesten Stand und sorgen für maximale Performance.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="at-section">
                            <h2 class="at-section-title">Noch mehr VIERLESS</h2>
                            <p class="at-section-description">Entdecken Sie weitere Services Ihrer Lieblingsagentur.</p>
                            <div class="at-add-ons">
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Foto- & Videoproduktion</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Hochwertige visuelle Inhalte, die Ihre Marke perfekt in Szene setzen – kreativ und wirkungsvoll.</strong></p>
                                            <p>Professionelle Bilder und Videos, die Ihre Botschaft authentisch vermitteln.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Online Marketing (Google, Facebook, Instagram)</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Gezielte Kampagnen auf den stärksten Plattformen – mehr Reichweite, mehr Erfolg.</strong></p>
                                            <p>Wir bringen Ihre Marke dorthin, wo Ihre Zielgruppe ist – mit messbaren Ergebnissen.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">SEO (Suchmaschinenoptimierung)</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Bessere Rankings, mehr Besucher – wir machen Ihre Website sichtbar.</strong></p>
                                            <p>Durchdachte Strategien, die Sie in den Suchergebnissen ganz nach vorne bringen.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Automatisierungen & API-Integration</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Effizientere Workflows und nahtlose Verbindungen für maximale Produktivität.</strong></p>
                                            <p>Wir automatisieren Ihre Prozesse und verknüpfen Ihre Tools für optimalen Workflow.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Social Media Betreuung</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Professionelle Inhalte und Strategien für Ihre Präsenz auf allen Kanälen.</strong></p>
                                            <p>Wir übernehmen Content, Planung und Interaktion – damit Sie sich aufs Wesentliche konzentrieren können.</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- Add-On -->
                                <div class="at-add-on">
                                    <h3 class="at-add-on-title">Landing Pages & Funnels</h3>
                                    <div class="at-add-on-body">
                                        <div class="at-add-on-image">
                                            <img src="<?php echo plugins_url('assets/images/addon-.webp', dirname(__FILE__)); ?>" alt="Add-On Bild" />
                                        </div>
                                        <div class="at-add-on-text">
                                            <p><strong>Optimierte Seiten, die Kunden überzeugen – der Schlüssel zu mehr Conversions.</strong></p>
                                            <p>Von der ersten Impression bis zum Abschluss – wir gestalten Ihre Conversion-Strategie.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Support Tab -->
                    <div id="support" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Starthilfe</h2>
                            <p class="at-section-description">Lerne, wie du deine Seite selbst bearbeitest und mögliche Fehler behebst.</p>
                            <div class="at-video-grid">
                                <div class="at-video-item">
                                    <div class="at-video-embed">
                                        <div style="position: relative; padding-bottom: 62.5%; height: 0;"><iframe src="https://www.loom.com/embed/aae40397959a4f8fba789a556037f8ce?sid=be891197-4c33-4c86-a0fb-3129767ec3f3?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed=true" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></iframe></div>
                                    </div>
                                    <div class="at-video-caption">Video Beschreibung</div>
                                </div>
                                <div class="at-video-item">
                                    <div class="at-video-embed">
                                        <div style="position: relative; padding-bottom: 62.5%; height: 0;"><iframe src="https://www.loom.com/embed/aae40397959a4f8fba789a556037f8ce?sid=be891197-4c33-4c86-a0fb-3129767ec3f3?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed=true" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></iframe></div>
                                    </div>
                                    <div class="at-video-caption">Video Beschreibung</div>
                                </div>
                                <div class="at-video-item">
                                    <div class="at-video-embed">
                                        <div style="position: relative; padding-bottom: 62.5%; height: 0;"><iframe src="https://www.loom.com/embed/aae40397959a4f8fba789a556037f8ce?sid=be891197-4c33-4c86-a0fb-3129767ec3f3?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed=true" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></iframe></div>
                                    </div>
                                    <div class="at-video-caption">Video Beschreibung</div>
                                </div>
                                <div class="at-video-item">
                                    <div class="at-video-embed">
                                        <div style="position: relative; padding-bottom: 62.5%; height: 0;"><iframe src="https://www.loom.com/embed/aae40397959a4f8fba789a556037f8ce?sid=be891197-4c33-4c86-a0fb-3129767ec3f3?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed=true" frameborder="0" webkitallowfullscreen mozallowfullscreen allowfullscreen style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></iframe></div>
                                    </div>
                                    <div class="at-video-caption">Video Beschreibung</div>
                                </div>
                            </div>
                        </div>
                        <?php 
                        $videos = get_option('tutorial_videos', []);
                        if (!empty($videos)): ?>
                            <div class="at-section">
                                <h2 class="at-section-title">Individuelle Videos</h2>
                                <p class="at-section-description">Entdecken Sie Anleitungen speziell für Ihre Webseite.</p>
                                <div class="at-video-grid">
                                    <?php foreach ($videos as $video): ?>
                                        <div class="at-video-item">
                                            <div class="at-video-embed">
                                                <div style="position: relative; padding-bottom: 62.5%; height: 0;">
                                                    <iframe src="https://www.loom.com/embed/<?php echo esc_attr($video['loom_id']); ?>?hide_owner=true&hide_share=true&hide_title=true&hideEmbedTopBar=true&hide_speed=true" 
                                                            frameborder="0" 
                                                            webkitallowfullscreen 
                                                            mozallowfullscreen 
                                                            allowfullscreen 
                                                            style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
                                                    </iframe>
                                                </div>
                                            </div>
                                            <div class="at-video-caption"><?php echo esc_html($video['title']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="at-section">
                            <h2 class="at-section-title">Experten Hilfe</h2>
                            <div class="at-support-banner">
                                <?php 
                                $options = $this->get_support_package_options();
                                $support_id = get_option('support_id');
                                ?>
                                <div class="at-support-banner-col">
                                    <h3 class="at-support-banner-title">
                                        Erhalten Sie direkte Hilfe von unseren Entwicklern.
                                    </h3>
                                    <?php if ($value == 1): ?>
                                        <a href="https://vierless.de/anfrage?referrer=<?php echo esc_attr($this->get_base_domain()); ?>&support_id=<?php echo esc_attr($support_id); ?>" target="_blank" class="button at-button is-primary">Vertrieb kontaktieren</a>
                                    <?php else: ?>
                                        <a href="https://vierless.de/support?id=<?php echo esc_attr($support_id); ?>" target="_blank" class="button at-button is-primary">Zum Support Formular</a>
                                    <?php endif; ?>
                                </div>
                                <div class="at-support-banner-col">
                                    <div class="at-support-banner-image">
                                        <img src="<?php echo plugins_url('assets/images/support-banner.webp', dirname(__FILE__)); ?>" alt="VIERLESS Support bei der Arbeit" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Einstellungen Tab -->
                    <div id="einstellungen" class="at-tab-content">
                        <div class="at-section">
                        <h2 class="at-section-title">Projekt einrichten</h2>
                            <p class="at-section-description">Grundeinstellungen für den Support.</p>
                            <fieldset class="at-form-fieldset">
                                <div class="at-form-group">
                                    <label class="at-label" for="support">Support Paket</label>
                                    <?php $this->render_support_package_field(); ?>
                                </div>

                                <div class="at-form-group">
                                    <label class="at-label" for="support_id">Support ID</label>
                                    <?php $this->render_support_id_field(); ?>
                                </div>
                            </fieldset>
                        </div>
                        <?php $this->render_tutorial_videos_section(); ?>
                        <div class="at-section">
                            <h2 class="at-section-title">Dashboard</h2>
                            <p class="at-section-description">Konfiguriere hier das WP Dashboard.</p>
                            <fieldset class="at-form-fieldset">

                                <div class="at-form-group">
                                    <label class="at-label" for="support">Intro Video</label>
                                    <?php $this->render_intro_video_field(); ?>
                                </div>

                                <div class="at-form-group">
                                    <label class="at-label" for="support">Dashboard ersetzen</label>
                                    <?php $this->render_dashboard_toggle(); ?>
                                </div>

                            </fieldset>
                        </div>
                        <div class="at-section">
                        <h2 class="at-section-title">Google Analytics</h2>
                            <p class="at-section-description">Verbinde eine Property.</p>
                            <fieldset class="at-form-fieldset">
                                <div class="at-form-group">
                                    <label class="at-label" for="support">Property ID</label>
                                    <?php $this->render_ga_property_id_field(); ?>
                                </div>
                            </fieldset>
                        </div>
                        <div class="at-section">
                            <h2 class="at-section-title">Lizenz</h2>
                            <p class="at-section-description">Verbinde hier deine Plugin Lizenz.</p>
                            <fieldset class="at-form-fieldset">
                                <div class="at-form-group">
                                    <label class="at-label" for="alfreds_toolbox_license_key">Lizenzschlüssel</label>
                                    <?php $this->render_lizenz_field(); ?>
                                </div>
                            </fieldset>
                        </div>
                        <div class="at-submit-wrapper">
                            <?php submit_button('Änderungen speichern', 'at-button is-primary'); ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_analytics_fallbacks() {
        $support_package = get_option('support_package', 1);
        $property_id = get_option('ga_property_id');
        
        // Case 1: Kein Performance Paket
        if ($support_package < 3) {
            ?>
            <div class="at-analytics-fallback">
                <div class="at-analytics-fallback-content">
                    <div class="at-analytics-fallback-icon">
                        <?php echo $this->get_icon_svg('lock'); ?>
                    </div>
                    <h3>Analytics ist Teil des Performance Pakets</h3>
                    <p>Upgrade auf das Performance Paket um detaillierte Statistiken einsehen zu können.</p>
                    <a href="https://vierless.de/anfrage?utm_campaign=wordpress_alfreds_toolbox&utm_medium=upgrade_support&utm_source=<?php echo esc_attr($this->get_base_domain()); ?>" 
                       class="button at-button is-primary" 
                       target="_blank">
                        Jetzt upgraden
                    </a>
                </div>
            </div>
            <?php
            return;
        }
        
        // Case 2: Keine Property ID
        if (empty($property_id)) {
            ?>
            <div class="at-analytics-fallback">
                <div class="at-analytics-fallback-content">
                    <div class="at-analytics-fallback-icon">
                        <?php echo $this->get_icon_svg('settings'); ?>
                    </div>
                    <h3>Google Analytics nicht konfiguriert</h3>
                    <p>Bitte hinterlege deine Google Analytics Property ID in den Einstellungen.</p>
                    <a href="#einstellungen" class="button at-button is-primary at-nav-item">
                        Zu den Einstellungen
                    </a>
                </div>
            </div>
            <?php
            return;
        }
    
        // Case 3 & 4: Property ID Validierung und Berechtigungsprüfung
        try {
            $analytics_api = new GoogleAnalyticsAPI($property_id);
            $validation_result = $analytics_api->validate_property();
            
            if ($validation_result === 'invalid_property') {
                // Case 3: Fehlerhafte Property ID
                ?>
                <div class="at-analytics-fallback">
                    <div class="at-analytics-fallback-content">
                        <div class="at-analytics-fallback-icon">
                            <?php echo $this->get_icon_svg('alert-triangle'); ?>
                        </div>
                        <h3>Property ID ungültig</h3>
                        <p>Die eingetragene Google Analytics Property ID scheint nicht zu existieren. Bitte überprüfe die ID.</p>
                        <a href="#einstellungen" class="button at-button is-primary at-nav-item">
                            Zu den Einstellungen
                        </a>
                    </div>
                </div>
                <?php
                return;
            } elseif ($validation_result === 'permission_denied') {
                // Case 4: Keine Berechtigung
                ?>
                <div class="at-analytics-fallback">
                    <div class="at-analytics-fallback-content">
                        <div class="at-analytics-fallback-icon">
                            <?php echo $this->get_icon_svg('user-x'); ?>
                        </div>
                        <h3>Keine Berechtigung</h3>
                        <p>Der VIERLESS Service Account hat keine Berechtigung auf diese Property zuzugreifen. Bitte füge service@vierless.de als Benutzer in den Google Analytics Einstellungen hinzu.</p>
                        <a href="https://analytics.google.com/analytics/web/#/a<?php echo esc_attr(str_replace('GA-', '', $property_id)); ?>/admin/accountUsersAndProperties" 
                           class="button at-button is-primary"
                           target="_blank">
                            Zu den GA Einstellungen
                        </a>
                    </div>
                </div>
                <?php
                return;
            }
        } catch (Exception $e) {
            // Generischer Fehler-Fallback
            ?>
            <div class="at-analytics-fallback">
                <div class="at-analytics-fallback-content">
                    <div class="at-analytics-fallback-icon">
                        <?php echo $this->get_icon_svg('alert-circle'); ?>
                    </div>
                    <h3>Verbindungsfehler</h3>
                    <p>Es konnte keine Verbindung zu Google Analytics hergestellt werden. Bitte versuche es später erneut.</p>
                </div>
            </div>
            <?php
            return;
        }
    
        // Wenn wir hier ankommen, ist alles in Ordnung und wir können true zurückgeben
        return true;
    }

    private function get_cache_timestamp($cache_key) {
        global $wpdb;
        
        // Prüfen ob die Daten gerade erst geladen wurden
        if (isset($_POST['action']) && $_POST['action'] === 'get_analytics_data' && 
            (!isset($_POST['from_cache_only']) || $_POST['from_cache_only'] !== 'true')) {
            return 'Gerade eben';
        }
        
        // Timeout des Transients holen
        $timeout = get_option('_transient_timeout_' . $cache_key);
        
        if (!$timeout) {
            return 'Jetzt';
        }
        
        // Cache-Erstellungszeit berechnen (Timeout - Cache-Dauer)
        $cache_duration = 7200; // Standard: 2 Stunden
        $creation_time = $timeout - $cache_duration;
        
        // Aktuelle Zeit
        $current_time = time();
        
        // Zeitdifferenz in Minuten
        $diff_minutes = round(($current_time - $creation_time) / 60);
        
        if ($diff_minutes < 1) {
            return 'Gerade eben';
        } elseif ($diff_minutes < 60) {
            return "Vor $diff_minutes " . ($diff_minutes == 1 ? 'Minute' : 'Minuten');
        } elseif ($diff_minutes < 1440) { // weniger als 24 Stunden
            $hours = round($diff_minutes / 60);
            return "Vor $hours " . ($hours == 1 ? 'Stunde' : 'Stunden');
        } else {
            $days = round($diff_minutes / 1440);
            return "Vor $days " . ($days == 1 ? 'Tag' : 'Tagen');
        }
    }

    private function get_spotify_status() {
        $client_id = get_option('spotify-client-id');
        $client_secret = get_option('spotify_client_secret');
        
        if (empty($client_id) || empty($client_secret)) {
            return '<span style="color: red;">⚠️ API-Zugangsdaten fehlen</span>';
        }
        return '<span style="color: green;">✓ Aktiv</span>';
    }

    public function render_ga_property_id_field() {
        $value = get_option('ga_property_id');
        echo '<input type="text" placeholder="GA-XXXXXXXXX" name="ga_property_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }

    public function validate_and_save_license() {
        if (!isset($_POST['alfreds_toolbox_license_key'])) {
            return;
        }
    
        $license_key = sanitize_text_field($_POST['alfreds_toolbox_license_key']);
        $old_key = get_option('alfreds_toolbox_license_key');
    
        // Wenn der Key gelöscht oder geändert wurde
        if ($license_key !== $old_key) {
            // API Client instanziieren
            $api_client = new AlfredsToolboxAPI();
            
            // Cache leeren
            $api_client->clear_cache();
            
            if (empty($license_key)) {
                // Key wurde gelöscht
                delete_option('alfreds_toolbox_license_key');
                return;
            }
    
            // Versuche den neuen Key zu validieren
            $validation = $api_client->validate_license();
            
            if (!$validation['success']) {
                add_settings_error(
                    'alfreds_toolbox_license_key',
                    'invalid_license',
                    $validation['message'],
                    'error'
                );
                // Alten Key beibehalten wenn der neue ungültig ist
                update_option('alfreds_toolbox_license_key', $old_key);
            }
        }
    }

    public function render_lizenz_field() {
        $value = get_option('alfreds_toolbox_license_key');
        $validation = $this->validate_license_key($value);
        
        echo '<div class="at-license-field">';
        echo '<input type="text" 
            name="alfreds_toolbox_license_key" 
            value="' . esc_attr($value) . '" 
            class="regular-text at-input" 
            placeholder="XXXX-XXXX-XXXX-XXXX">';
        
        if ($value) {
            if ($validation['success']) {
                echo '<span class="at-license-status success">
                    <span class="dashicons dashicons-yes-alt"></span> Lizenz aktiv
                </span>';
            } else {
                echo '<span class="at-license-status error">
                    <span class="dashicons dashicons-warning"></span> ' . esc_html($validation['message']) . '
                </span>';
            }
        }
        echo '</div>';
    }
    
    private function validate_license_key($key) {
        if (empty($key)) {
            return [
                'success' => false,
                'message' => 'Kein Lizenzschlüssel hinterlegt'
            ];
        }
    
        $api_client = new AlfredsToolboxAPI();
        return $api_client->validate_license();
    }

    public function render_client_id_field() {
        $value = get_option('spotify_client_id');
        echo '<input type="text" name="spotify_client_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }
    
    public function render_client_secret_field() {
        $value = get_option('spotify_client_secret');
        echo '<input type="text" name="spotify_client_secret" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }

    public function render_support_id_field() {
        $value = get_option('support_id');
        echo '<input type="text" name="support_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }
    
    private function get_support_package_options() {
        return [
            1 => 'Kein Support',
            2 => 'Basis Paket',
            3 => 'Performance Paket'
        ];
    }
    
    public function render_support_package_field() {
        $value = get_option('support_package');
        $options = $this->get_support_package_options();
        
        echo '<select name="support_package" class="at-select" autocomplete="off">';
        foreach ($options as $id => $name) {
            echo '<option value="' . esc_attr($id) . '" ' . 
                 selected($value, $id, false) . '>' . 
                 esc_html($name) . '</option>';
        }
        echo '</select>';
    }

    public function render_intro_video_field() {
        $value = get_option('intro_video');
        echo '<input type="text" name="intro_video" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }

    public function render_cache_duration_field() {
        $duration = get_option('spotify_cache_duration', 3600);
        $options = [
            1800  => '30 Minuten',
            3600  => '1 Stunde',
            7200  => '2 Stunden',
            14400 => '4 Stunden',
            28800 => '8 Stunden',
            43200 => '12 Stunden',
            86400 => '24 Stunden'
        ];
        
        echo '<select name="spotify_cache_duration" class="at-select">';
        foreach ($options as $seconds => $label) {
            echo '<option value="' . esc_attr($seconds) . '" ' . 
                 selected($duration, $seconds, false) . '>' . 
                 esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_cache_control_field() {
        $nonce = wp_create_nonce('clear_spotify_cache');
        echo '<button type="button" class="button at-button is-secondary" id="clear_spotify_cache" 
                data-nonce="' . esc_attr($nonce) . '">Cache leeren</button>';
    }

    public function render_dashboard_toggle() {
        $value = get_option('replace_dashboard', false);
        ?>
        <label class="at-switch">
            <input type="checkbox" name="replace_dashboard" value="1" <?php checked($value); ?>>
            <span class="at-switch-slider"></span>
        </label>
        <?php
    }

    public function handle_clear_cache() {
        check_ajax_referer('clear_spotify_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $spotify_api = new SpotifyAPI(
            get_option('spotify_client_id'),
            get_option('spotify_client_secret')
        );
        
        $spotify_api->clear_cache();
        wp_send_json_success();
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_alfreds-toolbox' && $hook !== 'toplevel_page_alfreds-toolbox') {
            return;
        }
    
        wp_enqueue_style(
            'font-awesome-5',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
            [],
            '5.15.4'
        );
    
        wp_enqueue_style(
            'alfreds-toolbox-admin-styles', 
            plugins_url('assets/css/admin-styles.css', dirname(__FILE__)),
            [], 
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/admin-styles.css')
        );
    
        wp_enqueue_script(
            'alfreds-toolbox-admin-scripts', 
            plugins_url('assets/js/admin-scripts.js', dirname(__FILE__)),
            ['jquery'],
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/admin-scripts.js'),
            true
        );
    
        wp_localize_script('alfreds-toolbox-admin-scripts', 'alfreds_toolbox', [
            'nonce' => wp_create_nonce('alfreds_toolbox_settings')
        ]);

        wp_localize_script('alfreds-toolbox-admin-scripts', 'alfreds_toolbox', [
            'nonce' => wp_create_nonce('alfreds_toolbox_settings'),
            'plugin_url' => plugins_url('', dirname(__FILE__))
        ]);
    }
}