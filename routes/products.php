<?php
add_action('rest_api_init', function () {

    register_rest_route('zarsam_odoo/v1', '/products/update', [
        'methods'  => 'POST',
        'callback' => 'zarsam_odoo_update_product',
        'permission_callback' => 'zarsam_odoo_permission'
    ]);

});