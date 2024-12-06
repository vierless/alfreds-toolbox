<?php
// GitHub Update-Checker
if (!class_exists('AlfredsToolbox_UpdateChecker')) {
    class AlfredsToolbox_UpdateChecker {
        private $repo_url = 'https://api.github.com/repos/vierless/alfreds-toolbox/releases/latest';
        private $plugin_slug = 'alfreds-toolbox';
        
        public function __construct() {
            add_filter('site_transient_update_plugins', [$this, 'check_for_update']);
            add_filter('plugins_api', [$this, 'get_plugin_info'], 10, 3);
        }

        public function check_for_update($transient) {
            if (empty($transient->checked)) return $transient;
            
            $latest_release = $this->get_latest_release();
            if (version_compare($this->get_current_version(), $latest_release->tag_name, '<')) {
                $transient->response[$this->plugin_slug] = (object) [
                    'slug' => $this->plugin_slug,
                    'plugin' => 'alfreds-toolbox/alfreds-toolbox.php',
                    'new_version' => $latest_release->tag_name,
                    'url' => $latest_release->html_url,
                    'package' => $latest_release->zipball_url,
                ];
            }

            return $transient;
        }

        public function get_plugin_info($false, $action, $args) {
            if ('plugin_information' === $action && isset($args->slug) && $args->slug === $this->plugin_slug) {
                $latest_release = $this->get_latest_release();
                return (object) [
                    'name' => 'Alfreds Toolbox',
                    'slug' => $this->plugin_slug,
                    'version' => $latest_release->tag_name,
                    'description' => 'Custom Elementor Widgets',
                    'author' => 'VIERLESS GmbH',
                    'homepage' => $latest_release->html_url,
                    'download_link' => $latest_release->zipball_url,
                ];
            }
            return false;
        }

        private function get_latest_release() {
            $response = wp_remote_get($this->repo_url);
            if (is_wp_error($response)) return false;
            return json_decode(wp_remote_retrieve_body($response));
        }

        private function get_current_version() {
            $plugin_file = plugin_dir_path(__FILE__) . 'alfreds-toolbox.php';
            $plugin_data = get_plugin_data($plugin_file);
            return $plugin_data['Version'];
        }
    }

    new AlfredsToolbox_UpdateChecker();
}