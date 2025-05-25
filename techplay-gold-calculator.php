<?php
/**
 * Plugin Name: Techplay Gold Calculator
 * Plugin URI: 
 * Description: A gold calculator plugin for WordPress
 * Version: 1.0.0
 * Author: Techplay
 * Author URI: 
 * Text Domain: techplay-gold-calculator
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TECHPLAY_GOLD_CALCULATOR_VERSION', '1.0.0');
define('TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/class-techplay-gold-calculator.php';
require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/class-techplay-gold-calculator-admin.php';
require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/class-techplay-gold-calculator-frontend.php';
require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/ajax-handlers.php';

// Initialize the plugin
function techplay_gold_calculator_init() {
    $plugin = new Techplay_Gold_Calculator();
    $plugin->run();
    
    // Initialize database
    $db = Techplay_Gold_Calculator_DB::get_instance();
    $db->activate();
}
add_action('plugins_loaded', 'techplay_gold_calculator_init');

// Check if shortcode is used on the page
function techplay_gold_calculator_check_shortcode($content) {
    if (has_shortcode($content, 'gold_calculator')) {
        return true;
    }
    return false;
}

// Conditional loading of resources
class Techplay_Gold_Calculator_Loader {
    private static $instance = null;
    private $frontend;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->frontend = Techplay_Gold_Calculator_Frontend::get_instance();
    }
    
    public function run() {
        // Register the shortcode handler early. 'plugins_loaded' (where this->run() is effectively called from) is fine.
        add_shortcode('gold_calculator', array($this->frontend, 'render_calculator'));

        // Hook 'load_resources' to 'wp' to conditionally enqueue scripts.
        add_action('wp', array($this, 'load_resources'));
    }
    
    public function load_resources() {
        // Only proceed if it's a singular page (post, page, or custom post type).
        if (is_singular()) {
            $current_post = get_queried_object();

            if ($current_post instanceof WP_Post && property_exists($current_post, 'post_content')) {
                // Use WordPress's has_shortcode() directly or the existing wrapper.
                if (has_shortcode($current_post->post_content, 'gold_calculator')) {
                    // If the shortcode is found in the content, then hook the script enqueueing function.
                    add_action('wp_enqueue_scripts', array($this->frontend, 'enqueue_frontend_scripts'));
                }
            }
        }
    }

}



// Initialize loader
function techplay_gold_calculator_setup_loader() {
    $loader = Techplay_Gold_Calculator_Loader::get_instance();
    $loader->run();
}
add_action('plugins_loaded', 'techplay_gold_calculator_setup_loader');
