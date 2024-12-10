<?php
/**
 * Plugin Name: Alfreds Toolbox
 * Description: Custom Elementor Widgets
 * Version: 1.0.5
 * Author: VIERLESS GmbH
 */

if (!defined('ABSPATH')) exit;

// Load includes before initializing main class
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/spotify-api.php';

// Load update checker
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Register activation hook
register_activation_hook(__FILE__, function() {
    // Set default values on plugin activation
    add_option('alfreds_toolbox_active_widgets', array(), '', 'no');
});

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

    public function get_widgets() {
        return $this->widgets;
    }

    public function get_active_widgets() {
        return (array) get_option('alfreds_toolbox_active_widgets', []);
    }


    public function __construct() {
        $this->settings = AlfredsToolboxSettings::get_instance();
        
        // Initialisiere active_widgets direkt im Konstruktor
        $this->active_widgets = (array) get_option('alfreds_toolbox_active_widgets', []);
        
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'init_widgets']);
        add_action('elementor/widgets/register', [$this, 'register_elementor_widgets']);
        add_action('elementor/elements/categories_registered', [$this, 'add_elementor_category']);
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
        
        // Aktualisiere active_widgets
        $this->active_widgets = (array) get_option('alfreds_toolbox_active_widgets', []);
    }

    private function get_widget_class_name($widget_id) {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $widget_id)));
    }

    public function register_settings() {
        // Option als Array registrieren
        register_setting('alfreds_toolbox_settings', 'alfreds_toolbox_active_widgets', array(
            'type' => 'array',
            'default' => array()
        ));
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
    global $alfreds_toolbox;
    $alfreds_toolbox = new AlfredsToolbox();
    AlfredsToolboxSettings::get_instance();
}

add_action('plugins_loaded', 'init_alfreds_toolbox');