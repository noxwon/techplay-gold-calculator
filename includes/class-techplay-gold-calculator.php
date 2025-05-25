<?php
/**
 * Main Techplay Gold Calculator Class
 */

class Techplay_Gold_Calculator {
    
    protected $admin;
    protected $frontend;
    protected $api;
    protected $db;
    
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_frontend_hooks();
        
        // Activation hook
        register_activation_hook(
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'techplay-gold-calculator.php',
            array('Techplay_Gold_Calculator', 'activate')
        );
        
        // Deactivation hook
        register_deactivation_hook(
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'techplay-gold-calculator.php',
            array('Techplay_Gold_Calculator', 'deactivate')
        );
    }
    
    private function load_dependencies() {
        require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/class-techplay-gold-calculator-db.php';
        require_once TECHPLAY_GOLD_CALCULATOR_PLUGIN_DIR . 'includes/class-techplay-gold-calculator-api.php';
    }
    
    private function define_admin_hooks() {
        if (is_admin()) {
            $this->admin = new Techplay_Gold_Calculator_Admin();
            add_action('admin_menu', array($this->admin, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_admin_scripts'));
            add_action('admin_init', array($this->admin, 'register_settings'));
        }
    }
    
    private function define_frontend_hooks() {
        $this->frontend = Techplay_Gold_Calculator_Frontend::get_instance();
        // add_action('wp_enqueue_scripts', array($this->frontend, 'enqueue_frontend_scripts')); // Handled by Techplay_Gold_Calculator_Loader
        // add_shortcode('gold_calculator', array($this->frontend, 'render_calculator')); // Handled by Techplay_Gold_Calculator_Loader
    }
    
    public static function activate() {
        // Create database table
        $db = Techplay_Gold_Calculator_DB::get_instance();
        $db->activate();
        
        // Create cache directory
        $cache_dir = wp_upload_dir()['basedir'] . '/techplay-gold-calculator-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
    }
    
    public static function deactivate() {
        // Drop database table
        $db = Techplay_Gold_Calculator_DB::get_instance();
        $db->deactivate();
    }
    
    public function run() {
        // Initialize API and database
        $this->api = new Techplay_Gold_Calculator_API();
        $this->db = Techplay_Gold_Calculator_DB::get_instance();
        
        // Add AJAX handlers
        // add_action('wp_ajax_get_price_history', array($this, 'get_price_history')); // Handled by ajax-handlers.php
        // add_action('wp_ajax_nopriv_get_price_history', array($this, 'get_price_history')); // Handled by ajax-handlers.php
        add_action('wp_ajax_calculate_gold_value', array($this, 'calculate_gold_value'));
        add_action('wp_ajax_nopriv_calculate_gold_value', array($this, 'calculate_gold_value'));
        add_action('wp_ajax_test_gold_api', array($this, 'test_gold_api'));
        add_action('wp_ajax_nopriv_test_gold_api', array($this, 'test_gold_api'));
    }
    
    public function test_gold_api() {
        check_ajax_referer('gold_calculator_nonce', 'nonce');
        
        $karat = isset($_POST['karat']) ? intval($_POST['karat']) : 24;
        
        // Enable test mode
        $this->api->set_test_mode(true);
        
        // Try to get price
        $price = $this->api->get_gold_price($karat);
        
        if ($price === false) {
            wp_send_json_error('Failed to get price from API');
        } else {
            wp_send_json_success(array(
                'price' => $price,
                'message' => 'API test successful'
            ));
        }
    }
    
    public function calculate_gold_value() {
        check_ajax_referer('gold_calculator_nonce', 'nonce');
        
        $karat = isset($_POST['karat']) ? intval($_POST['karat']) : 0;
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 0;
        $unit = isset($_POST['unit']) ? sanitize_text_field($_POST['unit']) : 'g';
        
        if ($karat <= 0 || $weight <= 0) {
            wp_send_json_error('Invalid input values');
            return;
        }
        
        try {
            // Try to get price from database first
            $price = $this->db->get_latest_rate($karat);
            
            if ($price === null) {
                // If not in database, get from API
                $price = $this->api->get_gold_price($karat);
                
                if ($price === false) {
                    error_log("[Gold Calculator] Failed to get price for karat $karat");
                    wp_send_json_error('Failed to get current price');
                    return;
                }
                
                // Store in database
                $this->db->insert_rate($karat, $price);
            }
            
            // Convert weight to grams
            $weight_in_grams = $weight;
            switch ($unit) {
                case 'oz':
                    $weight_in_grams = $weight * 31.1034768;
                    break;
                case 'don':
                    $weight_in_grams = $weight * 1.866208608;
                    break;
                case 'tael':
                    $weight_in_grams = $weight * 37.429;
                    break;
            }
            
            $total_value = $price * $weight_in_grams;
            
            wp_send_json_success(array(
                'value' => number_format($total_value, 2)
            ));
        } catch (Exception $e) {
            error_log("[Gold Calculator] Error calculating value: " . $e->getMessage());
            wp_send_json_error('An error occurred while calculating the value');
        }
    }
    
    public function get_price_history() {
        check_ajax_referer('gold_calculator_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'gold_rates';
        
        $query = $wpdb->prepare(
            "SELECT date, karat, price 
             FROM $table_name 
             WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY date ASC",
            array()
        );
        
        $results = $wpdb->get_results($query);
        
        if (!$results) {
            wp_send_json_error('No price history available');
        }
        
        $data = array(
            'labels' => array(),
            'values' => array()
        );
        
        foreach ($results as $result) {
            $data['labels'][] = date('Y-m-d', strtotime($result->date));
            $data['values'][] = floatval($result->price);
        }
        
        wp_send_json_success($data);
    }
}
