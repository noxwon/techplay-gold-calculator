<?php
/**
 * Frontend class for Techplay Gold Calculator
 */

class Techplay_Gold_Calculator_Frontend {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Private constructor to prevent direct instantiation
    }
    
    public function enqueue_frontend_scripts() {
        if (!defined('TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL')) {
            return;
        }
        
        wp_enqueue_style(
            'techplay-gold-calculator',
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            TECHPLAY_GOLD_CALCULATOR_VERSION
        );
        
        wp_enqueue_script(
            'techplay-gold-calculator',
            TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            TECHPLAY_GOLD_CALCULATOR_VERSION,
            true
        );
        
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            array(),
            '4.4.0',
            true
        );
        
        $nonce_value = wp_create_nonce('gold_calculator_nonce');
        wp_localize_script(
            'techplay-gold-calculator',
            'goldCalculator',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => $nonce_value
            )
        );
    }
    
    public function render_calculator($atts = array()) {
        if ( !defined('DONOTCACHEPAGE') ) {
            define('DONOTCACHEPAGE', true);
        }

        if (!defined('TECHPLAY_GOLD_CALCULATOR_PLUGIN_URL')) {
            return '';
        }
        
        $defaults = array(
            'title' => 'Gold Calculator',
            'default_karat' => '24',
            'default_unit' => 'g'
        );
        
        $atts = shortcode_atts($defaults, $atts);
        
        ob_start();
        ?>
        <div class="gold-calc-container" style="position: relative;">
            <h2 class="gold-calc-title"><?php echo esc_html($atts['title']); ?></h2>
            <div class="gold-calc-theme-toggle" style="position: absolute; top: 10px; right: 10px;">
                <label class="theme-switch">
                    <input type="checkbox" id="gold-calc-theme-toggle">
                    <span class="slider"></span>
                </label>
            </div>
            <div class="gold-calc-main-grid">
                <div class="gold-calc-display" style="position: relative;">
                    <div id="goldbar-svg" style="position: absolute; top: 15px; left: 15px; width: 80px; height: 80px; overflow: hidden; z-index: 0;"></div>
                    <div id="gold-calc-user-input-display" style="font-size: 1.2em; color: #888; margin-bottom: 5px; height: 1.5em; text-align: right; position: relative; z-index: 1;">0g</div>
                    <div id="gold-calc-monetary-value" style="font-size: 1.5em; margin-bottom: 5px; position: relative; z-index: 1;">0원</div>
                    <div id="gold-calc-karat-info" style="font-size: 0.9em; color: #555;"></div>
                    <div id="gold-calc-weight-info" style="font-size: 0.9em; color: #555;"></div>
                </div>
                <div class="gold-calc-row gold-calc-karat-row">
                    <button type="button" class="karat-btn <?php echo ($atts['default_karat'] == '14' ? 'active' : ''); ?>" data-karat="14">14K</button>
                    <button type="button" class="karat-btn <?php echo ($atts['default_karat'] == '18' ? 'active' : ''); ?>" data-karat="18">18K</button>
                    <button type="button" class="karat-btn <?php echo ($atts['default_karat'] == '21' ? 'active' : ''); ?>" data-karat="21">21K</button>
                    <button type="button" class="karat-btn <?php echo ($atts['default_karat'] == '22' ? 'active' : ''); ?>" data-karat="22">22K</button>
                    <button type="button" class="karat-btn <?php echo ($atts['default_karat'] == '24' ? 'active' : ''); ?>" data-karat="24">24K</button>
                </div>
                <div class="gold-calc-row gold-calc-unit-row">
                    <button type="button" class="unit-btn <?php echo ($atts['default_unit'] == 'g' ? 'active' : ''); ?>" data-unit="g">그램</button>
                    <button type="button" class="unit-btn <?php echo ($atts['default_unit'] == 'oz' ? 'active' : ''); ?>" data-unit="oz">온스</button>
                    <button type="button" class="unit-btn <?php echo ($atts['default_unit'] == 'don' ? 'active' : ''); ?>" data-unit="don">돈</button>
                    <button type="button" class="unit-btn <?php echo ($atts['default_unit'] == 'tael' ? 'active' : ''); ?>" data-unit="tael">냥</button>
                </div>
                <div class="gold-calc-row gold-calc-keypad-row">
                    <button type="button" class="keypad-btn" data-value="7">7</button>
                    <button type="button" class="keypad-btn" data-value="8">8</button>
                    <button type="button" class="keypad-btn" data-value="9">9</button>
                    <button type="button" class="keypad-btn gold-calc-backspace" data-value="back">⌫</button>
                    <button type="button" class="keypad-btn" data-value="4">4</button>
                    <button type="button" class="keypad-btn" data-value="5">5</button>
                    <button type="button" class="keypad-btn" data-value="6">6</button>
                    <button type="button" class="keypad-btn gold-calc-ac" data-value="ac">AC</button>
                    <button type="button" class="keypad-btn" data-value="1">1</button>
                    <button type="button" class="keypad-btn" data-value="2">2</button>
                    <button type="button" class="keypad-btn" data-value="3">3</button>
                    <button type="button" class="keypad-btn gold-calc-equal" data-value="=">=</button>
                    <button type="button" class="keypad-btn gold-calc-zero" data-value="0">0</button>
                    <button type="button" class="keypad-btn" data-value=".">.</button>
                </div>
            </div>
            <div style="margin-top:2rem; font-size:0.85em; color:var(--gold-calc-subtext); text-align:center;">
                데이터 출처: 금융위원회_일반상품시세정보 API
            </div>
        </div>
        <div class="gold-calc-extra-info">
            <div id="gold-info-tile-list"></div>
            <button id="gold-info-tile-toggle" style="width: 100%; text-align: center; margin-bottom:10px; display:none;">더보기✨</button>
            <div id="price-history-section" class="price-chart-container" style="display:none;">
                <h3>금 시세 그래프</h3>
                <canvas id="priceChart" height="220"></canvas>
                <div id="price-diff-block"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    
    }
}
