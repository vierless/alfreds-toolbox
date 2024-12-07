<?php
/**
 * Plugin Name: Alfreds Toolbox
 * Description: Custom Elementor Widgets
 * Version: 1.0.2
 * Author: VIERLESS GmbH
 */

if (!defined('ABSPATH')) exit;

// Load includes before initializing main class
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/spotify-api.php';

// Load update checker
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/vierless/alfreds-toolbox',
	__FILE__,
	'alfreds-toolbox'
);

$myUpdateChecker->setBranch('production'); // branch for releases
// $myUpdateChecker->setAuthentication('your-token-here'); // for private repos

class AlfredsToolbox {
    private $widgets = [];
    private $active_widgets = [];
    private $settings;

    public function __construct() {
        $this->settings = AlfredsToolboxSettings::get_instance();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'init_widgets']);
        add_action('elementor/widgets/register', [$this, 'register_elementor_widgets']);
        add_action('elementor/elements/categories_registered', [$this, 'add_elementor_category']);
        add_action('admin_init', function() {
            $this->settings->add_settings_section();
        });
        add_action('elementor/editor/after_enqueue_scripts', function () {
            wp_enqueue_style(
                'font-awesome-5',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',
                [],
                '5.15.4'
            );
        });
    }

    public function add_elementor_category($elements_manager) {
        $elements_manager->add_category(
            'alfreds-toolbox',
            [
                'title' => 'Alfreds Toolbox',
                'icon' => 'fa fa-plug',
            ]
        );
    }

    public function init_widgets() {
        $widgets_dir = plugin_dir_path(__FILE__) . 'widgets/';
        
        if ($handle = opendir($widgets_dir)) {
            while (false !== ($widget_folder = readdir($handle))) {
                if ($widget_folder != "." && $widget_folder != "..") {
                    $widget_file = $widgets_dir . $widget_folder . '/index.php';
                    error_log('Checking widget file: ' . $widget_file);
                    
                    if (file_exists($widget_file)) {
                        require_once $widget_file;
                        $class_name = $this->get_widget_class_name($widget_folder);
                        
                        if (class_exists($class_name)) {
                            $widget = new $class_name();
                            $this->widgets[$widget_folder] = [
                                'name' => $widget->get_title(),
                                'description' => method_exists($widget, 'get_description') ? $widget->get_description() : '',
                                'class' => get_class($widget)
                            ];
                        }
                    }
                }
            }
            closedir($handle);
        }
    
        $this->active_widgets = (array) get_option('alfreds_toolbox_active_widgets', []);
        error_log('Active widgets from DB: ' . print_r($this->active_widgets, true));
        error_log('Available widgets: ' . print_r(array_keys($this->widgets), true));
    }

    private function get_widget_class_name($widget_id) {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $widget_id)));
    }

    public function add_admin_menu() {
        $icon_url = plugin_dir_url(__FILE__) . 'assets/alfreds-toolbox-logo.svg';
    
        add_options_page(
            'Alfreds Toolbox',
            'Alfreds Toolbox',
            'manage_options',
            'alfreds-toolbox',
            [$this, 'admin_page']
        );
        
        add_action('admin_head', function() {
            echo '<style>
                #toplevel_page_alfreds-toolbox .wp-menu-image img {
                    width: 20px !important;
                    height: 20px !important;
                    fill: currentColor !important;
                }
            </style>';
        });
    }

    public function register_settings() {
        register_setting('alfreds_toolbox_settings', 'alfreds_toolbox_active_widgets');
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
    
        if (isset($_GET['settings-updated'])) {
            add_settings_error('alfreds_toolbox_messages', 'alfreds_toolbox_message', 'Einstellungen gespeichert', 'updated');
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('alfreds_toolbox_messages'); ?>
    
            <form method="post" action="options.php">
                <?php 
                settings_fields('alfreds_toolbox_settings');
                do_settings_sections('alfreds-toolbox');
                ?>
                
                <h2>Aktive Widgets</h2>
                <table class="widefat" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th>Aktivieren</th>
                            <th>Widget Name</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->widgets as $widget_id => $widget) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox"
                                        name="alfreds_toolbox_active_widgets[]"
                                        value="<?php echo esc_attr($widget_id); ?>"
                                        <?php checked(in_array($widget_id, $this->active_widgets)); ?>>
                                </td>
                                <td><?php echo esc_html($widget['name']); ?></td>
                                <td><?php echo esc_html($widget['description']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Änderungen speichern'); ?>
            </form>
        </div>
        <?php
    }

    public function register_elementor_widgets($widgets_manager) {
        foreach ($this->widgets as $widget_id => $widget) {
            if (in_array($widget_id, $this->active_widgets)) {
                $widget_class = $widget['class'];
                $widgets_manager->register(new $widget_class());
            }
        }
    }
}

function init_alfreds_toolbox() {
    new AlfredsToolbox();
}

add_action('plugins_loaded', 'init_alfreds_toolbox');