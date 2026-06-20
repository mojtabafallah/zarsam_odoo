jQuery(function ($) {
    if (typeof wc_add_to_cart_params === 'undefined') {
        return;
    }

    var defaultErrorMessage = 'امکان افزودن این محصول به سبد خرید وجود ندارد.';

    function isAddToCartRequest(url) {
        if (!url) {
            return false;
        }

        return url.indexOf('add_to_cart') !== -1 || url.indexOf('wc-ajax=add_to_cart') !== -1;
    }

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }

    function resetAddToCartButtons() {
        $('.add_to_cart_button.loading, .single_add_to_cart_button.loading, button.loading.add_to_cart_button')
            .removeClass('loading');
    }

    function showCartError(message) {
        var finalMessage = message || defaultErrorMessage;

        var noticeHtml =
            '<div class="woocommerce-notices-wrapper zarsam-odoo-cart-notice">' +
            '<ul class="woocommerce-error" role="alert">' +
            '<li>' + escapeHtml(finalMessage) + '</li>' +
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

    function handleAddToCartError(response) {
        resetAddToCartButtons();
        showCartError(response && response.error_message ? response.error_message : defaultErrorMessage);
    }

    $.ajaxPrefilter(function (options) {
        if (!isAddToCartRequest(options.url)) {
            return;
        }

        var originalSuccess = options.success;

        options.success = function (response) {
            if (response && response.error) {
                handleAddToCartError(response);
                return;
            }

            if (typeof originalSuccess === 'function') {
                originalSuccess.apply(this, arguments);
            }
        };

        var originalComplete = options.complete;

        options.complete = function (xhr, status) {
            if (status !== 'success') {
                resetAddToCartButtons();
            }

            if (typeof originalComplete === 'function') {
                originalComplete.apply(this, arguments);
            }
        };
    });
});
