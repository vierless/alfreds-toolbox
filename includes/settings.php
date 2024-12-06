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
}