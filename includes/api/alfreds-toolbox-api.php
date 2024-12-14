<?php
require_once plugin_dir_path(__FILE__) . '../secure-storage-handler.php';

class AlfredsToolboxAPI {
    private $api_url = 'https://api.vierless.de/api/wp-credentials/verify-credentials';
    private $storage;
    private $cache_duration = 3600; // 1 Stunde Cache-Dauer
    
    public function __construct() {
        $this->storage = new SecureStorageHandler();
    }

    /**
     * Hole Credentials für einen spezifischen Service
     */
    public function get_credentials($service_type) {
        try {
            // Versuche gecachte Credentials zu holen
            $cached = $this->storage->get_encrypted('credentials_' . $service_type);
            
            if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
                error_log('Using cached credentials for ' . $service_type);
                return $cached['data'];
            }
    
            // Hole neue Credentials von der API
            $response = $this->fetch_credentials();
            
            if (!$response['success']) {
                throw new Exception($response['error']);
            }
    
            $credentials = $response['data']['credentials'][$service_type] ?? null;
            
            // Konvertiere Array zu String für das Logging
            error_log('Fetched credentials for ' . $service_type . ': ' . print_r($credentials, true));
    
            if (empty($credentials)) {
                throw new Exception("No credentials available for {$service_type}");
            }
    
            // Cache die Credentials
            $this->storage->store_encrypted('credentials_' . $service_type, [
                'data' => $credentials,
                'expires' => time() + $this->cache_duration
            ]);
    
            error_log('Stored credentials in cache for ' . $service_type);
            return $credentials;
    
        } catch (Exception $e) {
            $this->handle_error($e);
            return null;
        }
    }

    /**
     * Hole die Lizenzinformationen
     */
    public function get_license_info() {
        try {
            $response = $this->fetch_credentials();
            
            if (!$response['success']) {
                throw new Exception($response['error']);
            }

            return $response['data']['license'];

        } catch (Exception $e) {
            $this->handle_error($e);
            return null;
        }
    }

    // In AlfredsToolboxAPI.php
    public function validate_license($key = null) {
        try {
            // Wenn kein Key übergeben wurde, nehmen wir den aus den Optionen
            $license_key = $key ?? get_option('alfreds_toolbox_license_key');

            if (empty($license_key)) {
                return [
                    'success' => false,
                    'message' => 'Kein Lizenzschlüssel hinterlegt'
                ];
            }

            // API Request mit dem zu validierenden Key
            $response = wp_remote_post($this->api_url, [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'domain' => parse_url(get_site_url(), PHP_URL_HOST),
                    'license_key' => $license_key
                ]),
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                throw new Exception('Ungültiger Lizenzschlüssel');
            }

            return [
                'success' => true,
                'message' => 'Lizenz gültig'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * API Request durchführen
     */
    private function fetch_credentials() {
        $response = wp_remote_post($this->api_url, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'domain' => parse_url(get_site_url(), PHP_URL_HOST),
                'license_key' => get_option('alfreds_toolbox_license_key')
            ]),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            throw new Exception($body['error'] . 
                (isset($body['details']) ? ': ' . $body['details'] : ''));
        }

        return $body;
    }

    /**
     * Cache leeren
     */
    public function clear_cache() {
        $services = ['spotify', 'service_account'];
        foreach ($services as $service) {
            $this->storage->delete_encrypted('credentials_' . $service);
        }
    }

    /**
     * Fehlerbehandlung
     */
    private function handle_error($error) {
        error_log('AlfredsToolbox API Error: ' . $error->getMessage());
        
        // Speichere den Fehler für die Admin Notice
        set_transient('alfreds_toolbox_api_error', [
            'message' => $error->getMessage(),
            'time' => time()
        ], 60 * 5); // 5 Minuten

        // Admin Notice registrieren
        add_action('admin_notices', [$this, 'show_admin_notice']);
    }

    /**
     * Admin Notice anzeigen
     */
    public function show_admin_notice() {
        $error = get_transient('alfreds_toolbox_api_error');
        if ($error) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong>Alfreds Toolbox API Error:</strong> 
                    <?php echo esc_html($error['message']); ?>
                    <br>
                    <small>Error occurred at: <?php echo date('Y-m-d H:i:s', $error['time']); ?></small>
                </p>
            </div>
            <?php
            delete_transient('alfreds_toolbox_api_error');
        }
    }
}