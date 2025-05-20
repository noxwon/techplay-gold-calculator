<?php
/**
 * Admin class for Techplay Gold Calculator
 */

class Techplay_Gold_Calculator_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_post_save_gold_calculator_settings', array($this, 'save_settings'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Gold Calculator Settings',
            'Gold Calculator',
            'manage_options',
            'techplay-gold-calculator',
            array($this, 'render_admin_page'),
            'dashicons-chart-line'
        );
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="gold-calculator-admin-container">
                <div class="api-test-section">
                    <h2>API Test</h2>
                    <p>Click the button below to test the API connection:</p>
                    <button id="test-api-button" class="button button-primary">Test API Connection</button>
                    <div id="test-api-result" class="api-test-result"></div>
                </div>
                
                <form method="post" action="options.php">
                    <?php
                    settings_fields('techplay_gold_calculator');
                    do_settings_sections('techplay_gold_calculator');
                    submit_button();
                    ?>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_techplay-gold-calculator' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'techplay-gold-calculator-admin',
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            TECHPLAY_GOLD_CALCULATOR_VERSION
        );
        
        wp_enqueue_script(
            'techplay-gold-calculator-admin',
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            TECHPLAY_GOLD_CALCULATOR_VERSION,
            true
        );
        
        wp_localize_script('techplay-gold-calculator-admin', 'goldCalculatorAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gold_calculator_nonce')
        ));
    }
    
    public function register_settings() {
        register_setting('techplay_gold_calculator', 'techplay_gold_calculator_settings', array($this, 'sanitize_settings'));
        
        add_settings_section(
            'techplay_gold_calculator_section',
            'Gold Calculator Settings',
            array($this, 'settings_section_callback'),
            'techplay_gold_calculator'
        );
        
        add_settings_field(
            'api_key',
            'API Key',
            array($this, 'api_key_field_callback'),
            'techplay_gold_calculator',
            'techplay_gold_calculator_section'
        );
        
        add_settings_field(
            'cache_time',
            'Cache Time (minutes)',
            array($this, 'cache_time_field_callback'),
            'techplay_gold_calculator',
            'techplay_gold_calculator_section'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>Configure the gold calculator settings.</p>';
    }
    
    public function api_key_field_callback() {
        $options = get_option('techplay_gold_calculator_settings');
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        // 강제로 인코딩해서 보여주기 (이미 인코딩된 값이면 그대로)
        if ($api_key && strpos($api_key, '%') === false) {
            $api_key = rawurlencode($api_key);
        }
        echo '<input type="text" name="techplay_gold_calculator_settings[api_key]" value="' . htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8') . '" size="80" />';
        echo '<p class="description">data.go.kr에서 발급받은 인코딩된 API 키를 입력하세요. (예: %2F, %3D 등 포함)</p>';
    }
    
    public function save_settings() {
        if (!isset($_POST['techplay_gold_calculator_settings'])) {
            return;
        }

        $settings = $_POST['techplay_gold_calculator_settings'];
        
        update_option('techplay_gold_calculator_settings', $settings);
    }
    
    public function cache_time_field_callback() {
        $options = get_option('techplay_gold_calculator_settings');
        ?>
        <input type="number" name="techplay_gold_calculator_settings[cache_time]" 
               value="<?php echo esc_attr($options['cache_time'] ?? 30); ?>" 
               min="1" max="1440">
        <p class="description">Time in minutes to cache API responses (1-1440 minutes)</p>
        <?php
    }
    
    public function sanitize_settings($input) {
        $output = array();
        // Always encode the API key before saving
        if (isset($input['api_key'])) {
            $api_key = trim($input['api_key']);
            // Prevent double encoding: decode first, then encode
            $output['api_key'] = rawurlencode(urldecode($api_key));
        }
        if (isset($input['cache_time'])) {
            $output['cache_time'] = intval($input['cache_time']);
        }
        return $output;
    }
}
