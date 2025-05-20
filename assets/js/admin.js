jQuery(document).ready(function($) {
    // Handle API test button
    $('#test-api-button').on('click', function() {
        $(this).prop('disabled', true).text('Testing...');
        $('.api-test-result').html('');
        
        $.ajax({
            url: goldCalculatorAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'test_gold_api',
                nonce: goldCalculatorAdmin.nonce,
                karat: 24
            },
            success: function(response) {
                if (response.success) {
                    $('.api-test-result').html(
                        '<div class="notice notice-success inline"><p>' + 
                        'API Test Successful! Current 24K gold price: ' + 
                        response.data.price + '원' +
                        '</p></div>'
                    );
                } else {
                    $('.api-test-result').html(
                        '<div class="notice notice-error inline"><p>' + 
                        'API Test Failed: ' + response.data.message +
                        '</p></div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('.api-test-result').html(
                    '<div class="notice notice-error inline"><p>' + 
                    'API Test Failed: ' + error +
                    '</p></div>'
                );
            },
            complete: function() {
                $('#test-api-button').prop('disabled', false).text('Test API Connection');
            }
        });
    });
});
