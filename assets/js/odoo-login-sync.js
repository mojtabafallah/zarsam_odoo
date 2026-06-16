(function ($) {
    var syncRequested = false;
    var pendingKey = 'zarsam_odoo_pending_customer_create';

    function sendCreateCustomerRequest(useBeacon) {
        if (typeof zarsamOdooLoginSync === 'undefined') {
            return;
        }

        var payload = 'action=zarsam_odoo_create_current_customer';

        if (useBeacon && navigator.sendBeacon) {
            var blob = new Blob([payload], {
                type: 'application/x-www-form-urlencoded; charset=UTF-8'
            });

            navigator.sendBeacon(zarsamOdooLoginSync.ajaxUrl, blob);
            return;
        }

        if (window.fetch) {
            fetch(zarsamOdooLoginSync.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload
            }).then(function (response) {
                if (response.ok) {
                    window.localStorage.removeItem(pendingKey);
                }
            });
            return;
        }

        $.post(zarsamOdooLoginSync.ajaxUrl, {
            action: 'zarsam_odoo_create_current_customer'
        }).done(function () {
            window.localStorage.removeItem(pendingKey);
        });
    }

    if (window.localStorage.getItem(pendingKey)) {
        window.setTimeout(function () {
            sendCreateCustomerRequest(false);
        }, 500);
    }

    jQuery(document).ajaxSuccess(function(event, xhr, settings) {
        if (settings.data && settings.data.indexOf('action=mreeir_verify_code') !== -1) {
            console.log('mreeir_verify_code success');

            if (syncRequested || xhr.status !== 200 || typeof zarsamOdooLoginSync === 'undefined') {
                return;
            }

            syncRequested = true;
            window.localStorage.setItem(pendingKey, '1');
            sendCreateCustomerRequest(true);
            syncRequested = false;
        }
    });
})(jQuery);
