<?php
class SpotifyPodcastWidget extends \Elementor\Widget_Base {
    private $spotify_api;
    private static $widget_id = 'spotify_podcast_widget';

    public function __construct($data = [], $args = null) {
        parent::__construct($data, $args);
        
        $client_id = get_option('alfreds_toolbox_spotify_client_id');
        $client_secret = get_option('alfreds_toolbox_spotify_client_secret');
        
        if ($client_id && $client_secret) {
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'includes/api/spotify-api.php';
            $this->spotify_api = new SpotifyAPI($client_id, $client_secret);
        }

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
        if (Elementor\Plugin::$instance->preview->is_preview_mode() || $this->is_widget_used_on_page()) {
            $this->debug_log('Enqueuing Spotify widget assets - Widget is used on page');
            wp_enqueue_script('at-spotify-podcast');
            wp_enqueue_style('at-spotify-podcast');
        }
    }

    private function is_widget_used_on_page() {
        $document = Elementor\Plugin::$instance->documents->get(get_the_ID());
        if (!$document) return false;

        // Hole die Elementor-Daten der Seite
        $data = $document->get_elements_data();
        if (empty($data)) return false;

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
        /*
        * CONTENT TAB
        */

        // SECTION: Einstellungen
        $this->start_controls_section(
            'base_settings_section',
            [
                'label' => 'Einstellungen',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        // Spotify Show ID
        $this->add_control(
            'spotify_show_id',
            [
                'label' => 'Spotify Show ID',
                'type' => \Elementor\Controls_Manager::TEXT,
                'placeholder' => 'z.B. 6UX0Vj0kOHZE8DOpKF9RWI',
            ]
        );

        // Anzahl Episoden
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

        // Pagination
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

        // SECTION: Anzeige
        $this->start_controls_section(
            'display_section',
            [
                'label' => 'Anzeige',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        // Layout
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

        // Sichtbare Elemente
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
                'label' => 'Dauer anzeigen',
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        // Beschreibungs-Begrenzung
        $this->add_control(
            'description_limit',
            [
                'label' => 'Beschreibung begrenzen',
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'none' => 'Keine Begrenzung',
                    'characters' => 'Zeichen',
                    'words' => 'Wörter'
                ],
                'default' => 'none',
                'condition' => [
                    'show_description' => 'yes'
                ]
            ]
        );
        
        $this->add_control(
            'description_limit_count',
            [
                'label' => 'Anzahl Zeichen/Wörter',
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 1000,
                'default' => 100,
                'condition' => [
                    'show_description' => 'yes',
                    'description_limit!' => 'none'
                ]
            ]
        );

        $this->end_controls_section();

        // SECTION: Link
        $this->start_controls_section(
            'link_section',
            [
                'label' => 'Link',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
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
                'type' => \Elementor\Controls_Manager::URL,
                'placeholder' => 'https://your-link.com',
                'options' => ['url', 'is_external', 'nofollow'],
                'default' => [
                    'url' => '',
                    'is_external' => true,
                    'nofollow' => true,
                ],
                'condition' => [
                    'link_type' => 'custom',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Pagination
        $this->start_controls_section(
            'pagination_section',
            [
                'label' => 'Pagination',
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->add_control(
            'load_more_count',
            [
                'label' => 'Anzahl bei "Mehr laden"',
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 25,
                'default' => 10,
            ]
        );

        $this->add_control(
            'load_more_text',
            [
                'label' => 'Button Text (Default)',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'Weitere Episoden laden',
            ]
        );

        $this->add_control(
            'load_more_loading_text',
            [
                'label' => 'Button Text (Loading)',
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => 'Lade weitere Episoden...',
            ]
        );

        $this->end_controls_section();

        /*
        * STYLE TAB
        */

        // SECTION: Episode Box Styles (gesamte Box)
        $this->start_controls_section(
            'episode_box_style_section',
            [
                'label' => 'Episode Box',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'episode_box_padding',
            [
                'label' => 'Padding',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Background::get_type(),
            [
                'name' => 'episode_box_background',
                'types' => ['classic', 'gradient'],
                'selector' => '{{WRAPPER}} .at_episode',
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'episode_box_border',
                'selector' => '{{WRAPPER}} .at_episode',
            ]
        );

        $this->add_responsive_control(
            'episode_box_border_radius',
            [
                'label' => 'Border Radius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Box_Shadow::get_type(),
            [
                'name' => 'episode_box_shadow',
                'selector' => '{{WRAPPER}} .at_episode',
            ]
        );

        // Spacing
        $this->add_responsive_control(
            'episode_box_spacing',
            [
                'label' => 'Abstand',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem', '%'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 100],
                    'em' => ['min' => 0, 'max' => 10],
                    'rem' => ['min' => 0, 'max' => 10],
                    '%' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Content Box Styles
        $this->start_controls_section(
            'content_box_style_section',
            [
                'label' => 'Content Box',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'content_box_padding',
            [
                'label' => 'Padding',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-content' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Background::get_type(),
            [
                'name' => 'content_box_background',
                'types' => ['classic', 'gradient'],
                'selector' => '{{WRAPPER}} .at_episode-content',
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'content_box_border',
                'selector' => '{{WRAPPER}} .at_episode-content',
            ]
        );

        $this->add_responsive_control(
            'content_box_border_radius',
            [
                'label' => 'Border Radius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-content' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Cover Styles
        $this->start_controls_section(
            'cover_style_section',
            [
                'label' => 'Cover',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_cover' => 'yes',
                ],
            ]
        );

        // Image Size
        $this->add_responsive_control(
            'cover_width',
            [
                'label' => 'Breite',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 1000],
                    '%' => ['min' => 0, 'max' => 100],
                    'vw' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_layout-cover_left' => 'grid-template-columns: {{SIZE}}{{UNIT}} 1fr;',
                    '{{WRAPPER}} .at_layout-cover_right' => 'grid-template-columns: 1fr {{SIZE}}{{UNIT}};',
                    '{{WRAPPER}} .at_episode-cover img' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'cover_max_width',
            [
                'label' => 'Maximale Breite',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 1000],
                    '%' => ['min' => 0, 'max' => 100],
                    'vw' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'max-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'cover_height',
            [
                'label' => 'Höhe',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'em', 'rem', 'vh', 'custom'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 1000],
                    '%' => ['min' => 0, 'max' => 100],
                    'vh' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        // Object Fit
        $this->add_control(
            'cover_object_fit',
            [
                'label' => 'Object Fit',
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'cover',
                'options' => [
                    'cover' => 'Cover',
                    'contain' => 'Contain',
                    'fill' => 'Fill',
                    'none' => 'None',
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'object-fit: {{VALUE}};',
                ],
                'condition' => [
                    'cover_height[size]!' => '',
                ],
            ]
        );

        $this->add_control(
            'cover_object_position',
            [
                'label' => 'Object Position',
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'center center',
                'options' => [
                    'center center' => 'Center Center',
                    'center left' => 'Center Left',
                    'center right' => 'Center Right',
                    'top center' => 'Top Center',
                    'top left' => 'Top Left',
                    'top right' => 'Top Right',
                    'bottom center' => 'Bottom Center',
                    'bottom left' => 'Bottom Left',
                    'bottom right' => 'Bottom Right',
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'object-position: {{VALUE}};',
                ],
                'condition' => [
                    'cover_height[size]!' => '',
                ],
            ]
        );

        // Border
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'cover_border',
                'selector' => '{{WRAPPER}} .at_episode-cover img',
            ]
        );

        $this->add_responsive_control(
            'cover_border_radius',
            [
                'label' => 'Border Radius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-cover img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Title Styles
        $this->start_controls_section(
            'title_style_section',
            [
                'label' => 'Titel',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_title' => 'yes',
                ],
            ]
        );

        // HTML Tag
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

        // Typography
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'title_typography',
                'selector' => '{{WRAPPER}} .at_episode-title, {{WRAPPER}} .at_episode-title a',
            ]
        );

        // Colors
        $this->add_control(
            'title_color',
            [
                'label' => 'Farbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-title' => 'color: {{VALUE}};',
                    '{{WRAPPER}} .at_episode-title a' => 'color: {{VALUE}};',
                ],
            ]
        );

        // Spacing
        $this->add_responsive_control(
            'title_spacing',
            [
                'label' => 'Abstand unten',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem', '%'],
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

        // SECTION: Description Styles
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

        // Typography
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'description_typography',
                'selector' => '{{WRAPPER}} .at_episode-description',
            ]
        );

        // Text Color
        $this->add_control(
            'description_color',
            [
                'label' => 'Textfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-description' => 'color: {{VALUE}};',
                ],
            ]
        );

        // Text Align
        $this->add_responsive_control(
            'description_text_align',
            [
                'label' => 'Ausrichtung',
                'type' => \Elementor\Controls_Manager::CHOOSE,
                'options' => [
                    'left' => [
                        'title' => 'Links',
                        'icon' => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => 'Zentriert',
                        'icon' => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => 'Rechts',
                        'icon' => 'eicon-text-align-right',
                    ],
                    'justify' => [
                        'title' => 'Blocksatz',
                        'icon' => 'eicon-text-align-justify',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-description' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        // Spacing
        $this->add_responsive_control(
            'description_spacing',
            [
                'label' => 'Abstand unten',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem', '%'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 100],
                    'em' => ['min' => 0, 'max' => 10],
                    'rem' => ['min' => 0, 'max' => 10],
                    '%' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Meta Styles
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

        // Typography
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'meta_typography',
                'selector' => '{{WRAPPER}} .at_episode-duration',
            ]
        );

        // Colors
        $this->add_control(
            'meta_color',
            [
                'label' => 'Textfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-duration' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'meta_background_color',
            [
                'label' => 'Hintergrundfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_episode-duration' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        // Border
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'meta_border',
                'selector' => '{{WRAPPER}} .at_episode-duration',
                'separator' => 'before',
            ]
        );

        // Border Radius
        $this->add_responsive_control(
            'meta_border_radius',
            [
                'label' => 'Border Radius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-duration' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Padding
        $this->add_responsive_control(
            'meta_padding',
            [
                'label' => 'Padding',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors' => [
                    '{{WRAPPER}} .at_episode-duration' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        // SECTION: Load More Styles
        $this->start_controls_section(
            'button_style_section',
            [
                'label' => 'Load More',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
                'condition' => [
                    'pagination' => 'load_more',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'button_typography',
                'selector' => '{{WRAPPER}} .at_load-more-episodes',
            ]
        );

        $this->start_controls_tabs('button_style_tabs');

        $this->start_controls_tab(
            'button_normal',
            ['label' => 'Normal']
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

        $this->add_control(
            'button_background_color',
            [
                'label' => 'Hintergrundfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'button_hover',
            ['label' => 'Hover']
        );

        $this->add_control(
            'button_hover_text_color',
            [
                'label' => 'Textfarbe',
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes:hover' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_hover_background_color',
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

        // Button Border
        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name' => 'button_border',
                'selector' => '{{WRAPPER}} .at_load-more-episodes',
                'separator' => 'before',
            ]
        );

        // Button Border Radius
        $this->add_responsive_control(
            'button_border_radius',
            [
                'label' => 'Border Radius',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem'],
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Button Padding
        $this->add_responsive_control(
            'button_padding',
            [
                'label' => 'Padding',
                'type' => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', 'rem', '%'],
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        // Spacing
        $this->add_responsive_control(
            'button_spacing',
            [
                'label' => 'Abstand oben',
                'type' => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem', '%'],
                'range' => [
                    'px' => ['min' => 0, 'max' => 100],
                    'em' => ['min' => 0, 'max' => 10],
                    'rem' => ['min' => 0, 'max' => 10],
                    '%' => ['min' => 0, 'max' => 100],
                ],
                'selectors' => [
                    '{{WRAPPER}} .at_load-more-episodes' => 'margin-top: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $this->debug_log('Spotify Widget Render Start');
    
        $settings = $this->get_settings_for_display();
        $show_id = $settings['spotify_show_id'];
        
        $this->debug_log('Settings: ' . print_r($settings, true));
        
        if (empty($show_id)) {
            echo 'Bitte Spotify Show ID eingeben';
            return;
        }

        $episodes = $this->get_spotify_episodes($show_id, $settings['episodes_count']);
        
        $this->debug_log('Episodes returned: ' . (is_array($episodes) ? count($episodes) : 'none'));
        
        if (empty($episodes)) {
            echo '<div class="at_spotify-error">Keine Episoden gefunden. Show ID: ' . esc_html($show_id) . '</div>';
            return;
        }

        echo '<div class="at_spotify-podcast-grid">';
        foreach ($episodes as $episode) {
            $this->debug_log('Rendering episode: ' . $episode->name);
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
                'title_tag' => $settings['title_tag'],
                'load_more_text' => $settings['load_more_text'],
                'load_more_loading_text' => $settings['load_more_loading_text'],
                'description_limit' => $settings['description_limit'],
                'description_limit_count' => $settings['description_limit_count']
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
        
        $this->debug_log('Requesting episodes for show: ' . $show_id . ' with limit: ' . $limit);
        
        $episodes = $this->spotify_api->get_show_episodes($show_id, $limit);
        
        if (empty($episodes)) {
            $this->debug_log('No episodes returned from API');
            return [];
        }
        
        $this->debug_log('Retrieved ' . count($episodes) . ' episodes');
        $this->debug_log('First episode data: ' . print_r($episodes[0], true));
        
        return $episodes;
    }

    private function render_episode($episode, $settings) {
        $this->debug_log('Rendering episode with data: ' . print_r($episode, true));
        
        if (!isset($episode->name) || !isset($episode->external_urls->spotify)) {
            $this->debug_log('Missing required episode fields');
            return;
        }
    
        if ($settings['link_type'] === 'box') {
            $html = '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank" class="at_episode ' . 
                    esc_attr('at_layout-' . $settings['layout']) . '">';
        } else {
            $html = '<div class="at_episode ' . esc_attr('at_layout-' . $settings['layout']) . '">';
        }
    
        if ($settings['show_cover'] === 'yes' && !empty($episode->images) && is_array($episode->images) && !empty($episode->images[0]->url)) {
            if ($settings['link_type'] === 'cover') {
                $html .= '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">';
            }
            $html .= '<div class="at_episode-cover">';
            $html .= '<img src="' . esc_url($episode->images[0]->url) . '" alt="' . esc_attr($episode->name) . '">';
            $html .= '</div>';
            if ($settings['link_type'] === 'cover') {
                $html .= '</a>';
            }
        } else {
            $this->debug_log('Cover image not available');
        }
    
        $html .= '<div class="at_episode-content">';
        
        if ($settings['show_title'] === 'yes') {
            $tag = $settings['title_tag'] ?? 'h3';
            $title = ($settings['link_type'] === 'title') ? 
                '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">' . esc_html($episode->name) . '</a>' :
                esc_html($episode->name);
            $html .= '<' . $tag . ' class="at_episode-title">' . $title . '</' . $tag . '>';
        }
    
        if ($settings['show_description'] === 'yes' && !empty($episode->description)) {
            $description = $episode->description;
            
            if ($settings['description_limit'] !== 'none' && !empty($settings['description_limit_count'])) {
                if ($settings['description_limit'] === 'characters') {
                    if (mb_strlen($description) > $settings['description_limit_count']) {
                        $description = mb_substr($description, 0, $settings['description_limit_count']) . '...';
                    }
                } else if ($settings['description_limit'] === 'words') {
                    $words = explode(' ', $description);
                    if (count($words) > $settings['description_limit_count']) {
                        $description = implode(' ', array_slice($words, 0, $settings['description_limit_count'])) . '...';
                    }
                }
            }
            
            $html .= '<p class="at_episode-description">' . $description . '</p>';
        }
    
        if ($settings['show_duration'] === 'yes' && !empty($episode->duration_ms)) {
            $duration = round($episode->duration_ms / 60000);
            $html .= '<div class="at_episode-duration">' . $duration . ' Minuten</div>';
        }
    
        $html .= '</div>';
    
        if ($settings['link_type'] === 'box') {
            $html .= '</a>';
        } else {
            $html .= '</div>';
        }
    
        echo $html;
    }
}