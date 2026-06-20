jQuery(function ($) {
    var config = window.zarsamOdooAddToCart || {};
    var defaultErrorMessage = config.defaultErrorMessage || 'امکان افزودن این محصول به سبد خرید وجود ندارد.';
    var toastSelectors = '#custom-addtocart-toast, .custom-addtocart-toast';
    var toastVisibleClasses = 'show active visible is-visible open zarsam-odoo-toast-visible';

    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }

    function getErrorMessage() {
        if (config.errorMessage) {
            return config.errorMessage;
        }

        var $wcError = $('.woocommerce-error li, .woocommerce-notices-wrapper .woocommerce-error li').first();
        if ($wcError.length) {
            return $.trim($wcError.text());
        }

        return '';
    }

    function hideThemeSuccessToast() {
        var $toast = $(toastSelectors);

        $toast
            .removeClass(toastVisibleClasses)
            .removeClass('zarsam-odoo-cart-error is-error')
            .hide();

        $toast.find('.toast-btn').show();
    }

    function showThemeErrorToast(message) {
        var finalMessage = message || defaultErrorMessage;
        var $toast = $(toastSelectors);

        hideThemeSuccessToast();

        if ($toast.length) {
            $toast
                .addClass('zarsam-odoo-cart-error is-error ' + toastVisibleClasses)
                .find('.toast-text')
                .text(finalMessage);

            $toast.find('.toast-btn').hide();
            $toast.show();

            $('html, body').animate({ scrollTop: $toast.offset().top - 80 }, 300);
            return;
        }

        showFallbackError(finalMessage);
    }

    function showFallbackError(message) {
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

    function handleFormAddToCartFeedback() {
        var errorMessage = getErrorMessage();

        if (!errorMessage) {
            return;
        }

        hideThemeSuccessToast();
        showThemeErrorToast(errorMessage);
    }

    function resetAddToCartButtons() {
        $('.add_to_cart_button.loading, .single_add_to_cart_button.loading, button.loading.add_to_cart_button')
            .removeClass('loading');
    }

    $(document).on('submit', 'form.cart', function () {
        hideThemeSuccessToast();
        sessionStorage.setItem('zarsam_odoo_cart_form_submit', '1');
    });

    handleFormAddToCartFeedback();

    [150, 400, 900].forEach(function (delay) {
        setTimeout(function () {
            if (getErrorMessage()) {
                handleFormAddToCartFeedback();
            }
        }, delay);
    });

    if (getErrorMessage() && window.MutationObserver) {
        $(toastSelectors).each(function () {
            var toast = this;
            var observer = new MutationObserver(function () {
                if (getErrorMessage()) {
                    handleFormAddToCartFeedback();
                }
            });

            observer.observe(toast, {
                attributes: true,
                attributeFilter: ['class', 'style'],
                childList: true,
                subtree: true
            });
        });
    }

    if (typeof wc_add_to_cart_params !== 'undefined') {
        function isAddToCartRequest(url) {
            if (!url) {
                return false;
            }

            return url.indexOf('add_to_cart') !== -1 || url.indexOf('wc-ajax=add_to_cart') !== -1;
        }

        $.ajaxPrefilter(function (options) {
            if (!isAddToCartRequest(options.url)) {
                return;
            }

            var originalSuccess = options.success;

            options.success = function (response) {
                if (response && response.error) {
                    resetAddToCartButtons();
                    hideThemeSuccessToast();
                    showThemeErrorToast(response.error_message || defaultErrorMessage);
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
    }
});
