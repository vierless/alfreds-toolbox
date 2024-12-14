<?php
class SpotifyAPI {
    private $client_id;
    private $client_secret;
    private $access_token;
    private $token_expiry;

    public function __construct($client_id, $client_secret) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    private function get_cache_key($show_id, $limit, $offset) {
        return 'spotify_episodes_' . $show_id . '_' . $limit . '_' . $offset;
    }

    private function get_access_token() {
        // Bestehender Token noch gültig?
        if ($this->access_token && time() < $this->token_expiry) {
            return $this->access_token;
        }

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'grant_type=client_credentials'
        ];

        $response = wp_remote_post('https://accounts.spotify.com/api/token', $args);
        error_log('Token response: ' . print_r($response, true));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!empty($body->access_token)) {
            $this->access_token = $body->access_token;
            $this->token_expiry = time() + $body->expires_in;
            return $this->access_token;
        }

        return false;
    }

    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }

    public function get_show_episodes($show_id, $limit = 10, $offset = 0) {
        $cache_key = $this->get_cache_key($show_id, $limit, $offset);
    
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            $this->debug_log('Returning cached Spotify episodes data');
            return $cached_data;
        }

        $access_token = $this->get_access_token();
        if (!$access_token) return false;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ]
        ];

        $url = "https://api.spotify.com/v1/shows/{$show_id}/episodes?limit={$limit}&offset={$offset}&market=DE";
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('Spotify API Error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        
        if (isset($body->error)) {
            error_log('Spotify API Error: ' . print_r($body->error, true));
            return false;
        }

        $episodes = $body->items ?? [];

        if (!empty($episodes)) {
            $cache_duration = get_option('alfreds_toolbox_spotify_cache_duration', 3600);
            set_transient($cache_key, $episodes, $cache_duration);
            $this->debug_log('Cached Spotify episodes data for ' . $cache_duration . ' seconds');
        }
    
        return $episodes;
    }

    public function clear_cache($show_id = null) {
        global $wpdb;
        
        if ($show_id) {
            // Spezifischen Cache löschen
            $cache_key = $this->get_cache_key($show_id, 10, 0);
            delete_transient($cache_key);
        } else {
            // Alle Spotify Caches löschen
            $wpdb->query(
                "DELETE FROM $wpdb->options 
                WHERE option_name LIKE '_transient_spotify_episodes_%' 
                OR option_name LIKE '_transient_timeout_spotify_episodes_%'"
            );
        }
        
        error_log('Cleared Spotify cache' . ($show_id ? ' for show ' . $show_id : ''));
    }
}