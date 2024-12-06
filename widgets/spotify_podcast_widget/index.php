<?php
class SpotifyPodcastWidget extends \Elementor\Widget_Base {
    private $spotify_api;

    public function __construct($data = [], $args = null) {
        parent::__construct($data, $args);
        
        $client_id = get_option('alfreds_toolbox_spotify_client_id');
        $client_secret = get_option('alfreds_toolbox_spotify_client_secret');
        
        if ($client_id && $client_secret) {
            require_once plugin_dir_path(dirname(dirname(__FILE__))) . 'includes/spotify-api.php';
            $this->spotify_api = new SpotifyAPI($client_id, $client_secret);
        }

        add_action('elementor/frontend/after_register_scripts', [$this, 'register_widget_scripts']);
        add_action('elementor/frontend/after_register_styles', [$this, 'register_widget_styles']);
        add_action('wp_ajax_load_more_episodes', [$this, 'handle_load_more_episodes']);
        add_action('wp_ajax_nopriv_load_more_episodes', [$this, 'handle_load_more_episodes']);
    }

    public function register_widget_scripts() {
        wp_register_script(
            'at-spotify-podcast',
            plugins_url('script.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );
    }

    public function register_widget_styles() {
        wp_register_style(
            'at-spotify-podcast',
            plugins_url('style.css', __FILE__)
        );
    }

    public function get_script_depends() {
        return ['at-spotify-podcast'];
    }

    public function get_style_depends() {
        return ['at-spotify-podcast'];
    }

    public function handle_load_more_episodes() {
        check_ajax_referer('spotify_load_more', 'nonce');
        $show_id = $_POST['show_id'];
        $offset = intval($_POST['offset']);
        
        if ($this->spotify_api) {
            $episodes = $this->spotify_api->get_show_episodes($show_id, 10, $offset);
            wp_send_json_success([
                'episodes' => $episodes,
                'settings' => $this->get_settings()
            ]);
        }
        wp_send_json_error();
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

        $this->end_controls_section();

        // Style Settings
        $this->start_controls_section(
            'style_section',
            [
                'label' => 'Style',
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'title_align',
            [
                'label' => 'Titel Ausrichtung',
                'type' => \Elementor\Controls_Manager::CHOOSE,
                'options' => [
                    'left' => [
                        'title' => 'Links',
                        'icon' => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => 'Mitte',
                        'icon' => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => 'Rechts',
                        'icon' => 'eicon-text-align-right',
                    ],
                ],
                'default' => 'left',
                'selectors' => [
                    '{{WRAPPER}} .at_episode-title' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
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
            echo '<button class="at_load-more-episodes" 
                data-show-id="' . esc_attr($show_id) . '" 
                data-offset="' . esc_attr($settings['episodes_count']) . '">
                Weitere Episoden laden
            </button>';
            
            $this->enqueue_load_more_script();
        }
    }

    private function get_spotify_episodes($show_id, $limit) {
        if (!$this->spotify_api) {
            error_log('Spotify API not initialized');
            return [];
        }
        $episodes = $this->spotify_api->get_show_episodes($show_id, $limit);
        error_log('Episodes response: ' . print_r($episodes, true));
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
            $title = ($settings['link_type'] === 'title') ? 
                '<a href="' . esc_url($episode->external_urls->spotify) . '" target="_blank">' . esc_html($episode->name) . '</a>' :
                esc_html($episode->name);
            $html .= '<h3 class="at_episode-title">' . $title . '</h3>';
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

    private function enqueue_load_more_script() {
        wp_enqueue_script(
            'at-load-more',
            plugins_url('js/load-more.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('at-load-more', 'atSpotify', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('at_load_more_nonce')
        ]);
    }
}