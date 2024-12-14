<?php
class SecureStorageHandler {
    /**
     * Prefix für unsere verschlüsselten Optionen
     */
    const OPTION_PREFIX = 'vierless_encrypted_';
    
    /**
     * Cache für den Encryption Key
     */
    private static $encryption_key = null;

    /**
     * Generiert oder holt den Encryption Key basierend auf WordPress Salts
     */
    private function get_encryption_key() {
        if (self::$encryption_key !== null) {
            return self::$encryption_key;
        }

        if (!defined('LOGGED_IN_KEY') || !defined('LOGGED_IN_SALT')) {
            throw new Exception('WordPress security keys are not properly configured.');
        }

        // Kombiniere WordPress Salts für maximale Entropie
        $key_material = LOGGED_IN_KEY . LOGGED_IN_SALT;
        if (defined('NONCE_KEY') && defined('NONCE_SALT')) {
            $key_material .= NONCE_KEY . NONCE_SALT;
        }

        // Nutze WordPress' native hash-Funktion
        self::$encryption_key = wp_hash($key_material);
        
        return self::$encryption_key;
    }

    /**
     * Verschlüsselt und speichert Daten
     */
    public function store_encrypted($key, $value) {
        if (empty($key)) {
            return false;
        }

        try {
            $encryption_key = $this->get_encryption_key();
            
            // Erstelle einen zufälligen Salt für diese spezifische Verschlüsselung
            $salt = wp_generate_password(64, true, true);
            
            // Erstelle den finalen Verschlüsselungskey mit dem Salt
            $final_key = wp_hash($encryption_key . $salt);
            
            // Erstelle IV für GCM
            $iv = random_bytes(12);
            
            // Verschlüssele die Daten
            $encrypted = openssl_encrypt(
                maybe_serialize($value),
                'aes-256-gcm',
                $final_key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }

            // Kombiniere alle Daten für die Speicherung
            $stored_value = base64_encode(json_encode([
                'salt' => $salt,
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data' => base64_encode($encrypted)
            ]));

            // Speichere in WordPress
            return update_option(self::OPTION_PREFIX . $key, $stored_value);

        } catch (Exception $e) {
            error_log('Encryption failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Holt und entschlüsselt Daten
     */
    public function get_encrypted($key) {
        try {
            $stored_value = get_option(self::OPTION_PREFIX . $key);
            
            if (empty($stored_value)) {
                return null;
            }

            $encryption_key = $this->get_encryption_key();
            
            // Dekodiere die gespeicherten Daten
            $stored_data = json_decode(base64_decode($stored_value), true);
            
            if (!isset($stored_data['salt'], $stored_data['iv'], $stored_data['tag'], $stored_data['data'])) {
                throw new Exception('Stored data is corrupt');
            }

            // Rekonstruiere den Verschlüsselungskey
            $final_key = wp_hash($encryption_key . $stored_data['salt']);
            
            // Entschlüssele die Daten
            $decrypted = openssl_decrypt(
                base64_decode($stored_data['data']),
                'aes-256-gcm',
                $final_key,
                OPENSSL_RAW_DATA,
                base64_decode($stored_data['iv']),
                base64_decode($stored_data['tag'])
            );

            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }

            return maybe_unserialize($decrypted);

        } catch (Exception $e) {
            error_log('Decryption failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Löscht verschlüsselte Daten
     */
    public function delete_encrypted($key) {
        return delete_option(self::OPTION_PREFIX . $key);
    }

    /**
     * Prüft ob verschlüsselte Daten existieren
     */
    public function has_encrypted($key) {
        return get_option(self::OPTION_PREFIX . $key) !== false;
    }
}