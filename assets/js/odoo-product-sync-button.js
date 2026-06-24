jQuery(function ($) {
    var config = window.zarsamOdooProductSync || {};
    var $button = $('#zarsam-odoo-frontend-sync');

    if (!$button.length || !config.ajaxUrl) {
        return;
    }

    var defaultLabel = $button.text();

    $button.on('click', function () {
        if ($button.prop('disabled')) {
            return;
        }

        $button.prop('disabled', true).text(config.loadingText || 'در حال بروزرسانی...');
        $('#zarsam-odoo-frontend-sync-status').text('');

        $.post(config.ajaxUrl, {
            action: 'zarsam_odoo_sync_product_frontend',
            nonce: config.nonce,
            product_id: config.productId
        })
            .done(function (response) {
                if (response && response.success) {
                    $('#zarsam-odoo-frontend-sync-status')
                        .css('color', '#2271b1')
                        .text(response.data.message || config.successText || 'بروزرسانی شد. در حال بارگذاری مجدد...');
                    window.location.reload();
                    return;
                }

                var message =
                    (response && response.data && response.data.message) ||
                    config.errorText ||
                    'خطا در بروزرسانی محصول';

                $('#zarsam-odoo-frontend-sync-status').css('color', '#b32d2e').text(message);
                $button.prop('disabled', false).text(defaultLabel);
            })
            .fail(function () {
                $('#zarsam-odoo-frontend-sync-status')
                    .css('color', '#b32d2e')
                    .text(config.failText || 'خطا در ارتباط با سرور');
                $button.prop('disabled', false).text(defaultLabel);
            });
    });
});
