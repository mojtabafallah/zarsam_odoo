(function ($) {
    var syncRequested = false;

    jQuery(document).ajaxSuccess(function(event, xhr, settings) {
        if (settings.data && settings.data.indexOf('action=mreeir_verify_code') !== -1) {
            console.log('mreeir_verify_code success');

            if (syncRequested || xhr.status !== 200 || typeof zarsamOdooLoginSync === 'undefined') {
                return;
            }

            syncRequested = true;

            window.setTimeout(function () {
                $.post(zarsamOdooLoginSync.ajaxUrl, {
                    action: 'zarsam_odoo_create_current_customer'
                }).always(function () {
                    syncRequested = false;
                });
            }, 300);
        }
    });
})(jQuery);
