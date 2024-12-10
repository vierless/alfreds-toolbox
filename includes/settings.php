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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_clear_spotify_cache', [$this, 'handle_clear_cache']);
        add_action('wp_ajax_save_alfreds_toolbox_settings', [$this, 'handle_save_settings']);
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
        
        error_log('Received settings data: ' . print_r($_POST['settings'], true));

        if (isset($settings['alfreds_toolbox_active_widgets'])) {
            $widget_string = stripslashes($settings['alfreds_toolbox_active_widgets']);
            $active_widgets = json_decode($widget_string, true);
            if (is_array($active_widgets)) {
                update_option('alfreds_toolbox_active_widgets', $active_widgets);
            }
        }
    
        $registered_settings = [
            'spotify_client_id',
            'spotify_client_secret',
            'spotify_cache_duration',
            'awork_project_id',
            'slack_channel_id',
            'developer',
            'projectmanager',
            'support_package',
            'intro_video',
            'replace_dashboard'
        ];
    
        error_log('Processing tutorial videos: ' . print_r($settings['tutorial_videos'] ?? 'no videos found', true));

        if (isset($settings['tutorial_videos'])) {
            $videos_data = json_decode(stripslashes($settings['tutorial_videos']), true);
            $videos = [];
            
            if (is_array($videos_data)) { 
                foreach ($videos_data as $video) {
                    if (!empty($video['title']) && !empty($video['loom_id'])) {
                        $videos[] = [
                            'title' => sanitize_text_field($video['title']),
                            'loom_id' => sanitize_text_field($video['loom_id'])
                        ];
                    }
                }
                $update_result = update_option('tutorial_videos', $videos);
                error_log('Tutorial videos saved: ' . print_r($videos, true));
                error_log('Update result: ' . ($update_result ? 'success' : 'failed'));
            } else {
                error_log('Failed to decode tutorial videos JSON: ' . $settings['tutorial_videos']);
            }
        }

        foreach ($settings as $key => $value) {
            if ($key !== 'alfreds_toolbox_active_widgets' && in_array($key, $registered_settings)) {
                $sanitized_value = sanitize_text_field($value);
                $update_result = update_option($key, $sanitized_value);
                error_log("Saving option $key with value: $sanitized_value (Update successful: " . ($update_result ? 'yes' : 'no') . ")");
            }
        }
    
        wp_send_json_success([
            'message' => 'Einstellungen gespeichert',
            'saved_settings' => array_map(function($key) {
                return get_option($key);
            }, array_keys($settings))
        ]);
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
        // Spotify Settings
        register_setting('alfreds_toolbox_settings', 'spotify_client_id');
        register_setting('alfreds_toolbox_settings', 'spotify_client_secret');
        register_setting('alfreds_toolbox_settings', 'spotify_cache_duration', [
            'type' => 'integer',
            'default' => 3600,
            'sanitize_callback' => 'absint'
        ]);
    
        // Projekt Settings
        register_setting('alfreds_toolbox_settings', 'awork_project_id');
        register_setting('alfreds_toolbox_settings', 'slack_channel_id');
        register_setting('alfreds_toolbox_settings', 'developer');
        register_setting('alfreds_toolbox_settings', 'projectmanager');
        register_setting('alfreds_toolbox_settings', 'support_package');
    
        // Dashboard Settings
        register_setting('alfreds_toolbox_settings', 'intro_video');
        register_setting('alfreds_toolbox_settings', 'replace_dashboard', ['type' => 'boolean', 'default' => false]);

        // Add tutorial videos setting
        register_setting('alfreds_toolbox_settings', 'tutorial_videos', [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitize_tutorial_videos']
        ]);

        // Widget Settings
        register_setting('alfreds_toolbox_settings', 'alfreds_toolbox_active_widgets', [
            'default' => []
        ]);
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
                    <div class="at-version">V.1.0.5</div>
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

                <form action="options.php" method="post" id="at-settings-form">
                    <?php settings_fields('alfreds_toolbox_settings'); ?>
    
                    <!-- Dashboard Tab -->
                    <div id="dashboard" class="at-tab-content active">
                        <div class="at-tab-inner">
                            <div class="at-main-col">
                                <div class="at-greeting">
                                    <div class="at-greeting-title">
                                        Hallo <?php 
                                        $current_user = wp_get_current_user();
                                        echo esc_html($current_user->display_name);
                                        ?>!
                                    </div>
                                    <div class="at-greeting-description">Willkommen zurück!</div>
                                </div>
                                <div class="at-section">
                                    <h2 class="at-section-title">VIERLESS Wartung</h2>
                                    <div class="at-section-row">
                                        <?php 
                                        $value = get_option('support_package');
                                        $options = $this->get_support_package_options();
                                        $status_class = $value == 1 ? 'is-error' : ($value ? 'is-success' : '');
                                        $icon = $value == 1 ? $this->get_icon_svg('cross', 'at-status-icon') : ($value ? $this->get_icon_svg('check', 'at-status-icon') : '');
                                        ?>

                                        <div class="at-section-description <?php echo $status_class; ?>">
                                            <?php if ($icon): ?>
                                                <?php echo $icon; ?>
                                            <?php endif; ?>
                                            <?php echo esc_html($options[$value] ?? 'Nicht festgelegt'); ?>
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                                            <img src="https://placehold.co/108x108" alt="Add-On Bild" />
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
                    </div>

                    <!-- Einstellungen Tab -->
                    <div id="einstellungen" class="at-tab-content">
                        <div class="at-section">
                            <h2 class="at-section-title">Projekt einrichten</h2>
                            <p class="at-section-description">Nutze unsere Integrationen mit Awork & Slack um den Support zu automatisieren.</p>
                            <fieldset class="at-form-fieldset">
                                <div class="at-form-group">
                                    <label class="at-label" for="awork_project_id">Awork Projekt ID</label>
                                    <?php $this->render_awork_project_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label" for="slack_channel_id">Slack Channel ID</label>
                                    <?php $this->render_slack_channel_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label" for="developer">Entwickler</label>
                                    <?php $this->render_developer_field(); ?>
                                </div>

                                <div class="at-form-group">
                                    <label class="at-label" for="projectmanager">Projekt Manager</label>
                                    <?php $this->render_project_manager_field(); ?>
                                </div>
                                
                                <div class="at-form-group">
                                    <label class="at-label" for="support">Support Paket</label>
                                    <?php $this->render_support_package_field(); ?>
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
                        <div class="at-submit-wrapper">
                            <?php submit_button('Änderungen speichern', 'at-button is-primary'); ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function get_spotify_status() {
        $client_id = get_option('spotify-client-id');
        $client_secret = get_option('spotify_client_secret');
        
        if (empty($client_id) || empty($client_secret)) {
            return '<span style="color: red;">⚠️ API-Zugangsdaten fehlen</span>';
        }
        return '<span style="color: green;">✓ Aktiv</span>';
    }

    public function render_client_id_field() {
        $value = get_option('spotify_client_id');
        echo '<input type="text" name="spotify_client_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }
    
    public function render_client_secret_field() {
        $value = get_option('spotify_client_secret');
        echo '<input type="password" name="spotify_client_secret" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }

    public function render_awork_project_field() {
        $value = get_option('awork_project_id');
        echo '<input type="text" name="awork_project_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }
    
    public function render_slack_channel_field() {
        $value = get_option('slack_channel_id');
        echo '<input type="text" name="slack_channel_id" value="' . esc_attr($value) . '" 
              class="regular-text at-input" autocomplete="off">';
    }

    public function render_developer_field() {
        $value = get_option('developer');
        $options = [
            'rec87KE3hSmFzgq20' => 'Julian Witzel',
            'recZTtGK5QLkTsHFe' => 'Nicole Logiewa'
        ];
        
        echo '<select name="developer" class="at-select" autocomplete="off">';
        foreach ($options as $id => $name) {
            echo '<option value="' . esc_attr($id) . '" ' . 
                 selected($value, $id, false) . '>' . 
                 esc_html($name) . '</option>';
        }
        echo '</select>';
    }
    
    public function render_project_manager_field() {
        $value = get_option('projectmanager');
        $options = [
            'recPFPXHW30lTbUyQ' => 'Leonardo Lemos Rilk',
            'recbI0ZSM1VKTjFvK' => 'Claus Wiedemann'
        ];
        
        echo '<select name="projectmanager" class="at-select" autocomplete="off">';
        foreach ($options as $id => $name) {
            echo '<option value="' . esc_attr($id) . '" ' . 
                 selected($value, $id, false) . '>' . 
                 esc_html($name) . '</option>';
        }
        echo '</select>';
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
    }
}