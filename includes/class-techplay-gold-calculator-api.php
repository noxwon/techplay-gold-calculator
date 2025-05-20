<?php
/**
 * API class for Techplay Gold Calculator
 */

class Techplay_Gold_Calculator_API {
    private $api_key;

    // Public getter for API key
    public function get_api_key() {
        return $this->api_key;
    }
    private $cache_time;
    private $cache_dir;
    private $test_mode = false;
    
    public function __construct() {
        $options = get_option('techplay_gold_calculator_settings');
        $this->api_key = $options['api_key'] ?? '';
        
        // Ensure API key is properly encoded
        if (strpos($this->api_key, '%') === false) {
            // If it's not already encoded, encode it
            $this->api_key = str_replace('/', '%2F', $this->api_key);
            $this->api_key = str_replace('=', '%3D', $this->api_key);
            $this->api_key = str_replace('+', '%2B', $this->api_key);
        }
        
        // Save the encoded API key back to options
        update_option('techplay_gold_calculator_settings', array(
            'api_key' => $this->api_key,
            'cache_time' => $options['cache_time'] ?? 30
        ));
        
        $this->cache_time = $options['cache_time'] ?? 30;
        
        $this->cache_dir = wp_upload_dir()['basedir'] . '/techplay-gold-calculator-cache';
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
    }
    
    public function set_test_mode($mode = true) {
        $this->test_mode = $mode;
    }
    
    private function log_request($karat, $url) {
        error_log("[Gold Calculator] Request Details:");
        error_log("[Gold Calculator] Karat: $karat");
        error_log("[Gold Calculator] API Key: " . substr($this->api_key, 0, 5) . '...' . substr($this->api_key, -5));
        error_log("[Gold Calculator] URL: $url");
        error_log("[Gold Calculator] Cache Time: " . $this->cache_time . " minutes");
        error_log("[Gold Calculator] Test Mode: " . ($this->test_mode ? 'Enabled' : 'Disabled'));
    }
    
    private function log_response($response, $body) {
        error_log("[Gold Calculator] Response Details:");
        error_log("[Gold Calculator] Response Code: " . wp_remote_retrieve_response_code($response));
        $headers = wp_remote_retrieve_headers($response);
        error_log("[Gold Calculator] Response Headers: " . print_r($headers, true));
        
        // Log content type
        if (isset($headers['content-type'])) {
            error_log("[Gold Calculator] Content Type: " . $headers['content-type']);
        }
        
        // Log response body with length check
        if (strlen($body) > 1000) {
            error_log("[Gold Calculator] Response Body (truncated): " . substr($body, 0, 1000) . '...');
        } else {
            error_log("[Gold Calculator] Response Body: " . $body);
        }
    }
    
    /**
     * Return ONLY the 24K gold price (순금) for calculation base
     */
    public function get_gold_price($karat) {
        try {
            $cache_key = 'gold_price_' . $karat;
            $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
            
            // Check cache
            if ($this->is_cache_valid($cache_file)) {
                $cache_data = json_decode(file_get_contents($cache_file), true);
                error_log("[Gold Calculator] Using cached price for karat $karat: " . $cache_data['price']);
                return $cache_data['price'];
            }
            
            // Test mode: Skip cache and force API call
            if ($this->test_mode) {
                error_log("[Gold Calculator] Test mode enabled - forcing API call");
            }
            
            // Fetch from API
            $url = $this->build_api_url($karat);
            
            // Log request details
            $this->log_request($karat, $url);
            
            // Add proper headers and user agent
            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/xml',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
                ),
                'sslverify' => false, // Disable SSL verification for testing
                'httpversion' => '1.1'
            );
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                error_log("[Gold Calculator] API Error: " . $response->get_error_message());
                throw new Exception("API request failed: " . $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            
            // Log response details
            $this->log_response($response, $body);
            
            // Log raw response body
            error_log("[Gold Calculator] Raw Response Body: " . $body);
            
            if (empty($body)) {
                throw new Exception("Empty response from API");
            }
            
            // Try to parse XML
            $xml = simplexml_load_string($body);
            
            if ($xml === false) {
                // Try to parse as JSON if XML fails
                $json = json_decode($body, true);
                if ($json !== null && isset($json['response']['body']['items']['item'])) {
                    $items = $json['response']['body']['items']['item'];
                    $price = 0;
                    // 배열이면 '금 99.99_1Kg'만 추출
                    if (isset($items[0])) {
                        $found = false;
                        foreach ($items as $candidate) {
                            if (isset($candidate['itmsNm']) && strpos($candidate['itmsNm'], '금 99.99_1Kg') !== false) {
                                $price = isset($candidate['clpr']) ? (float)$candidate['clpr'] : 0;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            // 못 찾으면 첫 번째 사용
                            $price = isset($items[0]['clpr']) ? (float)$items[0]['clpr'] : 0;
                        }
                    } else {
                        $price = isset($items['clpr']) ? (float)$items['clpr'] : 0;
                    }
                    // 캐시 저장
                    $cache_data = array(
                        'price' => $price,
                        'timestamp' => time()
                    );
                    file_put_contents($cache_file, json_encode($cache_data));
                    error_log("[Gold Calculator] Cached price for karat $karat (from JSON): " . $price);
                    return $price;
                } else {
                    error_log("[Gold Calculator] Failed to parse API response as XML or JSON");
                    throw new Exception("Failed to parse API response");
                }
            }
            
            $price = $this->extract_price_from_xml($xml);
            
            if ($price === false) {
                throw new Exception("No price found in API response");
            }
            
            // Cache the result
            $cache_data = array(
                'price' => $price,
                'timestamp' => time()
            );
            file_put_contents($cache_file, json_encode($cache_data));
            
            error_log("[Gold Calculator] Cached price for karat $karat: " . $price);
            return $price;
            
        } catch (Exception $e) {
            error_log("[Gold Calculator] Error getting gold price: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Return price detail info for display tile (always 24K 기준)
     * @return array|null
     */
    public function get_gold_price_detail($karat) {
        try {
            // Always use 24K for detail tile
            $cache_key = 'gold_price_detail_24';
            $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
            $is_cache = $this->is_cache_valid($cache_file);
            $xml = null;
            $body = '';
            if ($is_cache) {
                $cache_data = json_decode(file_get_contents($cache_file), true);
                if ($cache_data && isset($cache_data['xml'])) {
                    $body = $cache_data['xml'];
                    $xml = simplexml_load_string($body);
                }
            }
            if (!$xml) {
                $url = $this->build_api_url(24); // 24K only
                $response = wp_remote_get($url);
                $body = wp_remote_retrieve_body($response);

                // JSON인지 XML인지 판별
                $json = json_decode($body, true);
                if ($json !== null && isset($json['response']['body']['items']['item'])) {
                    $item = $json['response']['body']['items']['item'];
                    // item이 배열(여러 건)일 경우 '금 99.99_1Kg'만 추출
                    if (isset($item[0])) {
                        $found = false;
                        foreach ($item as $candidate) {
                            if (isset($candidate['itmsNm']) && strpos($candidate['itmsNm'], '금 99.99_1Kg') !== false) {
                                $item = $candidate;
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            // 못 찾으면 첫 번째 사용
                            $item = $item[0];
                        }
                    }
                    file_put_contents($cache_file, json_encode(['json'=>$body,'timestamp'=>time()]));
                    return array(
                        'basDt' => isset($item['basDt']) ? $item['basDt'] : '',
                        'clpr'  => isset($item['clpr']) ? (float)$item['clpr'] : 0,
                        'vs'    => isset($item['vs']) ? (float)$item['vs'] : 0,
                        'fltRt' => isset($item['fltRt']) ? (float)$item['fltRt'] : 0,
                        'itmsNm'=> isset($item['itmsNm']) ? $item['itmsNm'] : '',
                    );
                } else {
                    // XML fallback
                    $xml = simplexml_load_string($body);
                    file_put_contents($cache_file, json_encode(['xml'=>$body,'timestamp'=>time()]));
                }
            }
            if (!$xml || !isset($xml->body->items->item)) {
                return null;
            }
            $item = $xml->body->items->item;
            return array(
                'basDt' => (string)$item->basDt,
                'clpr' => (float)$item->clpr,
                'vs' => (float)$item->vs,
                'fltRt' => (float)$item->fltRt,
                'itmsNm' => (string)$item->itmsNm,
            );
        } catch (Exception $e) {
            return null;
        }
    }

    public function build_api_url($karat = 24, $numOfRows = 30, $beginBasDt = null, $endBasDt = null) {
        $base_url = 'https://apis.data.go.kr/1160100/service/GetGeneralProductInfoService/getGoldPriceInfo';
        $url = $base_url . '?serviceKey=' . $this->api_key;
        $params = array(
            'numOfRows' => $numOfRows,
            'pageNo' => 1,
            'resultType' => 'json',
            'goldKarat' => $karat
        );
        if ($beginBasDt) $params['beginBasDt'] = $beginBasDt;
        if ($endBasDt) $params['endBasDt'] = $endBasDt;
        $url .= '&' . http_build_query($params, '', '&');
        return $url;
    }
    
    private function extract_price_from_json($json) {
        // Check for error response
        if (isset($json['response']['header']['resultCode']) && $json['response']['header']['resultCode'] !== '00') {
            $header = $json['response']['header'];
            error_log("[Gold Calculator] API Error Response:");
            error_log("[Gold Calculator] Error Message: " . (string)$header['resultMsg']);
            error_log("[Gold Calculator] Return Auth Message: " . (string)$header['returnAuthMsg']);
            error_log("[Gold Calculator] Return Reason Code: " . (string)$header['returnReasonCode']);
            error_log("[Gold Calculator] Error Message: " . (string)$header->errMsg);
            error_log("[Gold Calculator] Return Auth Message: " . (string)$header->returnAuthMsg);
            error_log("[Gold Calculator] Return Reason Code: " . (string)$header->returnReasonCode);
            
            // Throw specific error for API key issues
            if ((string)$header->returnAuthMsg === 'SERVICE_KEY_IS_NOT_REGISTERED_ERROR') {
                throw new Exception("Invalid or unregistered API key. Please check your API key and ensure it's properly registered at data.go.kr.");
            }
            
            return false;
        }
        
        // Check for successful response
        if (isset($xml->body->items->item)) {
            $item = $xml->body->items->item;
            if (isset($item->clpr)) { // clpr contains the closing price
                $price = floatval((string)$item->clpr);
                error_log("[Gold Calculator] Found gold price: " . $price);
                return $price;
            }
        }
        
        error_log("[Gold Calculator] No price found in API response");
        return false;
    }
    
    private function is_cache_valid($cache_file) {
        if (!file_exists($cache_file)) {
            return false;
        }
        
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (!$cache_data || !isset($cache_data['timestamp'])) {
            return false;
        }
        
        $cache_age = time() - $cache_data['timestamp'];
        return $cache_age <= ($this->cache_time * 60);
    }
}
