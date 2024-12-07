<?php
class SpotifyPodcastWidget extends \Elementor\Widget_Base {
    private $spotify_api;
    private static $widget_id = 'spotify_podcast_widget';

    public function __construct($data = [], $args = null) {
        parent::__construct($data, $args);
        
        $client_id = get_option('alfreds_toolbox_spotify_client_id');
        $client_secret = get_option('alfreds_toolbox_spotify_client_secret');
        
        if ($client_id && $client_secret) {
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'includes/spotify-api.php';
            $this->spotify_api = new SpotifyAPI($client_id, $client_secret);
        }

        // Optimierte Asset-Ladung
        add_action('elementor/frontend/after_register_scripts', [$this, 'register_widget_assets']);
        add_action('elementor/frontend/before_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        
        add_action('wp_ajax_load_more_episodes', [$this, 'handle_load_more_episodes']);
        add_action('wp_ajax_nopriv_load_more_episodes', [$this, 'handle_load_more_episodes']);
    }

    public function register_widget_assets() {
        $plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));
        
        wp_register_script(
            'at-spotify-podcast',
            $plugin_url . 'widgets/' . self::$widget_id . '/script.js',
            ['jquery'],
            filemtime(plugin_dir_path(dirname(dirname(__FILE__))) . 'widgets/' . self::$widget_id . '/script.js'),
            true
        );
    
        wp_localize_script('at-spotify-podcast', 'spotify_widget_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spotify_load_more')
        ]);
    
        wp_register_style(
            'at-spotify-podcast',
            $plugin_url . 'widgets/' . self::$widget_id . '/style.css',
            [],
            filemtime(plugin_dir_path(dirname(dirname(__FILE__))) . 'widgets/' . self::$widget_id . '/style.css')
        );
    }

    public function maybe_enqueue_assets() {
        // Lade Assets nur wenn wir im Preview Mode sind oder das Widget auf der Seite verwendet wird
        if (Elementor\Plugin::$instance->preview->is_preview_mode() || $this->is_widget_used_on_page()) {
            $this->debug_log('Enqueuing Spotify widget assets - Widget is used on page');
            wp_enqueue_script('at-spotify-podcast');
            wp_enqueue_style('at-spotify-podcast');
        }
    }

    private function is_widget_used_on_page() {
        // Hole das aktuelle Dokument
        $document = Elementor\Plugin::$instance->documents->get(get_the_ID());
        if (!$document) return false;

        // Hole die Elementor-Daten der Seite
        $data = $document->get_elements_data();
        if (empty($data)) return false;

        // Prüfe rekursiv ob unser Widget verwendet wird
        return $this->is_widget_in_elements($data);
    }

    private function is_widget_in_elements($elements) {
        foreach ($elements as $element) {
            if (!empty($element['widgetType']) && $element['widgetType'] === $this->get_name()) {
                return true;
            }
            if (!empty($element['elements'])) {
                if ($this->is_widget_in_elements($element['elements'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Spotify Widget] ' . $message);
        }
    }

    public function handle_load_more_episodes() {
        try {
            check_ajax_referer('spotify_load_more', 'nonce');
            
            if (!isset($_POST['show_id']) || !isset($_POST['offset']) || !isset($_POST['count'])) {
                wp_send_json_error(['message' => 'Missing parameters']);
                die();
            }
    
            $show_id = sanitize_text_field($_POST['show_id']);
            $offset = intval($_POST['offset']);
            $count = intval($_POST['count']);
            
            if (!$this->spotify_api) {
                wp_send_json_error(['message' => 'API not initialized']);
                die();
            }
    
            $episodes = $this->spotify_api->get_show_episodes($show_id, $count, $offset);
            
            if (!$episodes) {
                wp_send_json_error(['message' => 'No episodes found']);
                die();
            }
    
            wp_send_json_success([
                'episodes' => $episodes
            ]);
            die();
    
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
            die();
        }
    }

    public function get_name() {
        return 'spotify_podcast';
    }

    public function get_title() {
        return 'Spotify Podcast';
    }

    public function get_description() {
        return 'Zeigt Episoden eines Spotify Podcasts mit Verlinkung an';
    }

    public function get_icon() {
        return 'fab fa-spotify';
    }

    public function get_categories() {
        return ['alfreds-toolbox'];
    }

    protected function register_controls() {
        // Content Settings
        $this->start_controls_section(
            'content_section',
            [
                'label' => 'Inhaltseinstellungen',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'spotify_show_id',
            [
                'label' => 'Spotify Show ID',
                'type' => \Elementor\Controls_Manager::TEXT,
                'placeholder' => 'z.B. 6UX0Vj0kOHZE8DOpKF9RWI',
            ]
        );

        $this->add_control(
            'episodes_count',
            [
                'label' => 'Anzahl Episoden',
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 50,
                'default' => 10,
            ]
        );

        $this->add_control(
            'pagination',
            [
                'label' => 'Pagination',
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'none' => 'Keine',
                    'load_more' => 'Load More',
                ],
                'default' => 'none',
            ]
        );

        $this->end_controls_section();

        // Display Settings
        $this->start_controls_section(
            'display_section',
            [
                'label' => 'Anzeigeeinstellungen',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'show_cover',
            [
                'label' => 'Cover anzeigen',
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_title',
            [
                'label' => 'Titel anzeigen',
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_description',
            [
                'label' => 'Beschreibung anzeigen',
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'show_duration',
            [
                'label' => 'Länge anzeigen',
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'layout',
            [
                'label' => 'Layout',
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'cover_top' => 'Cover oben',
                    'cover_left' => 'Cover links',
                    'cover_right' => 'Cover rechts',
                ],
                'default' => 'cover_left',
            ]
        );

        $this->add_control(
            'link_type',
            [
                'label' => 'Verlinkung',
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'none' => 'Keine',
                    'cover' => 'Nur Cover',
                    'title' => 'Nur Titel',
                    'box' => 'Gesamte Box',
                    'custom' => 'Custom URL',
                ],
                'default' => 'title',
            ]
        );

        $this->add_control(
            'custom_url',
            [
                'label' => 'Custom URL',
                'type' => \Elementor\Controls_Manager::TEXT,
                'condition' => [
                    'link_type' => 'custom',
                ],
            ]
        );

        $this->add_control(
            'load_more_count',
            [
                'label' => 'Anzahl bei "Mehr laden"',
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 50,
                'default' => 10,
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->add_control(
            'load_more_text',
            [
                'label' => 'Button Text',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'Weitere Episoden laden',
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->add_control(
            'load_more_loading_text',
            [
                'label' => 'Button Text während des Ladens',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'Lade weitere Episoden...',
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->end_controls_section();

        // Style Settings
        // Cover Styles
        $this->start_controls_section(
            'cover_style_section',
            [
                'label' => 'Cover',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'cover_size',
            [
                'label' => 'Cover Größe',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'range' => [
                    'px' => ['min' => 50, 'max' => 500, 'step' => 1],
                    '%' => ['min' => 1, 'max' => 100, 'step' => 1],
                    'em' => ['min' => 1, 'max' => 50, 'step' => 0.1],
                    'rem' => ['min' => 1, 'max' => 50, 'step' => 0.1],
                    'vw' => ['min' => 1, 'max' => 100, 'step' => 1],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_layout-cover_left' => 'grid-template-columns: {{SIZE}}{{UNIT}} 1fr;',
                    '{{WRAPPER}} .at_layout-cover_right' => 'grid-template-columns: 1fr {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'cover_border_radius',
            [
                'label' => 'Eckenradius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // Title Styles
        $this->start_controls_section(
            'title_style_section',
            [
                'label' => 'Titel',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'title_tag',
            [
                'label' => 'HTML Tag',
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'h3',
                'options' => [
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'h4' => 'H4',
                    'h5' => 'H5',
                    'h6' => 'H6',
                    'div' => 'div',
                    'span' => 'span',
                    'p' => 'p'
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'title_typography',
                'selector' => '{{WRAPPER}} .at_episode-title',
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label' => 'Farbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'title_spacing',
            [
                'label' => 'Abstand unten',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'em', 'rem'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 100],
                    'em' => ['min' => 0, 'max' => 10],
                    'rem' => ['min' => 0, 'max' => 10],
                    '%' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // Description Styles
        $this->start_controls_section(
            'description_style_section',
            [
                'label' => 'Beschreibung',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_description' => 'yes',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'description_typography',
                'selector' => '{{WRAPPER}} .at_episode-description',
            ]
        );

        $this->add_control(
            'description_color',
            [
                'label' => 'Farbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-description' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        // Meta (Duration) Styles
        $this->start_controls_section(
            'meta_style_section',
            [
                'label' => 'Meta',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_duration' => 'yes',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'meta_typography',
                'selector' => '{{WRAPPER}} .at_episode-duration',
            ]
        );

        $this->add_control(
            'meta_color',
            [
                'label' => 'Farbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-duration' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();

        // Box Styles
        $this->start_controls_section(
            'box_style_section',
            [
                'label' => 'Box',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'box_border',
                'selector' => '{{WRAPPER}} .at_episode',
            ]
        );

        $this->add_responsive_control(
            'box_padding',
            [
                'label' => 'Innenabstand',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-content' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'box_shadow',
                'selector' => '{{WRAPPER}} .at_episode',
            ]
        );

        $this->end_controls_section();

        // Load More Button Styles
        $this->start_controls_section(
            'button_style_section',
            [
                'label' => 'Load More Button',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->start_controls_tabs('button_styles');

        $this->start_controls_tab(
            'button_normal',
            ['label' => 'Normal']
        );

        $this->add_control(
            'button_background',
            [
                'label' => 'Hintergrundfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'default' => '#1DB954',
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_text_color',
            [
                'label' => 'Textfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'button_hover',
            ['label' => 'Hover']
        );

        $this->add_control(
            'button_background_hover',
            [
                'label' => 'Hintergrundfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes:hover' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function render() {
        $this->debug_log('Spotify Widget Render Start');
        
        // Prüfe ob die Dateien existieren
        $script_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'widgets/' . self::$widget_id . '/script.js';
        $style_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'widgets/' . self::$widget_id . '/style.css';
        
        $this->debug_log('Script exists: ' . (file_exists($script_path) ? 'yes' : 'no'));
        $this->debug_log('Style exists: ' . (file_exists($style_path) ? 'yes' : 'no'));

        $settings = $this->get_settings_for_display();
        $show_id = $settings['spotify_show_id'];
        
        if (empty($show_id)) {
            echo 'Bitte Spotify Show ID eingeben';
            return;
        }

        $episodes = $this->get_spotify_episodes($show_id, $settings['episodes_count']);
        
        if (empty($episodes)) {
            echo 'Keine Episoden gefunden';
            return;
        }

        echo '<div class="at_spotify-podcast-grid">';
        foreach ($episodes as $episode) {
            $this->render_episode($episode, $settings);
        }
        echo '</div>';

        if ($settings['pagination'] === 'load_more') {
            $render_settings = [
                'show_cover' => $settings['show_cover'],
                'show_title' => $settings['show_title'],
                'show_description' => $settings['show_description'],
                'show_duration' => $settings['show_duration'],
                'layout' => $settings['layout'],
                'link_type' => $settings['link_type'],
                'load_more_text' => $settings['load_more_text'],
                'load_more_loading_text' => $settings['load_more_loading_text']
            ];
        
            $encoded_settings = base64_encode(wp_json_encode($render_settings));
        
            echo '<button class="at_load-more-episodes" 
                data-show-id="' . esc_attr($show_id) . '" 
                data-offset="' . esc_attr($settings['episodes_count']) . '"
                data-load-count="' . esc_attr($settings['load_more_count']) . '"
                data-settings="' . $encoded_settings . '">
                ' . esc_html($settings['load_more_text']) . '
            </button>';
        }
    }

    private function get_spotify_episodes($show_id, $limit) {
        if (!$this->spotify_api) {
            $this->debug_log('Spotify API not initialized');
            return [];
        }
        $episodes = $this->spotify_api->get_show_episodes($show_id, $limit);
        $this->debug_log('Episodes response: ' . print_r($episodes, true));
        return $episodes;
    }

    private function render_episode($episode, $settings) {
        $html = '<div class="at_episode ' . esc_attr('at_layout-' . $settings['layout']) . '">';
        
        if ($settings['link_type'] === 'box') {
            $html = '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">' . $html;
        }

        // Cover
        if ($settings['show_cover'] === 'yes' && !empty($episode->images[0]->url)) {
            if ($settings['link_type'] === 'cover') {
                $html .= '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">';
            }
            $html .= '<div class="at_episode-cover">';
            $html .= '<img src="' . esc_url($episode->images[0]->url) . '" alt="' . esc_attr($episode->name) . '">';
            $html .= '</div>';
            if ($settings['link_type'] === 'cover') {
                $html .= '</a>';
            }
        }

        $html .= '<div class="at_episode-content">';
        
        // Title
        if ($settings['show_title'] === 'yes') {
            $tag = $settings['title_tag'] ?? 'h3';
            $title = ($settings['link_type'] === 'title') ? 
                '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">' . esc_html($episode->name) . '</a>' :
                esc_html($episode->name);
            $html .= '<' . $tag . ' class="at_episode-title">' . $title . '</' . $tag . '>';
        }

        // Description
        if ($settings['show_description'] === 'yes') {
            $html .= '<div class="at_episode-description">' . esc_html($episode->description) . '</div>';
        }

        // Duration
        if ($settings['show_duration'] === 'yes' && !empty($episode->duration_ms)) {
            $duration = round($episode->duration_ms / 60000); // Convert to minutes
            $html .= '<div class="at_episode-duration">' . $duration . ' Minuten</div>';
        }

        $html .= '</div>';

        if ($settings['link_type'] === 'box') {
            $html .= '</a>';
        }

        $html .= '</div>';
        echo $html;
    }
}