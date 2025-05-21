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
        
        // $this->api_key is already set and potentially re-encoded above.
        // The cache_time is read from options.
        // There's no need to re-save the option here. Settings are saved by the Admin class.
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
    public function get_gold_price_detail($karat) { // $karat is received but not strictly used to vary the API call for karat other than 24.
        
        try {
            // Always use 24K for detail tile
            $cache_key = 'gold_price_detail_24';
            $cache_file = $this->cache_dir . '/' . $cache_key . '.json';
            

            $is_cache_valid = $this->is_cache_valid($cache_file);
            

            $body = '';
            $source_type = ''; // To log whether we got data from cache (json/xml) or new API call

            if ($is_cache_valid) {
                
                $cache_content_raw = file_get_contents($cache_file);
                if ($cache_content_raw === false) {
                    
                    $is_cache_valid = false; // Treat as cache miss if read fails
                } else {
                    $cache_data = json_decode($cache_content_raw, true);
                    if ($cache_data && isset($cache_data['timestamp'])) {
                        if (isset($cache_data['json_body'])) {
                            $body = $cache_data['json_body'];
                            $source_type = 'json_cache';
                            
                        } elseif (isset($cache_data['xml_body'])) {
                            $body = $cache_data['xml_body'];
                            $source_type = 'xml_cache';
                            
                        } else {
                            
                            $is_cache_valid = false; // Cache format is unexpected
                        }
                    } else {
                        
                        $is_cache_valid = false; // Cache data is corrupted or old format
                    }
                }
            }

            if (!$is_cache_valid || empty($body)) {
                
                $url = $this->build_api_url(24); // 24K only
                
                
                $response = wp_remote_get($url, ['timeout' => 15]); // Added timeout

                if (is_wp_error($response)) {
                    
                    throw new Exception("API request failed: " . $response->get_error_message());
                }

                $response_code = wp_remote_retrieve_response_code($response);
                

                if ($response_code != 200) {
                    
                    throw new Exception("API request failed with HTTP status: " . $response_code);
                }
                
                $body = wp_remote_retrieve_body($response);
                $source_type = 'api';
                 // Log first 500 chars
                
                if (empty($body)) {
                    
                    return null;
                }
            }

            // Try parsing as JSON first
            
            $json = json_decode($body, true);

            if ($json !== null && isset($json['response']['header']['resultCode']) && $json['response']['header']['resultCode'] === '00' && isset($json['response']['body']['items']['item'])) {
                
                $items = $json['response']['body']['items']['item'];
                $item_to_use = null;

                if (isset($items[0]) && is_array($items[0])) { // Check if $items is an array of items
                    
                    $found_specific = false;
                    foreach ($items as $candidate) {
                        if (isset($candidate['itmsNm']) && strpos($candidate['itmsNm'], '금 99.99_1Kg') !== false) {
                            $item_to_use = $candidate;
                            $found_specific = true;
                            
                            break;
                        }
                    }
                    if (!$found_specific && !empty($items)) {
                        $item_to_use = $items[0]; // Use the first item if specific one not found
                        
                    }
                } elseif (is_array($items) && isset($items['itmsNm'])) { // Single item returned directly
                     
                    $item_to_use = $items;
                }

                if ($item_to_use && isset($item_to_use['clpr'])) {
                    
                    if ($source_type === 'api') { // Only cache if fetched from API
                        file_put_contents($cache_file, json_encode(['json_body' => $body, 'timestamp' => time()]));
                        
                    }
                    return array(
                        'basDt' => isset($item_to_use['basDt']) ? $item_to_use['basDt'] : '',
                        'clpr'  => isset($item_to_use['clpr']) ? (float)$item_to_use['clpr'] : 0,
                        'vs'    => isset($item_to_use['vs']) ? (string)$item_to_use['vs'] : '0', // API sends as string
                        'fltRt' => isset($item_to_use['fltRt']) ? (string)$item_to_use['fltRt'] : '0', // API sends as string
                        'itmsNm'=> isset($item_to_use['itmsNm']) ? $item_to_use['itmsNm'] : '',
                    );
                } else {
                    
                    // if ($item_to_use) error_log('[Gold Calculator DEBUG get_gold_price_detail] item_to_use dump: ' . print_r($item_to_use, true));

                }
            } else {
                 
                 // if ($json === null) error_log('[Gold Calculator DEBUG get_gold_price_detail] json_decode returned null. Error: ' . json_last_error_msg());
            }

            // Fallback to XML if JSON parsing failed or didn't yield data
            
            // Prevent XML errors from breaking the page
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();

            if ($xml !== false && isset($xml->header->resultCode) && (string)$xml->header->resultCode === '00' && isset($xml->body->items->item)) {
                
                $items_xml = $xml->body->items->item;
                $item_to_use_xml = null;

                if (count($items_xml) > 1) { // Multiple items
                    
                    $found_specific_xml = false;
                    foreach ($items_xml as $candidate_xml) {
                        if (isset($candidate_xml->itmsNm) && strpos((string)$candidate_xml->itmsNm, '금 99.99_1Kg') !== false) {
                            $item_to_use_xml = $candidate_xml;
                            $found_specific_xml = true;
                            
                            break;
                        }
                    }
                    if (!$found_specific_xml && count($items_xml) > 0) {
                        $item_to_use_xml = $items_xml[0]; // Use the first item
                        
                    }
                } elseif (count($items_xml) === 1) { // Single item
                    
                    $item_to_use_xml = $items_xml;
                }
                
                if ($item_to_use_xml && isset($item_to_use_xml->clpr)) {
                    
                     if ($source_type === 'api') { // Only cache if fetched from API
                        file_put_contents($cache_file, json_encode(['xml_body' => $body, 'timestamp' => time()]));
                        
                    }
                    return array(
                        'basDt' => isset($item_to_use_xml->basDt) ? (string)$item_to_use_xml->basDt : '',
                        'clpr'  => isset($item_to_use_xml->clpr) ? (float)$item_to_use_xml->clpr : 0,
                        'vs'    => isset($item_to_use_xml->vs) ? (string)$item_to_use_xml->vs : '0',
                        'fltRt' => isset($item_to_use_xml->fltRt) ? (string)$item_to_use_xml->fltRt : '0',
                        'itmsNm'=> isset($item_to_use_xml->itmsNm) ? (string)$item_to_use_xml->itmsNm : '',
                    );
                } else {
                     
                }
            } else {
                
                if ($xml === false) {
                    $xml_errors = libxml_get_errors();
                    foreach ($xml_errors as $error) {
                        
                    }
                    libxml_clear_errors();
                }
            }

            
            return null; // If all parsing fails or no suitable item found

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
