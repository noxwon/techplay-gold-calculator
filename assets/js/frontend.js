jQuery(document).ready(function($) {
    // Initialize price chart
    let priceChart;
    
    // Chart.js 4.x 최신 문법, 일자별 금 시세 선그래프
    let chartInstance = null;
    function initializeChart({ labels, values }) {
        const ctx = document.getElementById('priceChart').getContext('2d');
        // 기존 차트가 있으면 파괴
        if (chartInstance) {
            chartInstance.destroy();
        }
        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '금 시세 (KRW/g)',
                    data: values,
                    borderColor: '#ffd700',
                    backgroundColor: 'rgba(255, 215, 0, 0.08)',
                    pointBackgroundColor: '#ffd700',
                    pointRadius: 4,
                    tension: 0.2,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toLocaleString() + '원';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: '일자' },
                        ticks: { color: '#888' }
                    },
                    y: {
                        title: { display: true, text: 'KRW/g' },
                        ticks: {
                            color: '#888',
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        },
                        beginAtZero: false
                    }
                }
            }
        });
    }
    
    // Helper function to format numbers with thousands separators
    // Helper function to animate number changes
    function animateNumber($element, finalValueStr, duration = 1000) {
        let currentText = $element.text().replace(/[^\d.-]/g, ''); // Remove non-numeric except dot and minus
        let startValue = parseFloat(currentText) || 0;
        let finalValue = parseFloat(finalValueStr.replace(/[^\d.-]/g, '')) || 0;

        // Avoid animation if values are the same or invalid
        if (startValue === finalValue || isNaN(startValue) || isNaN(finalValue)) {
            $element.text(formatNumberWithCommas(finalValueStr.toString()) + (finalValueStr.toString().includes('원') ? '' : '원'));
            return;
        }

        $({ currentValue: startValue }).animate({ currentValue: finalValue }, {
            duration: duration,
            easing: 'swing', // or 'linear'
            step: function() {
                // Format with commas and append '원' if it's a monetary value display
                let displayText = formatNumberWithCommas(Math.round(this.currentValue).toString());
                if (finalValueStr.includes('원') && !displayText.endsWith('원')) {
                    displayText += '원';
                }
                $element.text(displayText);
            },
            complete: function() {
                // Ensure final value is accurately set and formatted
                let finalText = formatNumberWithCommas(Math.round(this.currentValue).toString());
                if (finalValueStr.includes('원') && !finalText.endsWith('원')) {
                    finalText += '원';
                }
                $element.text(finalText);
            }
        });
    }

    function formatNumberWithCommas(numStr) {
        if (!numStr || numStr === '0' || numStr === '.') return numStr === '.' ? '0.' : '0'; // Handle "0" and "." carefully
        let parts = numStr.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        return parts.join('.');
    }

    // Load price history (통합)
    function loadPriceHistory() {
        goldInfoTileShowCount = 3;

        $.ajax({
            url: goldCalculator.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_price_history',
                nonce: goldCalculator.nonce
            },
            success: function(response) {
                console.log('loadPriceHistory response:', response);
                if (response.success && response.data && response.data.labels && response.data.values) {
                    $('#price-history-section').show();
                    // vs, fltRt가 있으면 포함해서 캐시 구성
                    priceHistoryCache = response.data.labels.map(function(label, idx) {
                        return {
                            label: label,
                            value: response.data.values[idx],
                            vs: response.data.vs ? response.data.vs[idx] : null,
                            fltRt: response.data.fltRt ? response.data.fltRt[idx] : null
                        };
                    });
                    showDays = 3;
                    renderPriceDiffBlock();
                    initializeChart({ labels: response.data.labels, values: response.data.values }); // 차트 직접 그리기
                    // if (typeof renderHistoryChart === 'function') renderHistoryChart();
                } else {
                    $('#price-history-section').hide();
                    let errMsg = '금 시세 데이터를 불러올 수 없습니다.';
                    if (response.data) {
                        errMsg += '<br><span style="color:#e00;">' + response.data + '</span>';
                        alert('금 시세 불러오기 에러: ' + response.data);
                    }
                    $('#price-diff-block').html('<span style="color:#888;">' + errMsg + '</span>');
                }
            },
            error: function(xhr, status, error) {
                $('#price-history-section').show();
                $('#price-diff-block').html('<span style="color:#888;">금 시세 데이터를 불러올 수 없습니다.</span>');
                alert('금 시세 불러오기 에러: ' + status + ' / ' + error + ' / ' + (xhr && xhr.responseText));
                console.error('금 시세 AJAX 에러', xhr, status, error);
            }
        });
    }
    
    // --- Gold info tile update ---
    function updateGoldInfoTile(karat) {
        $.ajax({
            url: goldCalculator.ajaxurl,
            type: 'POST',
            data: {
                action: 'calculate_gold_value', // gold price API returns all info for tile
                nonce: goldCalculator.nonce,
                karat: karat,
                weight: 1, // dummy value for API call
                unit: 'g'
            },
            success: function(response) {
                if (!response.success || !response.data || !response.data.api_detail) {
                    $('#gold-info-tile').hide();
                    return;
                }
                const info = response.data.api_detail;
                // info: { basDt, clpr, vs, fltRt }
                let arrow = '', diffClass = '';
                const vs = parseFloat(info.vs);
                const fltRt = (info.fltRt !== undefined && info.fltRt !== null) ? parseFloat(info.fltRt).toFixed(2) : null;
                if (vs > 0) {
                    arrow = '<span class="arrow-up">&#8593;</span>';
                    diffClass = 'gold-diff up';
                } else if (vs < 0) {
                    arrow = '<span class="arrow-down">&#8595;</span>';
                    diffClass = 'gold-diff down';
                } else {
                    arrow = '';
                    diffClass = 'gold-diff';
                }
                const priceText = `<span class="gold-price">${Number(info.clpr).toLocaleString('ko-KR')}원</span>`;
                }, // End of success callback
                error: function(xhr, status, error) {
                    console.error('Error updating gold info tile for ' + karat + ':', xhr, status, error);
                    $('#gold-info-tile').hide();
                }
            }); // End of $.ajax call
    } // End of updateGoldInfoTile function

function renderPriceDiffBlock() {
    renderGoldInfoTiles();

    // priceHistoryCache: [{label, value}...], showDays: 현재 표시 일수
    let html = '';
    let maxShow = Math.min(showDays, 30, priceHistoryCache.length);
    for (let i = 0; i < maxShow; i++) {
        const item = priceHistoryCache[i];
        const prev = priceHistoryCache[i + 1];
        let diff = '';
        if (prev) {
            const change = item.value - prev.value;
            const rate = prev.value ? ((change / prev.value) * 100).toFixed(2) : '0.00';
            let arrow = change > 0 ? '<span class="arrow-up">▲</span>' : (change < 0 ? '<span class="arrow-down">▼</span>' : '');
            let diffClass = change > 0 ? 'gold-diff up' : (change < 0 ? 'gold-diff down' : 'gold-diff');
            diff = `<span class="${diffClass}">${arrow}${Math.abs(change).toLocaleString('ko-KR')}원 (${rate}%)</span>`;
        }
        let duration = 1200;
        let steps = 24;
        let current = 0;
        let increment = Math.ceil(value / steps);
        let interval = setInterval(function() {
            current += increment;
            if(current >= value) {
                current = value;
                clearInterval(interval);
            }
            $(target).text(current.toLocaleString('ko-KR') + '원');
        }, duration / steps);
    } // Added closing brace here
    }
    // 등락율/차트/더보기
    let priceHistoryCache = [];
    let goldInfoTileShowCount = 3;
    const goldInfoTileMax = 30;

    // gold-info-tile 최근 N개씩 표시 함수
    function renderGoldInfoTiles() {
        if (!priceHistoryCache || priceHistoryCache.length === 0) {
            $('#gold-info-tile-list').html('<span style="color:#888;">금 시세 데이터 없음</span>');
            $('#gold-info-tile-toggle').hide();
            return;
        }
        let count = goldInfoTileShowCount;
        // 항상 최신순(가장 최근이 위)으로 출력 (날짜 내림차순 정렬)
        let sorted = priceHistoryCache.slice().sort(function(a, b) {
            // label이 YYYY-MM-DD 형식일 때 내림차순
            return b.label.localeCompare(a.label);
        });
        let items = sorted.slice(0, Math.min(count, sorted.length));
        let html = '';
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            let vs = item.vs !== undefined && item.vs !== null ? Number(item.vs) : 0;
            let fltRt = item.fltRt !== undefined && item.fltRt !== null ? Number(item.fltRt) : 0;
            // 등락 정보가 모두 0이면(첫 데이터) 출력하지 않음
            if (vs === 0 && fltRt === 0) continue;
            let arrow = vs > 0 ? '<span class="arrow-up">↑</span>' : (vs < 0 ? '<span class="arrow-down">↓</span>' : '');
            let diffClass = vs > 0 ? 'gold-diff up' : (vs < 0 ? 'gold-diff down' : 'gold-diff');
            let diff = `<span class="${diffClass}">${arrow}${Math.abs(vs).toLocaleString('ko-KR')}원 (${fltRt.toFixed(2)}%)</span>`;
            html += `<div class="gold-info-tile"><span class="gold-date">${item.label}</span> <span class="gold-price">${Number(item.value).toLocaleString('ko-KR')}원</span> ${diff}</div>`;
        }
        $('#gold-info-tile-list').html(html);
        // 버튼
        if (priceHistoryCache.length > count) {
            $('#gold-info-tile-toggle').show().text('더보기✨');
        } else if (count > 3) {
            $('#gold-info-tile-toggle').show().text('접기');
        } else {
            $('#gold-info-tile-toggle').hide();
        }
    }

    // gold-info-tile 더보기/접기 버튼 이벤트
    $(document).on('click', '#gold-info-tile-toggle', function(e) {
        e.preventDefault();
        if (goldInfoTileShowCount < Math.min(goldInfoTileMax, priceHistoryCache.length)) {
            // "더보기" (Show More) logic: Increment count
            goldInfoTileShowCount = Math.min(goldInfoTileShowCount + 3, goldInfoTileMax, priceHistoryCache.length);
        } else {
            // "접기" (Collapse) logic: Reset count to initial display
            goldInfoTileShowCount = 3;
        }
        renderGoldInfoTiles(); // Refresh the tiles and button text/visibility

        });

    function renderPriceDiffBlock() {
        renderGoldInfoTiles();

        // priceHistoryCache: [{label, value}...], showDays: 현재 표시 일수
        let html = '';
        let maxShow = Math.min(showDays, 30, priceHistoryCache.length);
        for (let i = 0; i < maxShow; i++) {
            const item = priceHistoryCache[i];
            const prev = priceHistoryCache[i + 1];
            let diff = '';
            if (prev) {
                const change = item.value - prev.value;
                const rate = prev.value ? ((change / prev.value) * 100).toFixed(2) : '0.00';
                let arrow = change > 0 ? '<span class="arrow-up">▲</span>' : (change < 0 ? '<span class="arrow-down">▼</span>' : '');
                let diffClass = change > 0 ? 'gold-diff up' : (change < 0 ? 'gold-diff down' : 'gold-diff');
                diff = `<span class="${diffClass}">${arrow}${Math.abs(change).toLocaleString('ko-KR')}원 (${rate}%)</span>`;
            } else {
                diff = '';
            }
            html += `<div class="gold-daily-row"><span class="gold-date">${item.label}</span> <span class="gold-price">${Number(item.value).toLocaleString('ko-KR')}원</span> ${diff}</div>`;
        }
        console.log('renderPriceDiffBlock', priceHistoryCache, showDays, html);
        // 아래쪽 등락율 리스트 중복 출력을 막기 위해 리스트 출력 부분을 주석 처리
    // $('#price-diff-block').html(html); // 중복 등락율 리스트 출력 제거
    // 버튼 처리
    if (priceHistoryCache.length > maxShow) {
        $('#show-more-history').show();
    } else {
        $('#show-more-history').hide();
    }
    }

    // 더보기 버튼 이벤트
    $('#show-more-history').off('click').on('click', function() {
        showDays = Math.min(showDays + 3, 30, priceHistoryCache.length);
        renderPriceDiffBlock();
        renderHistoryChart && renderHistoryChart();
    });

    // 페이지 진입 시 자동 차트 로드

    // Load price history when page loads
    loadPriceHistory();

// === Gold Calc Modern UI (Grid/Keypad/Theme) ===

(function goldCalcModernUI() {
    const $container = $('.gold-calc-container');
    if ($container.length === 0) return; // Modern UI는 해당 컨테이너가 있을 때만 활성화
    let input = '';
    let $activeKaratButton = $container.find('.karat-btn.active');
    let selectedKarat = $activeKaratButton.length ? $activeKaratButton.data('karat').toString() : '24';
    if (!$activeKaratButton.length) {
        $container.find('.karat-btn[data-karat="24"]').addClass('active');
    }

    let $activeUnitButton = $container.find('.unit-btn.active');
    let selectedUnit = $activeUnitButton.length ? $activeUnitButton.data('unit').toString() : 'g';
    if (!$activeUnitButton.length) {
        $container.find('.unit-btn[data-unit="g"]').addClass('active');
    }

    // State for handling unit conversions after calculation
    let lastCalculatedKarat = null;
    let lastCalculatedWeightInGrams = null;
    let lastCalculatedMonetaryValue = null;

    // Conversion factors to grams
    const GRAMS_PER_OZ = 31.1034768;
    const GRAMS_PER_DON = 3.75;
    const GRAMS_PER_TAEL = 37.5;

    // Precision for different units (decimal places)
    const precisionMap = {
        g: 2,    // Grams
        oz: 3,   // Ounces
        don: 2,  // Don
        tael: 2  // Tael
    };

    function convertToGrams(weight, unit) {
        if (isNaN(parseFloat(weight))) return 0;
        weight = parseFloat(weight);
        switch (unit) {
            case 'oz': return weight * GRAMS_PER_OZ;
            case 'don': return weight * GRAMS_PER_DON;
            case 'tael': return weight * GRAMS_PER_TAEL;
            case 'g':
            default: return weight;
        }
    }

    function convertFromGrams(weightInGrams, unit) {
        if (isNaN(parseFloat(weightInGrams))) return 0;
        weightInGrams = parseFloat(weightInGrams);
        let result;
        switch (unit) {
            case 'oz': result = weightInGrams / GRAMS_PER_OZ; break;
            case 'don': result = weightInGrams / GRAMS_PER_DON; break;
            case 'tael': result = weightInGrams / GRAMS_PER_TAEL; break;
            case 'g':
            default: result = weightInGrams; break;
        }
        const dp = precisionMap[unit] !== undefined ? precisionMap[unit] : 2;
        return parseFloat(result.toFixed(dp));
    }

    //
    // 초기 UI 반영 (페이지 로드 시 기본 활성 버튼 설정)
    // $container.find('.karat-btn[data-karat="' + selectedKarat + '"]').addClass('active');
    // $container.find('.unit-btn[data-unit="' + selectedUnit + '"]').addClass('active');

    // 캐럿 버튼
    $container.on('click', '.karat-btn', function() {
        $container.find('.karat-btn').removeClass('active');
        $(this).addClass('active');
        selectedKarat = $(this).data('karat').toString();

        // Check if there was a previous calculation and the input hasn't been reset
        if (lastCalculatedWeightInGrams !== null && lastCalculatedWeightInGrams > 0 && (input !== '0' && input !== '')) {
            // Set 'input' to the last calculated weight, converted to the current selected unit
            // This ensures calcGold uses the prior weight with the new karat
            input = convertFromGrams(lastCalculatedWeightInGrams, selectedUnit).toString();
            // also update the user input display to reflect this
            $container.find('#gold-calc-user-input-display').text(input + unitLabel(selectedUnit));
            calcGold(); // Recalculate with the new karat and previous weight
        } else {
            // No previous valid calculation, or input was reset.
            // Just update display normally (which clears results and shows current input)
            updateDisplay(); 
        }
        updateGoldBarSVG(input, selectedUnit);
    });

    // 단위 버튼
    $container.on('click', '.unit-btn', function() {
        const newUnit = $(this).data('unit').toString();
        if (selectedUnit === newUnit) return; // No change if same unit

        const previousUnit = selectedUnit;
        selectedUnit = newUnit;

        $container.find('.unit-btn').removeClass('active');
        $(this).addClass('active');

        if (lastCalculatedWeightInGrams !== null && lastCalculatedMonetaryValue !== null) {
            // An active calculation result is being displayed
            const convertedWeightForDisplay = convertFromGrams(lastCalculatedWeightInGrams, selectedUnit);
            // Monetary value and Karat info remain the same
            $container.find('#gold-calc-monetary-value').text(lastCalculatedMonetaryValue);
            $container.find('#gold-calc-karat-info').text(`캐럿 ${lastCalculatedKarat}K`);
            // Update only the weight info
            $container.find('#gold-calc-weight-info').text(`무게 ${convertedWeightForDisplay}${unitLabel(selectedUnit)}`);
            input = convertedWeightForDisplay.toString(); // Update input for SVG and consistency
        } else {
            // No active calculation, or user has started new input; convert current input number
            let currentNumericInput = parseFloat(input);
            if (isNaN(currentNumericInput) || currentNumericInput == 0) { // check for 0 as well
                input = '0';
            } else {
                const weightInGrams = convertToGrams(currentNumericInput, previousUnit);
                input = convertFromGrams(weightInGrams, selectedUnit).toString();
            }
            updateDisplay(); // Regular display update for new input unit
        }
        updateGoldBarSVG(input, selectedUnit);
    });

    // 키패드 버튼
    $container.on('click', '.keypad-btn', function() {
        const val = $(this).data('value').toString();
        if (val !== '=') { // Any input other than '=' invalidates previous calculation for display conversion
            lastCalculatedKarat = null;
            lastCalculatedWeightInGrams = null;
            lastCalculatedMonetaryValue = null;
        }
        if (val === 'ac') {
            input = '';
        } else if (val === 'back') {
            input = input.slice(0, -1);
        } else if (val === '=') {
            if (input && input !== '.' && !isNaN(Number(input)) && Number(input) > 0) { // 유효한 입력일 때만 계산 ('.' 단독 입력 방지 추가)
                calcGold();
            } else {
                updateDisplay('0'); // 유효하지 않으면 0으로 표시
                $container.find('#goldbar-svg').html(''); // SVG도 클리어
            }
        } else if (val === '.') {
            if (input.indexOf('.') === -1 && input.length > 0) { // 소수점은 하나만, 숫자 뒤에만
                input += '.';
            }
        } else { // 숫자 입력
            if (input.length < 10) { // 최대 10자리
                 if (input === '0' && val !== '.') input = val; // 0만 있을 때 다른 숫자 누르면 대체
                 else input += val;
            }
        }
        updateDisplay();
        updateGoldBarSVG(input, selectedUnit); // Call to internal function
    });

    // 테마 적용 및 저장 함수
    function applyTheme(isDarkMode) {
        const themeToggle = $container.find('#gold-calc-theme-toggle');
        if (isDarkMode) {
            $container.addClass('gold-calc-dark'); // Scope to container
            if (themeToggle.length) themeToggle.prop('checked', true);
            localStorage.setItem('goldCalcTheme', 'dark');
        } else {
            $container.removeClass('gold-calc-dark'); // Scope to container
            if (themeToggle.length) themeToggle.prop('checked', false);
            localStorage.setItem('goldCalcTheme', 'light');
        }
    }

    // 테마 토글 이벤트 핸들러
    $container.on('change', '#gold-calc-theme-toggle', function() {
        applyTheme($(this).is(':checked'));
    });

    // 테마 초기화 (localStorage 확인, 없으면 다크모드로 기본 설정)
    function initializeTheme() {
        const savedTheme = localStorage.getItem('goldCalcTheme');
        if (savedTheme === 'dark') {
            applyTheme(true);
        } else if (savedTheme === 'light') {
            applyTheme(false);
        } else {
            // No saved theme, default to dark mode
            applyTheme(true);
        }
    }

    // 디스플레이 업데이트
    function updateDisplay() { // val parameter is removed as 'input' is the source of truth
        let currentInput = input ? input : '0'; 
        
        // Handle case where input might be just "."
        let displayValueForFormatting = currentInput;
        if (currentInput === '.') {
            displayValueForFormatting = '0.'; // Format "0." but keep internal 'input' as "."
        } else if (currentInput === '' && (lastCalculatedMonetaryValue === null || lastCalculatedKarat == null)) { // If input is empty AND no result is shown
             displayValueForFormatting = '0'; // Show '0' when cleared and no result
        }

        let formattedDispValue = formatNumberWithCommas(displayValueForFormatting);
        
        // Ensure the display shows '0g' (or other unit) if input is empty and no result
        let userDisplayStr = (currentInput === '' && (lastCalculatedMonetaryValue === null || lastCalculatedKarat == null)) 
                             ? '0' + unitLabel(selectedUnit) 
                             : formattedDispValue + unitLabel(selectedUnit);
        
        // If input is just '.', show "0.g" or similar but keep 'input' as '.'
        if (currentInput === '.') {
            userDisplayStr = formattedDispValue + unitLabel(selectedUnit);
        }

        $container.find('#gold-calc-user-input-display').text(userDisplayStr);
        
        // Clear or reset result fields ONLY if no valid calculation result is being shown
        if (lastCalculatedMonetaryValue === null || lastCalculatedKarat == null) {
            $container.find('#gold-calc-monetary-value').text( (currentInput === '' || currentInput === '0') ? '0원' : '' );
            $container.find('#gold-calc-karat-info').text('');
            $container.find('#gold-calc-weight-info').text('');
        }
    }

    function unitLabel(u) {
        if (u === 'g') return 'g';
        if (u === 'oz') return 'oz';
        if (u === 'don') return '돈';
        if (u === 'tael') return '냥';
        return '';
    }

    // 계산 AJAX
    function calcGold() {
        const weight = input;
        // Display what is being calculated in the user input display
        $container.find('#gold-calc-user-input-display').text(weight + unitLabel(selectedUnit));
        $container.find('#gold-calc-monetary-value').text('계산중...');
        $container.find('#gold-calc-karat-info').text('');
        $container.find('#gold-calc-weight-info').text('');
        $.ajax({
            url: goldCalculator.ajaxurl,
            type: 'POST',
            data: {
                action: 'calculate_gold_value',
                nonce: goldCalculator.nonce,
                karat: selectedKarat,
                weight: weight,
                unit: selectedUnit
            },
            success: function(response) {
                if (response.success && response.data && response.data.value) {
                    let monetaryValue = response.data.value.toString();
                    if (!monetaryValue.endsWith('원')) {
                        monetaryValue += '원';
                    }
                    // 'weight' is 'const weight = input;' captured at the start of calcGold()
                    // selectedKarat and selectedUnit are from the IIFE's scope
                    lastCalculatedKarat = selectedKarat;
                    lastCalculatedWeightInGrams = convertToGrams(parseFloat(weight), selectedUnit); // 'weight' is input at time of calc
                    lastCalculatedMonetaryValue = monetaryValue; // 'monetaryValue' is the formatted string e.g., "123,456원"

                    // User input display already shows the calculated weight and unit from before AJAX call
                    // $container.find('#gold-calc-user-input-display').text(`${weight}${unitLabel(selectedUnit)}`); // Confirm if needed, or if pre-AJAX set is enough
                    animateNumber($container.find('#gold-calc-monetary-value'), monetaryValue);
                    $container.find('#gold-calc-karat-info').text(`캐럿 ${lastCalculatedKarat}K`);
                    $container.find('#gold-calc-weight-info').text(`무게 ${weight}${unitLabel(selectedUnit)}`);
                } else {
                    let errorMsg = '에러';
                    if(response.data && response.data.message) errorMsg = response.data.message;
                    // User input display already shows the problematic weight and unit
                    $container.find('#gold-calc-monetary-value').text(errorMsg);
                    $container.find('#gold-calc-karat-info').text('');
                    $container.find('#gold-calc-weight-info').text('');
                }
            },
            error: function() {
                $container.find('#gold-calc-monetary-value').text('통신 에러');
                $container.find('#gold-calc-karat-info').text('');
                $container.find('#gold-calc-weight-info').text('');
            }
        });
    }
    
    // 초기화 (Moved into IIFE for correct scope)
    initializeTheme(); // 테마 초기화 (localStorage 또는 다크모드 기본)
    updateDisplay();   // 디스플레이 초기화 (0g 등)
    updateGoldBarSVG(input, selectedUnit); // SVG 초기화 - Call to internal function
    // Ensure initial active state for buttons after container is confirmed
    $container.find('.karat-btn[data-karat="' + selectedKarat + '"]').addClass('active');
    $container.find('.unit-btn[data-unit="' + selectedUnit + '"]').addClass('active');
    //

    // 금바 SVG 업데이트 (그램 환산) - Bean Shape (Ellipse)
    function updateGoldBarSVG(currentWeight, currentUnit) {
        const $goldbarSvgContainer = $container.find('#goldbar-svg');
        let gram = convertToGrams(currentWeight, currentUnit);

        if (isNaN(gram) || gram <= 0) {
            $goldbarSvgContainer.html(''); // Clear SVG if invalid or zero weight
            return;
        }

        // Scaling parameters for the gold circle
        const minGramForScaling = 1.0;    // Grams at which circle is smallest
        const maxGramForScaling = 100.0;  // Grams at which circle is largest (e.g., for 100돈 ~375g, adjust as needed for desired max size within 80px)

        const minCircleDiameter = 20;
        const maxCircleDiameter = 80;

        let currentCircleDiameter;
        // Ensure minGramForScaling and maxGramForScaling are defined in the function's outer scope or passed as params
        // gram variable is assumed to be calculated before this block from currentInput and unit
        if (gram <= minGramForScaling) { 
            currentCircleDiameter = minCircleDiameter;
        } else if (gram >= maxGramForScaling) {
            currentCircleDiameter = maxCircleDiameter;
        } else {
            const gramRange = maxGramForScaling - minGramForScaling;
            const diameterRange = maxCircleDiameter - minCircleDiameter;
            const proportion = (gram - minGramForScaling) / gramRange;
            currentCircleDiameter = minCircleDiameter + (proportion * diameterRange);
        }
        currentCircleDiameter = Math.round(currentCircleDiameter);
        
        const viewBoxWidth = currentCircleDiameter > 0 ? currentCircleDiameter : 1;
        const viewBoxHeight = currentCircleDiameter > 0 ? currentCircleDiameter : 1;

        let $outerContainer = $('#goldbar-svg'); // The one with overflow:hidden
        let $wrapper = $goldbarSvgContainer.find('#goldbar-svg-wrapper');
        
        if ($wrapper.length === 0) { // If wrapper doesn't exist, create and append SVG structure
            const svgHtml = `
<span id="goldbar-svg-wrapper" 
      style="display:block; width:${currentCircleDiameter}px; height:${currentCircleDiameter}px; 
             position:absolute; top:5px; left:0; /* Adjusted top to 5px */ 
             transition: width 0.3s ease-out, height 0.3s ease-out;"> 
  <svg width="100%" height="100%" 
       viewBox="0 0 ${viewBoxWidth} ${viewBoxHeight}" 
       preserveAspectRatio="none" 
       xmlns="http://www.w3.org/2000/svg" 
       style="display:block;">
    <defs>
      <radialGradient id="goldShinyGradient" cx="30%" cy="30%" r="70%" fx="30%" fy="30%">
        <stop offset="0%" style="stop-color:#FFF7AE; stop-opacity:1" />
        <stop offset="50%" style="stop-color:#FFD700; stop-opacity:1" />
        <stop offset="100%" style="stop-color:#B8860B; stop-opacity:1" />
      </radialGradient>
    </defs>
    <ellipse class="gold-circle-shape" 
             cx="${viewBoxWidth / 2}" cy="${viewBoxHeight / 2}" 
             rx="${(viewBoxWidth / 2) * 0.95}" ry="${(viewBoxHeight / 2) * 0.95}" 
             fill="url(#goldShinyGradient)" />
  </svg>
</span>`;
            $goldbarSvgContainer.html(svgHtml);
            // Also set size for the outer container with overflow:hidden
            $outerContainer.css({
                'width': (currentCircleDiameter + 15) + 'px',
                'height': (currentCircleDiameter + 15) + 'px'
            });
        } else { // Wrapper exists, update attributes for smooth transition
            $wrapper.css({
                'top': '5px', // Adjusted top to 5px
                'width': currentCircleDiameter + 'px',
                'height': currentCircleDiameter + 'px'
            });

            let $svgElement = $wrapper.find('svg');
            $svgElement.attr('viewBox', `0 0 ${viewBoxWidth} ${viewBoxHeight}`);

            let $ellipse = $wrapper.find('.gold-circle-shape');
            $ellipse.attr({
                'cx': viewBoxWidth / 2,
                'cy': viewBoxHeight / 2,
                'rx': (viewBoxWidth / 2) * 0.95,
                'ry': (viewBoxHeight / 2) * 0.95
            });

            // Also set size for the outer container with overflow:hidden
            $outerContainer.css({
                'width': (currentCircleDiameter + 15) + 'px',
                'height': (currentCircleDiameter + 15) + 'px'
            });
        }
    }

    // Keyboard input handling
    $(document).on('keydown', function(e) {
        const key = e.key;
        let btnToClick = null;

        if (key >= '0' && key <= '9') {
            btnToClick = $container.find('.keypad-btn[data-value="' + key + '"]');
        } else if (key === '.') {
            btnToClick = $container.find('.keypad-btn[data-value="."]');
        } else if (key === 'Backspace') {
            btnToClick = $container.find('.keypad-btn.gold-calc-backspace');
        } else if (key === 'Enter' || key === '=') {
            e.preventDefault(); // Prevent form submission if calculator is in a form
            btnToClick = $container.find('.keypad-btn.gold-calc-equal');
        } else if (key === 'Escape') {
            btnToClick = $container.find('.keypad-btn.gold-calc-ac');
        }

        if (btnToClick && btnToClick.length) {
            btnToClick.trigger('click');
            // Optionally, add a visual feedback for key press, like a temporary class
            btnToClick.addClass('key-pressed');
            setTimeout(() => {
                btnToClick.removeClass('key-pressed');
            }, 100);
        }
    });

})(); // End of goldCalcModernUI IIFE

});
