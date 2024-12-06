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

    private function get_access_token() {
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

    public function get_show_episodes($show_id, $limit = 10, $offset = 0) {
        $access_token = $this->get_access_token();
        error_log('Access token: ' . $access_token);
        if (!$access_token) return false;

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ]
        ];

        $response = wp_remote_get(
            "https://api.spotify.com/v1/shows/{$show_id}/episodes?limit={$limit}&offset={$offset}", 
            $args
        );

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        return $body->items ?? [];
    }
}