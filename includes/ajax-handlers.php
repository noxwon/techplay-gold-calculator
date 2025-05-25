<?php
/**
 * AJAX handlers for Techplay Gold Calculator
 */

add_action('wp_ajax_add_gold_rate', 'techplay_add_gold_rate');
add_action('wp_ajax_nopriv_add_gold_rate', 'techplay_add_gold_rate');

function techplay_add_gold_rate() {
    check_ajax_referer('gold_calculator_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $karat = isset($_POST['karat']) ? intval($_POST['karat']) : 0;
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    
    if ($karat <= 0 || $price <= 0) {
        wp_send_json_error('Invalid input values');
    }
    
    $db = Techplay_Gold_Calculator_DB::get_instance();
    $result = $db->insert_rate($karat, $price);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to add rate');
    }
}

add_action('wp_ajax_get_latest_rates', 'techplay_get_latest_rates');
add_action('wp_ajax_nopriv_get_latest_rates', 'techplay_get_latest_rates');

function techplay_get_latest_rates() {
    check_ajax_referer('gold_calculator_nonce', 'nonce');
    
    $db = Techplay_Gold_Calculator_DB::get_instance();
    $rates = array(
        24 => $db->get_latest_rate(24),
        22 => $db->get_latest_rate(22),
        21 => $db->get_latest_rate(21),
        18 => $db->get_latest_rate(18),
        14 => $db->get_latest_rate(14)
    );
    
    wp_send_json_success($rates);
}

add_action('wp_ajax_calculate_gold_value', 'techplay_calculate_gold_value');
add_action('wp_ajax_nopriv_calculate_gold_value', 'techplay_calculate_gold_value');

function techplay_calculate_gold_value() {
    check_ajax_referer('gold_calculator_nonce', 'nonce');
    
    $karat = isset($_POST['karat']) ? intval($_POST['karat']) : 0;
    $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 0;
    $unit = isset($_POST['unit']) ? sanitize_text_field($_POST['unit']) : 'g';
    
    if ($karat <= 0 || $weight <= 0) {
        wp_send_json_error('Invalid input values');
    }
    
    $api = new Techplay_Gold_Calculator_API();
    // Purity table
    $purity_table = array(
        24 => 0.999,
        22 => 0.917,
        21 => 0.875,
        18 => 0.750,
        14 => 0.585
    );
    $purity = isset($purity_table[$karat]) ? $purity_table[$karat] : 1.0;
    $api_detail = method_exists($api, 'get_gold_price_detail') ? $api->get_gold_price_detail($karat) : null;
    if (!$api_detail || !isset($api_detail['clpr'])) {
        wp_send_json_error(['message' => 'No price detail for 24K']);
    }
    $clpr = $api_detail['clpr']; // 1kg 단가

    
    // Convert weight to grams
    $weight_in_grams = $weight;
    switch ($unit) {
        case 'oz':
            $weight_in_grams = $weight * 31.1034768;
            break;
        case 'don':
            $weight_in_grams = $weight * 3.75;
            break;
        case 'tael':
            $weight_in_grams = $weight * 37.5;
            break;
    }
    
    if ($karat == 24) {
        // 24K: clpr(1g 단가) × 무게
        $total_value = $clpr * $weight_in_grams;
    } else {
        // 그 외: clpr(1g 단가) × 무게 × 함량비율
        $total_value = $clpr * $weight_in_grams * $purity;
    }
    $total_value_rounded = round($total_value); // 소숫점 없이 반올림
    wp_send_json_success(array(
        'value' => number_format($total_value_rounded) . '원',
        'api_detail' => $api_detail
    ));
}

add_action('wp_ajax_get_price_history', 'techplay_get_price_history');
add_action('wp_ajax_nopriv_get_price_history', 'techplay_get_price_history');

function techplay_get_price_history() {
    if (isset($_REQUEST['nonce'])) {
        error_log('Received Nonce (AJAX handler): ' . sanitize_text_field($_REQUEST['nonce']));
    } else {
        error_log('Nonce not received in AJAX handler request.');
    }
    // Verify nonce
    check_ajax_referer('gold_calculator_nonce', 'nonce');
    $db = Techplay_Gold_Calculator_DB::get_instance();
    global $wpdb;
    $table_name = $wpdb->prefix . 'gold_rates';

    // Get all unique dates in the last 30 days
    $dates = [];
    for ($i = 0; $i < 30; $i++) {
        $dates[] = date('Ymd', strtotime("-{$i} days"));
    }
    $dates = array_reverse($dates); // oldest to newest

    // Find which dates are missing in the DB
    $existing = [];
    if (count($dates) > 0) {
        $placeholders = implode(',', array_fill(0, count($dates), '%s'));
        if (count($dates) === 1) {
            $query = $wpdb->prepare(
                "SELECT DISTINCT DATE_FORMAT(date, '%Y%m%d') as basDt FROM $table_name WHERE DATE_FORMAT(date, '%Y%m%d') IN ($placeholders)",
                $dates[0]
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT DISTINCT DATE_FORMAT(date, '%Y%m%d') as basDt FROM $table_name WHERE DATE_FORMAT(date, '%Y%m%d') IN ($placeholders)",
                ...$dates
            );
        }
        $existing = $wpdb->get_col($query);
    }
    $missing = array_diff($dates, $existing);

    // If missing, fetch from API and insert
    if (!empty($missing)) {
        try {
            require_once dirname(__FILE__) . '/class-techplay-gold-calculator-api.php';
            $api = new Techplay_Gold_Calculator_API();
            $beginBasDt = min($missing);
            $endBasDt = max($missing);
            // 반드시 build_api_url만 사용 (API 인증키 인코딩/파라미터 통일)
            $url = $api->build_api_url(24, 30, $beginBasDt, $endBasDt);
            error_log('[GoldCalculator][HISTORY] API URL: ' . $url);
            $response = wp_remote_get($url, ['timeout' => 30]);
            if (is_wp_error($response)) {
                error_log('[GoldCalculator] API fetch error: ' . $response->get_error_message());
                wp_send_json_error('API fetch error: ' . $response->get_error_message());
            }
            $body = wp_remote_retrieve_body($response);
            error_log('[GoldCalculator][HISTORY] API raw response: ' . $body);
            $json = json_decode($body, true);
            if (isset($json['response']['body']['items']['item'])) {
                $items = $json['response']['body']['items']['item'];
                error_log('[GoldCalculator][HISTORY] items found: ' . print_r($items, true));
                if (isset($items['basDt'])) {
                    $items = [$items]; // 단일 데이터도 배열로
                }
                // 1. '금 99.99_1Kg'만 필터링
                $kg_items = array_filter($items, function($item) {
                    return isset($item['itmsNm']) && $item['itmsNm'] === '금 99.99_1Kg';
                });
                // 2. DB에 이미 있는 날짜(중복) 조회 (multi-row)
                $kg_dates = array_map(function($item) { return $item['basDt']; }, $kg_items);
                $existing_dates = [];
                if (count($kg_dates) > 0) {
                    $kg_placeholders = implode(',', array_fill(0, count($kg_dates), '%s'));
                    $kg_query = $wpdb->prepare(
                        "SELECT DISTINCT DATE_FORMAT(date, '%Y%m%d') as basDt FROM $table_name WHERE karat = 24 AND DATE_FORMAT(date, '%Y%m%d') IN ($kg_placeholders)",
                        ...$kg_dates
                    );
                    $existing_dates = $wpdb->get_col($kg_query);
                }
                // 3. 실제 insert 대상만 추림 (날짜 기준 중복 제거)
                $to_insert = array_filter($kg_items, function($item) use ($existing_dates) {
                    return !in_array($item['basDt'], $existing_dates);
                });
                // 날짜별로 한 번만 남기기 (중복 제거)
                $unique_to_insert = [];
                foreach ($to_insert as $item) {
                    $unique_to_insert[$item['basDt']] = $item; // 같은 날짜면 마지막 값만 남음
                }
                $to_insert = array_values($unique_to_insert);
                error_log('[GoldCalculator][HISTORY] to_insert count: ' . count($to_insert) . ', dates: ' . implode(',', array_map(function($i){return $i['basDt'];}, $to_insert)));
                // 4. 다중 insert 쿼리 동적 생성
                if (count($to_insert) > 0) {
                    $fields = ['date', 'karat', 'price'];
                    $placeholders = [];
                    $values = [];
                    foreach ($to_insert as $item) {
                        $placeholders[] = '(%s, %d, %f)';
                        $values[] = date('Y-m-d 10:00:00', strtotime($item['basDt']));
                        $values[] = 24;
                        $values[] = floatval($item['clpr']);
                        error_log("[GoldCalculator][HISTORY] Multi-insert attempt: basDt={$item['basDt']}, clpr={$item['clpr']}");
                    }
                    $insert_sql = "INSERT INTO $table_name (date, karat, price) VALUES " . implode(',', $placeholders);
                    $result = $wpdb->query($wpdb->prepare($insert_sql, ...$values));
                    error_log("[GoldCalculator][HISTORY] Multi-insert result: " . var_export($result, true));
                    if ($result === false) {
                        error_log('[GoldCalculator] Multi-insert DB error: ' . $wpdb->last_error);
                        wp_send_json_error('DB insert error: ' . $wpdb->last_error);
                    }
                }
            } else {
                error_log('[GoldCalculator] API response parse error: ' . $body);
                wp_send_json_error('API response parse error: ' . $body);
            }
        } catch (Throwable $e) {
            error_log('[GoldCalculator] Fatal error: ' . $e->getMessage());
            wp_send_json_error('Fatal error: ' . $e->getMessage());
        }
    }

    // Now fetch again
    $query = "SELECT date, karat, price FROM $table_name WHERE karat = 24 AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY date ASC";
    $results = $wpdb->get_results($query);
    if (!$results) {
        wp_send_json_error('No price history found');
    }
    $data = array('labels' => array(), 'values' => array(), 'vs' => array(), 'fltRt' => array());
    // 등락 계산을 위해 이전 가격 저장
    $prev_price = null;
    foreach ($results as $result) {
        $date = date('Y-m-d', strtotime($result->date));
        $price = floatval($result->price);
        $data['labels'][] = $date;
        $data['values'][] = $price;
        if ($prev_price !== null) {
            $vs = $price - $prev_price;
            $fltRt = $prev_price != 0 ? round(($vs / $prev_price) * 100, 2) : 0.00;
        } else {
            $vs = 0;
            $fltRt = 0.00;
        }
        $data['vs'][] = $vs;
        $data['fltRt'][] = $fltRt;
        $prev_price = $price;
    }
    // 날짜별로 첫 값만 남기기 (중복 제거, labels/values/vs/fltRt 모두 동기화)
    $unique = array();
    for ($i = 0; $i < count($data['labels']); $i++) {
        $date = $data['labels'][$i];
        if (!array_key_exists($date, $unique)) {
            $unique[$date] = array(
                'value' => $data['values'][$i],
                'vs' => $data['vs'][$i],
                'fltRt' => $data['fltRt'][$i]
            );
        }
    }
    $data['labels'] = array_keys($unique);
    $data['values'] = array_column($unique, 'value');
    $data['vs'] = array_column($unique, 'vs');
    $data['fltRt'] = array_column($unique, 'fltRt');
    wp_send_json_success($data);
}
