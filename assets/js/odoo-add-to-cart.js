jQuery(function ($) {
    if (typeof wc_add_to_cart_params === 'undefined') {
        return;
    }

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }

    function showCartError(message) {
        if (!message) {
            return;
        }

        var noticeHtml =
            '<div class="woocommerce-notices-wrapper zarsam-odoo-cart-notice">' +
            '<ul class="woocommerce-error" role="alert">' +
            '<li>' + escapeHtml(message) + '</li>' +
            '</ul>' +
            '</div>';

        $('.zarsam-odoo-cart-notice').remove();

        var $target = $('.single-product .woocommerce-notices-wrapper').first();
        if (!$target.length) {
            $target = $('.woocommerce-notices-wrapper').first();
        }

        if ($target.length) {
            $target.replaceWith(noticeHtml);
        } else if ($('.single-product .summary').length) {
            $('.single-product .summary').first().prepend(noticeHtml);
        } else if ($('.woocommerce').length) {
            $('.woocommerce').first().prepend(noticeHtml);
        } else {
            $('body').prepend(noticeHtml);
        }

        var $notice = $('.zarsam-odoo-cart-notice').first();
        if ($notice.length) {
            $('html, body').animate({ scrollTop: $notice.offset().top - 80 }, 300);
        }
    }

    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        if (!options.url || options.url.indexOf('add_to_cart') === -1) {
            return;
        }

        var originalSuccess = options.success;

        options.success = function (response) {
            if (response && response.error) {
                if (response.error_message) {
                    showCartError(response.error_message);
                }

                $('.add_to_cart_button.loading, .single_add_to_cart_button.loading').removeClass('loading');

                if (response.product_url) {
                    if (typeof originalSuccess === 'function') {
                        originalSuccess.apply(this, arguments);
                    }
                    return;
                }

                return;
            }

            if (typeof originalSuccess === 'function') {
                originalSuccess.apply(this, arguments);
            }
        };
    });
});
