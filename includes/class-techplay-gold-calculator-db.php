<?php
/**
 * Database class for Techplay Gold Calculator
 */

class Techplay_Gold_Calculator_DB {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'gold_rates';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            date datetime NOT NULL,
            karat int(2) NOT NULL,
            price decimal(10,2) NOT NULL,
            PRIMARY KEY  (id),
            KEY date (date),
            KEY karat (karat)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            try {
                dbDelta($sql);
                error_log("[Gold Calculator] Database table created successfully");
            } catch (Exception $e) {
                error_log("[Gold Calculator] Error creating database table: " . $e->getMessage());
                throw new Exception("Failed to create database table: " . $e->getMessage());
            }
        } else {
    
        }
    }
    
    public static function deactivate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gold_rates';
        
        try {
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
            error_log("[Gold Calculator] Database table dropped successfully");
        } catch (Exception $e) {
            error_log("[Gold Calculator] Error dropping database table: " . $e->getMessage());
        }
    }
    
    public function insert_rate($karat, $price) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gold_rates';
        
        $wpdb->insert(
            $table_name,
            array(
                'date' => current_time('mysql'),
                'karat' => $karat,
                'price' => $price
            ),
            array('%s', '%d', '%f')
        );
        
        return $wpdb->insert_id;
    }
    
    public function get_latest_rate($karat) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'gold_rates';
        
        $query = $wpdb->prepare(
            "SELECT price FROM $table_name 
            WHERE karat = %d 
            ORDER BY date DESC 
            LIMIT 1",
            $karat
        );
        
        return $wpdb->get_var($query);
    }
}
