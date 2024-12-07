<?php
class AlfredsToolboxSettings {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings() {
        register_setting('alfreds_toolbox_settings', 'alfreds_toolbox_spotify_client_id');
        register_setting('alfreds_toolbox_settings', 'alfreds_toolbox_spotify_client_secret');

        register_setting(
            'alfreds_toolbox_settings', 
            'alfreds_toolbox_spotify_cache_duration',
            [
                'default' => 3600,
                'sanitize_callback' => 'absint'
            ]
        );
    }

    public function add_settings_section() {
        add_settings_section(
            'alfreds_toolbox_spotify',
            'Spotify API Einstellungen',
            [$this, 'render_spotify_section'],
            'alfreds-toolbox'
        );
    
        add_settings_field(
            'spotify_client_id',
            'Client ID',
            [$this, 'render_client_id_field'],
            'alfreds-toolbox',
            'alfreds_toolbox_spotify'
        );
    
        add_settings_field(
            'spotify_client_secret',
            'Client Secret',
            [$this, 'render_client_secret_field'],
            'alfreds-toolbox',
            'alfreds_toolbox_spotify'
        );
    
        add_settings_field(
            'spotify_cache_duration',
            'Cache Dauer',
            [$this, 'render_cache_duration_field'],
            'alfreds-toolbox',
            'alfreds_toolbox_spotify'
        );
    
        add_settings_field(
            'spotify_cache_control',
            'Cache Control',
            [$this, 'render_cache_control_field'],
            'alfreds-toolbox',
            'alfreds_toolbox_spotify'
        );
    }

    public function render_spotify_section() {
        echo '<p>Spotify API Zugangsdaten für das Podcast Widget</p>';
    }

    public function render_client_id_field() {
        $value = get_option('alfreds_toolbox_spotify_client_id');
        echo '<input type="text" name="alfreds_toolbox_spotify_client_id" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_client_secret_field() {
        $value = get_option('alfreds_toolbox_spotify_client_secret');
        echo '<input type="password" name="alfreds_toolbox_spotify_client_secret" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_cache_control_field() {
        $nonce = wp_create_nonce('clear_spotify_cache');
        echo '<button type="button" class="button" id="clear_spotify_cache" 
                data-nonce="' . esc_attr($nonce) . '">Cache leeren</button>';
        echo '<p class="description">Löscht den zwischengespeicherten Podcast-Content</p>';
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#clear_spotify_cache').click(function() {
                var button = $(this);
                button.prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'clear_spotify_cache',
                    nonce: button.data('nonce')
                }, function(response) {
                    if (response.success) {
                        button.after('<span class="success" style="color:green;margin-left:10px">Cache wurde geleert</span>');
                    } else {
                        button.after('<span class="error" style="color:red;margin-left:10px">Fehler beim Leeren des Caches</span>');
                    }
                    button.prop('disabled', false);
                    setTimeout(function() {
                        button.siblings('.success, .error').fadeOut().remove();
                    }, 3000);
                });
            });
        });
        </script>
        <?php
    }

    public function render_cache_duration_field() {
        $duration = get_option('alfreds_toolbox_spotify_cache_duration', 3600);
        $options = [
            1800  => '30 Minuten',
            3600  => '1 Stunde',
            7200  => '2 Stunden',
            14400 => '4 Stunden',
            28800 => '8 Stunden',
            43200 => '12 Stunden',
            86400 => '24 Stunden'
        ];
        
        echo '<select name="alfreds_toolbox_spotify_cache_duration">';
        foreach ($options as $seconds => $label) {
            echo '<option value="' . esc_attr($seconds) . '" ' . 
                 selected($duration, $seconds, false) . '>' . 
                 esc_html($label) . '</option>';
        }
        echo '</select>';
        
        echo '<p class="description">Wie lange sollen die Podcast-Daten zwischengespeichert werden?</p>';
    }

    public function handle_clear_cache() {
        check_ajax_referer('clear_spotify_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $spotify_api = new SpotifyAPI(
            get_option('alfreds_toolbox_spotify_client_id'),
            get_option('alfreds_toolbox_spotify_client_secret')
        );
        
        $spotify_api->clear_cache();
        wp_send_json_success();
    }
}