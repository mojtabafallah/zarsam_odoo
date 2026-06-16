<?php

namespace Mojtaba\ZarsamOdoo;

use Exception;
use WC_Product_Simple;

if ( !defined( 'ABSPATH' ) ) exit;

class OdooWooSync
{

    private $db      = "product";
    private $uid     = 2;
    private $api_key = "";

    const META_RAW_DATA    = 'zarsam_odoo_raw_data';
    const META_CALCULATION = 'zarsam_odoo_calculation';
    const META_LAST_SYNC   = 'zarsam_odoo_last_sync';

    const USER_META_ODOO_ID   = 'zarsam_odoo_customer_id';
    const USER_META_RAW_DATA  = 'zarsam_odoo_customer_raw_data';
    const USER_META_LAST_SYNC = 'zarsam_odoo_customer_last_sync';
    const USER_META_FROM_ODOO = 'zarsam_odoo_created_from_odoo';
    const USER_META_CREATE_SENT = 'zarsam_odoo_customer_create_sent';

    private static $instance                   = null;
    private        $is_importing_odoo_customer = false;

    public function __construct()
    {
        self::$instance = $this;
        $this->api_key  = get_option( 'odoo_token' );
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'user_register', [ $this, 'create_customer' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'create_order' ] );
        add_action( 'woocommerce_payment_complete', [ $this, 'sync_order_customer_wallet_after_payment' ] );
        add_action( 'wp_login', [ $this, 'sync_customer_wallet_after_login' ], 10, 2 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'sync_current_customer_wallet_on_checkout' ] );
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_cart_with_odoo_before_checkout' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_login_sync_script' ] );
        add_action( 'template_redirect', [ $this, 'maybe_create_current_customer_after_login' ], 20 );

        add_action( 'add_meta_boxes', [ $this, 'register_product_meta_box' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_order_sync_meta_box' ] );
        add_action( 'wp_ajax_get_zarsim_rates', [ $this, 'handle_zarsim_rates_request' ] );
        add_action( 'wp_ajax_zarsim_update_product_rate', [ $this, 'ajax_update_product_rate' ] );
        add_action( 'wp_ajax_zarsim_sync_fetch_products', [ $this, 'ajax_fetch_products' ] );
        add_action( 'wp_ajax_zarsim_sync_products_batch', [ $this, 'ajax_sync_products_batch' ] );
        add_action( 'wp_ajax_zarsim_sync_fetch_customers', [ $this, 'ajax_fetch_customers' ] );
        add_action( 'wp_ajax_zarsim_sync_customers_batch', [ $this, 'ajax_sync_customers_batch' ] );
        add_action( 'wp_ajax_zarsim_sync_single_customer', [ $this, 'ajax_sync_single_customer' ] );
        add_action( 'wp_ajax_zarsim_sync_single_order', [ $this, 'ajax_sync_single_order' ] );
        add_action( 'wp_ajax_zarsam_odoo_create_current_customer', [ $this, 'ajax_create_current_customer' ] );
        add_action( 'wp_ajax_nopriv_zarsam_odoo_create_current_customer', [ $this, 'ajax_create_current_customer' ] );
        add_action( 'wp_ajax_zarsam_odoo_create_user_customer', [ $this, 'ajax_create_user_customer' ] );
        add_action( 'admin_post_zarsam_odoo_export_logs', [ $this, 'export_logs' ] );

        add_filter( 'manage_users_columns', [ $this, 'add_customer_sync_user_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_customer_sync_user_column' ], 10, 3 );
        add_action( 'show_user_profile', [ $this, 'render_customer_sync_profile_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_customer_sync_profile_section' ] );
        add_action( 'personal_options_update', [ $this, 'save_customer_sync_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_customer_sync_profile_fields' ] );
        add_action( 'admin_footer-users.php', [ $this, 'render_customer_sync_admin_script' ] );
        add_action( 'admin_footer-user-edit.php', [ $this, 'render_customer_sync_admin_script' ] );
        add_action( 'admin_footer-profile.php', [ $this, 'render_customer_sync_admin_script' ] );
    }

    public static function get_instance(): self
    {
        return self::$instance;
    }

    public function enqueue_login_sync_script(): void
    {
        $script_path = dirname( __DIR__ ) . '/assets/js/odoo-login-sync.js';
        $script_url  = plugins_url( 'assets/js/odoo-login-sync.js', dirname( __DIR__ ) . '/zarsam_odoo.php' );

        wp_enqueue_script(
            'zarsam-odoo-login-sync',
            $script_url,
            [ 'jquery' ],
            file_exists( $script_path ) ? (string) filemtime( $script_path ) : ZARSAM_ODOO_VERSION,
            true
        );

        wp_localize_script( 'zarsam-odoo-login-sync', 'zarsamOdooLoginSync', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    public function ajax_create_current_customer(): void
    {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'کاربر هنوز وارد نشده است' ], 401 );
        }

        $user_id  = get_current_user_id();
        $lock_key = 'zarsam_odoo_create_customer_after_login_' . $user_id;

        if ( get_transient( $lock_key ) ) {
            wp_send_json_success( [ 'message' => 'درخواست ساخت مشتری اخیرا ارسال شده است' ] );
        }

        set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

        $response = $this->create_customer( $user_id );

        if ( is_wp_error( $response ) ) {
            delete_transient( $lock_key );
            wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 );
        }

        wp_send_json_success( [ 'message' => 'درخواست ساخت مشتری به Odoo ارسال شد' ] );
    }

    public function maybe_create_current_customer_after_login(): void
    {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || !is_user_logged_in() ) {
            return;
        }

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        if ( !$user || in_array( 'administrator', (array) $user->roles, true ) ) {
            return;
        }

        if (
            get_user_meta( $user_id, self::USER_META_ODOO_ID, true )
            || get_user_meta( $user_id, self::USER_META_CREATE_SENT, true )
        ) {
            return;
        }

        if ( $this->get_customer_mobile_for_user( $user_id ) === '' ) {
            return;
        }

        $lock_key = 'zarsam_odoo_auto_create_customer_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }

        set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

        $response = $this->create_customer( $user_id );
        $success  = !is_wp_error( $response );
        $body     = $success ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];

        if ( $success && !empty( $body[ 'error' ] ) ) {
            $success = false;
        }

        if ( $success ) {
            update_user_meta( $user_id, self::USER_META_CREATE_SENT, current_time( 'mysql' ) );
        }

        SyncLogger::log( [
            'sync_type'     => 'customer_create_after_login',
            'product_id'    => $user_id,
            'action'        => 'create_customer',
            'request_data'  => [
                'user_id' => $user_id,
                'mobile'  => $this->sanitize_and_format_ir_mobile ($this->get_customer_mobile_for_user( $user_id )),
            ],
            'response_data' => is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : $body,
            'has_changes'   => 0,
            'message'       => $success
                ? 'ایجاد مشتری در Odoo بعد از تشخیص لاگین ارسال شد'
                : 'خطا در ایجاد مشتری در Odoo بعد از تشخیص لاگین',
        ] );
    }

    public function ajax_create_user_customer(): void
    {
        check_ajax_referer( 'zarsam_odoo_create_user_customer', 'nonce' );

        $user_id = isset( $_POST[ 'user_id' ] ) ? (int) $_POST[ 'user_id' ] : 0;

        if ( !$user_id || !current_user_can( 'edit_user', $user_id ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        if ( get_user_meta( $user_id, self::USER_META_ODOO_ID, true ) ) {
            wp_send_json_error( [ 'message' => 'این کاربر قبلا Odoo ID دارد و نیازی به ایجاد دوباره نیست.' ] );
        }

        $response = $this->create_customer( $user_id );
        $success  = !is_wp_error( $response );
        $body     = $success ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];

        if ( $success && !empty( $body[ 'error' ] ) ) {
            $success = false;
        }

        SyncLogger::log( [
            'sync_type'     => 'customer_create_manual',
            'product_id'    => $user_id,
            'action'        => 'create_customer',
            'request_data'  => [
                'user_id' => $user_id,
                'mobile'  => $this->sanitize_and_format_ir_mobile( $this->get_customer_mobile_for_user( $user_id )),
            ],
            'response_data' => is_wp_error( $response ) ? [ 'error' => $response->get_error_message() ] : $body,
            'has_changes'   => 0,
            'message'       => $success ? 'درخواست ایجاد مشتری در Odoo ارسال شد' : 'خطا در ایجاد مشتری در Odoo',
        ] );

        if ( !$success ) {
            wp_send_json_error( [
                'message' => is_wp_error( $response )
                    ? $response->get_error_message()
                    : 'Odoo خطا برگرداند. جزئیات در لاگ ثبت شد.',
            ] );
        }

        wp_send_json_success( [ 'message' => 'درخواست ایجاد کاربر در Odoo ارسال شد' ] );
    }

    private function assign_product_category( int $product_id, array $data ): void
    {
        $category_id   = $data[ 'category_id' ] ?? null;
        $category_name = $data[ 'category_name' ] ?? null;

        if ( !$category_id && !$category_name ) {
            return;
        }

        $term_id = $category_id;

        if ( !$term_id && $category_name ) {
            $term = term_exists( $category_name, 'product_cat' );

            if ( !$term ) {
                $term = wp_insert_term( $category_name, 'product_cat' );
            }

            if ( !is_wp_error( $term ) ) {
                $term_id = $term[ 'term_id' ];
            }
        }

        if ( $term_id ) {
            wp_set_object_terms( $product_id, (int) $term_id, 'product_cat' );
        }
    }

    private function assign_product_warehouse( int $product_id, array $data ): void
    {
        if ( !empty( $data[ 'warehouse_id' ] ) ) {
            update_post_meta( $product_id, '_warehouse_id', $data[ 'warehouse_id' ] );
        }
    }

    public function process_odoo_api_product( array $data ): array
    {
        $sku = $data[ 'default_code' ] ?? '';

        if ( $sku === '' ) {
            return [
                'success' => false,
                'message' => 'default_code is required',
                'status'  => 400,
            ];
        }

        $existing_id = wc_get_product_id_by_sku( $sku );
        $rates       = $this->get_zarsim_rates( 'rest_api', $existing_id ?: null, $sku );

        if ( $rates === false ) {
            SyncLogger::log( [
                'sync_type'     => 'rest_api',
                'product_id'    => $existing_id ?: null,
                'sku'           => $sku,
                'action'        => 'update_product',
                'request_data'  => $data,
                'response_data' => [ 'error' => 'rate_fetch_failed' ],
                'has_changes'   => 0,
                'message'       => 'خطا در دریافت نرخ از زرسام',
            ] );

            return [
                'success' => false,
                'message' => 'خطا در دریافت نرخ از زرسام',
                'status'  => 502,
            ];
        }

        $result = $this->zarsim_process_single_product( $data, $rates, 'rest_api' );

        return [
            'success'       => true,
            'message'       => $result[ 'created' ] ? 'محصول ایجاد شد' : 'محصول بروزرسانی شد',
            'status'        => 200,
            'product_id'    => $result[ 'woo_product_id' ],
            'odoo_id'       => $data[ 'id' ] ?? null,
            'sku'           => $sku,
            'name'          => $data[ 'name' ] ?? '',
            'created'       => $result[ 'created' ],
            'updated'       => !$result[ 'created' ],
            'weight'        => (float) ( $data[ 'zarsim_weight' ] ?? 0 ),
            'product_type'  => (string) ( $data[ 'zarsim_product_type' ] ?? '' ),
            'qty_available' => (int) ( $data[ 'qty_available' ] ?? 0 ),
            'list_price'    => (float) ( $data[ 'list_price' ] ?? 0 ),
            'final_price'   => $result[ 'final_price' ],
            'unit_price'    => $result[ 'unit_price' ],
            'category_id'   => $data[ 'category_id' ] ?? null,
            'category_name' => $data[ 'category_name' ] ?? null,
            'warehouse_id'  => $data[ 'warehouse_id' ] ?? null,
            'calculation'   => $result,
            'has_changes'   => $result[ 'has_changes' ],
        ];
    }

    function handle_zarsim_rates_request()
    {
        check_ajax_referer( 'zarsim_product_odoo', 'nonce' );

        if ( !current_user_can( 'edit_products' ) ) {
            wp_die( 'دسترسی ندارید' );
        }

        $product_sku = sanitize_text_field( $_POST[ 'product_id' ] ?? '' );
        $post_id     = isset( $_POST[ 'post_id' ] ) ? (int) $_POST[ 'post_id' ] : 0;

        $request_payload = [ 'default_code' => $product_sku ];
        $response        = $this->call_odoo( 'get_product_list', $request_payload );

        if ( is_wp_error( $response ) ) {
            SyncLogger::log( [
                'sync_type'     => 'single_product',
                'product_id'    => $post_id ?: null,
                'sku'           => $product_sku,
                'action'        => 'get_product_list',
                'request_data'  => $request_payload,
                'response_data' => [ 'error' => $response->get_error_message() ],
                'has_changes'   => 0,
                'message'       => 'خطا در اتصال به Odoo',
            ] );
            echo 'خطا در اتصال به Odoo';
            exit();
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ), true );
        $products_data = $body[ 'result' ][ 0 ] ?? null;

        if ( empty( $products_data ) ) {
            echo 'محصول در Odoo یافت نشد';
            exit();
        }

        $rates = $this->get_zarsim_rates( 'single_product', $post_id, $product_sku );
        if ( $rates === false ) {
            echo 'خطا در دریافت نرخ از زرسام';
            exit();
        }

        SyncLogger::log( [
            'sync_type'     => 'single_product',
            'product_id'    => $post_id ?: null,
            'sku'           => $product_sku,
            'action'        => 'get_product_list',
            'request_data'  => $request_payload,
            'response_data' => $products_data,
            'has_changes'   => 0,
            'message'       => 'دریافت محصول از Odoo',
        ] );

        $result = $this->zarsim_process_single_product( $products_data, $rates, 'single_product', $post_id );

        echo 'بروزرسانی قیمت: ' . number_format( $result[ 'final_price' ] );
        exit();
    }

    public function ajax_update_product_rate()
    {
        check_ajax_referer( 'zarsim_product_odoo', 'nonce' );

        if ( !current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $post_id = isset( $_POST[ 'post_id' ] ) ? (int) $_POST[ 'post_id' ] : 0;
        if ( !$post_id ) {
            wp_send_json_error( [ 'message' => 'محصول نامعتبر است' ] );
        }

        $sku      = get_post_meta( $post_id, '_sku', true );
        $raw_data = get_post_meta( $post_id, self::META_RAW_DATA, true );

        if ( empty( $raw_data ) ) {
            wp_send_json_error( [ 'message' => 'داده Odoo موجود نیست. ابتدا همگام‌سازی انجام دهید.' ] );
        }

        $odoo_data = json_decode( $raw_data, true );
        if ( empty( $odoo_data ) ) {
            wp_send_json_error( [ 'message' => 'داده Odoo نامعتبر است' ] );
        }

        $rates = $this->get_zarsim_rates( 'single_rate', $post_id, $sku );
        if ( $rates === false ) {
            wp_send_json_error( [ 'message' => 'خطا در دریافت نرخ از زرسام' ] );
        }

        $result = $this->apply_rate_to_product( $post_id, $odoo_data, $rates, 'single_rate' );

        wp_send_json_success( [
            'message'     => 'قیمت بروزرسانی شد: ' . number_format( $result[ 'final_price' ] ),
            'calculation' => $result,
            'rates'       => $rates,
        ] );
    }

    public function ajax_fetch_products()
    {
        check_ajax_referer( 'zarsim_sync_products', 'nonce' );

        if ( !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $response = $this->call_odoo( 'get_product_list', '' );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'خطا در اتصال به Odoo' ] );
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ), true );
        $products_data = $body[ 'result' ] ?? [];

        if ( empty( $products_data ) || !is_array( $products_data ) ) {
            wp_send_json_error( [ 'message' => 'محصولی یافت نشد' ] );
        }

        $rates = $this->get_zarsim_rates( 'bulk_sync' );
        if ( $rates === false ) {
            wp_send_json_error( [ 'message' => 'خطا در دریافت نرخ از زرسام' ] );
        }

        SyncLogger::log( [
            'sync_type'     => 'bulk_sync',
            'action'        => 'fetch_products',
            'request_data'  => [ 'method' => 'get_product_list' ],
            'response_data' => [ 'count' => count( $products_data ), 'rates' => $rates ],
            'has_changes'   => 0,
            'message'       => sprintf( '%d محصول از Odoo دریافت شد', count( $products_data ) ),
        ] );

        set_transient( 'zarsim_sync_products_data', $products_data, HOUR_IN_SECONDS );
        set_transient( 'zarsim_sync_rates', $rates, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'total'      => count( $products_data ),
            'batch_size' => 20,
            'message'    => sprintf( '%d محصول دریافت شد. شروع همگام‌سازی...', count( $products_data ) ),
        ] );
    }

    public function ajax_sync_products_batch()
    {
        check_ajax_referer( 'zarsim_sync_products', 'nonce' );

        if ( !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $offset     = isset( $_POST[ 'offset' ] ) ? (int) $_POST[ 'offset' ] : 0;
        $batch_size = 20;

        $products_data = get_transient( 'zarsim_sync_products_data' );
        $rates         = get_transient( 'zarsim_sync_rates' );

        if ( $products_data === false || $rates === false ) {
            wp_send_json_error( [ 'message' => 'داده‌های همگام‌سازی منقضی شده. دوباره شروع کنید.' ] );
        }

        $total   = count( $products_data );
        $batch   = array_slice( $products_data, $offset, $batch_size );
        $updated = 0;

        foreach ( $batch as $product_item ) {
            $this->zarsim_process_single_product( $product_item, $rates, 'bulk_sync' );
            $updated++;
        }

        $new_offset = $offset + $updated;
        $done       = $new_offset >= $total;

        if ( $done ) {
            delete_transient( 'zarsim_sync_products_data' );
            delete_transient( 'zarsim_sync_rates' );
        }

        wp_send_json_success( [
            'processed' => $updated,
            'offset'    => $new_offset,
            'total'     => $total,
            'done'      => $done,
            'message'   => sprintf( '%d از %d محصول بروزرسانی شد', $new_offset, $total ),
        ] );
    }

    public function register_product_meta_box(): void
    {
        add_meta_box(
            'zarsam_odoo_product_data',
            'اطلاعات Odoo / زرسام',
            [ $this, 'render_product_meta_box' ],
            'product',
            'normal',
            'high'
        );
    }

    public function render_product_meta_box( $post ): void
    {
        $sku         = get_post_meta( $post->ID, '_sku', true );
        $raw_data    = get_post_meta( $post->ID, self::META_RAW_DATA, true );
        $calculation = get_post_meta( $post->ID, self::META_CALCULATION, true );
        $last_sync   = get_post_meta( $post->ID, self::META_LAST_SYNC, true );
        $odoo_data   = $raw_data ? json_decode( $raw_data, true ) : [];
        $calc_data   = $calculation ? json_decode( $calculation, true ) : [];
        $nonce       = wp_create_nonce( 'zarsim_product_odoo' );
        ?>
        <div id="zarsam-odoo-product-box">
            <?php if ( $last_sync ) : ?>
                <p><strong>آخرین همگام‌سازی:</strong> <?php echo esc_html( $last_sync ); ?></p>
            <?php else : ?>
                <p class="description">هنوز همگام‌سازی انجام نشده. از منوی Odoo تنظیمات، همگام‌سازی را اجرا کنید.</p>
            <?php endif; ?>

            <p>
                <button type="button" class="button button-primary"
                        id="zarsam-refresh-from-odoo" <?php disabled( empty( $sku ) ); ?>>
                    دریافت از Odoo + نرخ
                </button>
                <button type="button" class="button"
                        id="zarsam-refresh-rate-only" <?php disabled( empty( $raw_data ) ); ?>>
                    بروزرسانی نرخ (فقط Rate)
                </button>
            </p>
            <p id="zarsam-odoo-status" style="margin:8px 0;"></p>

            <?php if ( !empty( $odoo_data ) ) : ?>
                <h4>داده دریافتی از Odoo</h4>
                <table class="widefat striped">
                    <tbody>
                    <?php foreach ( $odoo_data as $key => $value ) : ?>
                        <tr>
                            <th style="width:200px;"><?php echo esc_html( $key ); ?></th>
                            <td><?php echo esc_html( is_array( $value ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : (string) $value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( !empty( $calc_data ) ) : ?>
                <h4 style="margin-top:20px;">آخرین محاسبه قیمت</h4>
                <table class="widefat striped">
                    <tbody>
                    <?php if ( !empty( $calc_data[ 'calculation' ] ) ) : ?>
                        <?php foreach ( $calc_data[ 'calculation' ] as $key => $value ) : ?>
                            <tr>
                                <th style="width:200px;"><?php echo esc_html( $key ); ?></th>
                                <td><?php echo esc_html( is_numeric( $value ) ? number_format( (float) $value ) : (string) $value ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ( !empty( $calc_data[ 'rates_updated_at' ] ) ) : ?>
                        <tr>
                            <th>زمان نرخ</th>
                            <td><?php echo esc_html( $calc_data[ 'rates_updated_at' ] ); ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
            jQuery(function ($) {
                var nonce = '<?php echo esc_js( $nonce ); ?>';
                var postId = <?php echo (int) $post->ID; ?>;
                var sku = '<?php echo esc_js( (string) $sku ); ?>';
                var autoRateDone = false;

                function setStatus(text, isError) {
                    $('#zarsam-odoo-status').css('color', isError ? '#b32d2e' : '#2271b1').text(text);
                }

                function refreshRateOnly() {
                    setStatus('در حال دریافت نرخ...');
                    return $.post(ajaxurl, {
                        action: 'zarsim_update_product_rate',
                        nonce: nonce,
                        post_id: postId
                    });
                }

                $('#zarsam-refresh-rate-only').on('click', function () {
                    refreshRateOnly().done(function (response) {
                        if (response.success) {
                            setStatus(response.data.message);
                            location.reload();
                        } else {
                            setStatus(response.data.message || 'خطا', true);
                        }
                    }).fail(function () {
                        setStatus('خطا در ارتباط با سرور', true);
                    });
                });

                $('#zarsam-refresh-from-odoo').on('click', function () {
                    setStatus('در حال دریافت از Odoo...');
                    $.post(ajaxurl, {
                        action: 'get_zarsim_rates',
                        nonce: nonce,
                        post_id: postId,
                        product_id: sku
                    }, function (response) {
                        setStatus(response);
                        location.reload();
                    }).fail(function () {
                        setStatus('خطا در ارتباط با سرور', true);
                    });
                });

                <?php if ( !empty( $raw_data ) ) : ?>
                if (!autoRateDone) {
                    autoRateDone = true;
                    refreshRateOnly().done(function (response) {
                        if (response.success) {
                            setStatus(response.data.message);
                        } else {
                            setStatus(response.data.message || 'خطا در بروزرسانی خودکار نرخ', true);
                        }
                    });
                }
                <?php endif; ?>
            });
        </script>
        <?php
    }

    public function get_zarsim_rates( $sync_type = 'rate_fetch', $product_id = null, $sku = null )
    {
        $url = 'https://zarsimjewelry.com/wp-json/zarsim/v1/rates-simple';

        $response = wp_remote_get( $url, [
            'timeout'   => 15,
            'sslverify' => false,
        ] );

        if ( is_wp_error( $response ) ) {
            SyncLogger::log( [
                'sync_type'     => $sync_type,
                'product_id'    => $product_id,
                'sku'           => $sku,
                'action'        => 'get_zarsim_rates',
                'request_data'  => [ 'url' => $url ],
                'response_data' => [ 'error' => $response->get_error_message() ],
                'has_changes'   => 0,
                'message'       => 'خطا در دریافت نرخ',
            ] );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) ) {
            SyncLogger::log( [
                'sync_type'     => $sync_type,
                'product_id'    => $product_id,
                'sku'           => $sku,
                'action'        => 'get_zarsim_rates',
                'request_data'  => [ 'url' => $url ],
                'response_data' => $body,
                'has_changes'   => 0,
                'message'       => 'پاسخ نرخ خالی بود',
            ] );
            return false;
        }

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $product_id,
            'sku'           => $sku,
            'action'        => 'get_zarsim_rates',
            'request_data'  => [ 'url' => $url ],
            'response_data' => $data,
            'has_changes'   => 0,
            'message'       => 'دریافت نرخ از زرسام',
        ] );

        return $data;
    }

    public function calculateProductPrice( array $product, array $rates )
    {
        $type   = (string) ( $product[ 'zarsim_product_type' ] ?? '' );
        $weight = (float) ( $product[ 'zarsim_weight' ] ?? 0 );

        if ( $type === '' || !isset( $rates[ $type ] ) ) {
            $type   = 0;
            $weight = 0;
        }

        $unitPrice  = (float) $rates[ $type ];
        $finalPrice = $weight * $unitPrice;

        return [
            'product_id'  => $product[ 'id' ] ?? null,
            'name'        => $product[ 'name' ] ?? '',
            'type'        => $type,
            'weight'      => $weight,
            'unit_price'  => $unitPrice,
            'final_price' => $finalPrice,
        ];
    }

    public function apply_rate_to_product( int $product_id, array $odoo_data, array $rates, string $sync_type ): array
    {
        $product = wc_get_product( $product_id );
        if ( !$product ) {
            return [];
        }

        $old_values = [
            'price' => $product->get_price(),
            'stock' => $product->get_stock_quantity(),
        ];

        $result = $this->calculateProductPrice( $odoo_data, $rates );
        $product->set_price( $result[ 'final_price' ] );
        $product->set_regular_price( $result[ 'final_price' ] );
        $product->save();

        $new_values = [
            'price' => $result[ 'final_price' ],
            'stock' => $product->get_stock_quantity(),
        ];

        $this->save_product_calculation_meta( $product_id, $odoo_data, $result, $rates );

        $has_changes = ( (float) $old_values[ 'price' ] !== (float) $new_values[ 'price' ] );

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $product_id,
            'sku'           => $odoo_data[ 'default_code' ] ?? get_post_meta( $product_id, '_sku', true ),
            'action'        => 'update_price_from_rate',
            'request_data'  => [ 'rates' => $rates, 'odoo_data' => $odoo_data ],
            'response_data' => $result,
            'old_data'      => $old_values,
            'new_data'      => $new_values,
            'has_changes'   => $has_changes,
            'message'       => $has_changes ? 'قیمت تغییر کرد' : 'قیمت بدون تغییر',
        ] );

        return $result;
    }

    private function save_product_odoo_meta( int $product_id, array $odoo_data ): void
    {
        update_post_meta( $product_id, self::META_RAW_DATA, wp_json_encode( $odoo_data, JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $product_id, self::META_LAST_SYNC, current_time( 'mysql' ) );
        update_post_meta( $product_id, 'zarsam_odoo_product_id', $odoo_data[ 'id' ] ?? '' );
    }

    private function save_product_calculation_meta( int $product_id, array $odoo_data, array $calculation, array $rates ): void
    {
        update_post_meta( $product_id, self::META_CALCULATION, wp_json_encode( [
            'calculation'      => $calculation,
            'rates'            => $rates,
            'rates_updated_at' => current_time( 'mysql' ),
        ], JSON_UNESCAPED_UNICODE ) );
    }

    public function zarsim_process_single_product( $data, $rates = null, $sync_type = 'bulk_sync', $known_product_id = 0 )
    {
        $sku          = $data[ 'default_code' ] ?? '';
        $product_id   = $known_product_id ?: wc_get_product_id_by_sku( $sku );
        $was_existing = (bool) $product_id;

        if ( $rates === null ) {
            $rates = $this->get_zarsim_rates( $sync_type, $product_id ?: null, $sku );
        }

        $old_values = [ 'price' => null, 'stock' => null ];
        $result     = $this->calculateProductPrice( $data, $rates ?: [] );

        if ( $product_id ) {
            $product               = wc_get_product( $product_id );
            $old_values[ 'price' ] = $product->get_price();
            $old_values[ 'stock' ] = $product->get_stock_quantity();
            $product->set_name( $data[ 'name' ] ?? $product->get_name() );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $data[ 'qty_available' ] ?? 0 );
            $product->set_price( $result[ 'final_price' ] );
            $product->set_regular_price( $result[ 'final_price' ] );
            $product->save();
        } else {
            $new_product = new WC_Product_Simple();
            $new_product->set_name( $data[ 'name' ] ?? $sku );
            $new_product->set_sku( $sku );
            $new_product->set_status( 'draft' );
            $new_product->set_manage_stock( true );
            $new_product->set_price( $result[ 'final_price' ] );
            $new_product->set_regular_price( $result[ 'final_price' ] );
            $new_product->set_stock_quantity( $data[ 'qty_available' ] ?? 0 );
            $new_product->save();
            $product_id = $new_product->get_id();
        }

        $this->assign_product_category( $product_id, $data );
        $this->assign_product_warehouse( $product_id, $data );
        $this->save_product_odoo_meta( $product_id, $data );
        $this->save_product_calculation_meta( $product_id, $data, $result, $rates ?: [] );

        $new_values = [
            'price' => $result[ 'final_price' ],
            'stock' => $data[ 'qty_available' ] ?? 0,
        ];

        $has_changes = ( (float) $old_values[ 'price' ] !== (float) $new_values[ 'price' ] )
            || ( (int) $old_values[ 'stock' ] !== (int) $new_values[ 'stock' ] );

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $product_id,
            'sku'           => $sku,
            'action'        => $was_existing ? 'update_product' : 'create_product',
            'request_data'  => $data,
            'response_data' => $result,
            'old_data'      => $old_values,
            'new_data'      => $new_values,
            'has_changes'   => $has_changes,
            'message'       => $was_existing
                ? ( $has_changes ? 'محصول بروزرسانی شد' : 'بدون تغییر' )
                : 'محصول جدید ایجاد شد',
        ] );

        return array_merge( $result, [
            'woo_product_id' => $product_id,
            'created'        => !$was_existing,
            'has_changes'    => $has_changes,
        ] );
    }

    public function ajax_fetch_customers(): void
    {
        check_ajax_referer( 'zarsim_sync_customers', 'nonce' );

        if ( !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $response = $this->call_odoo( 'get_customer_list', null );
        if ( is_wp_error( $response ) ) {
            SyncLogger::log( [
                'sync_type'     => 'customer_bulk_sync',
                'action'        => 'fetch_customers',
                'request_data'  => [ 'method' => 'get_customer_list' ],
                'response_data' => [ 'error' => $response->get_error_message() ],
                'has_changes'   => 0,
                'message'       => 'خطا در اتصال به Odoo برای دریافت مشتریان',
            ] );
            wp_send_json_error( [ 'message' => 'خطا در اتصال به Odoo' ] );
        }

        $body           = json_decode( wp_remote_retrieve_body( $response ), true );
        $customers_data = $body[ 'result' ] ?? [];

        if ( empty( $customers_data ) || !is_array( $customers_data ) ) {
            wp_send_json_error( [ 'message' => 'مشتری‌ای یافت نشد' ] );
        }

        SyncLogger::log( [
            'sync_type'     => 'customer_bulk_sync',
            'action'        => 'fetch_customers',
            'request_data'  => [ 'method' => 'get_customer_list' ],
            'response_data' => [ 'count' => count( $customers_data ) ],
            'has_changes'   => 0,
            'message'       => sprintf( '%d مشتری از Odoo دریافت شد', count( $customers_data ) ),
        ] );

        set_transient( 'zarsim_sync_customers_data', $customers_data, HOUR_IN_SECONDS );

        wp_send_json_success( [
            'total'      => count( $customers_data ),
            'batch_size' => 20,
            'message'    => sprintf( '%d مشتری دریافت شد. شروع همگام‌سازی...', count( $customers_data ) ),
        ] );
    }

    public function ajax_sync_customers_batch(): void
    {
        check_ajax_referer( 'zarsim_sync_customers', 'nonce' );

        if ( !current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $offset     = isset( $_POST[ 'offset' ] ) ? (int) $_POST[ 'offset' ] : 0;
        $batch_size = 20;

        $customers_data = get_transient( 'zarsim_sync_customers_data' );
        if ( $customers_data === false ) {
            wp_send_json_error( [ 'message' => 'داده‌های همگام‌سازی منقضی شده. دوباره شروع کنید.' ] );
        }

        $total   = count( $customers_data );
        $batch   = array_slice( $customers_data, $offset, $batch_size );
        $updated = 0;

        foreach ( $batch as $customer_item ) {
            if ( is_array( $customer_item ) ) {
                $this->process_odoo_customer( $customer_item, 'customer_bulk_sync' );
                $updated++;
            }
        }

        $new_offset = $offset + $updated;
        $done       = $new_offset >= $total;

        if ( $done ) {
            delete_transient( 'zarsim_sync_customers_data' );
        }

        wp_send_json_success( [
            'processed' => $updated,
            'offset'    => $new_offset,
            'total'     => $total,
            'done'      => $done,
            'message'   => sprintf( '%d از %d مشتری بروزرسانی شد', $new_offset, $total ),
        ] );
    }

    public function ajax_sync_single_customer(): void
    {
        check_ajax_referer( 'zarsim_sync_customers', 'nonce' );

        $user_id = isset( $_POST[ 'user_id' ] ) ? (int) $_POST[ 'user_id' ] : 0;
        if ( !$user_id || !current_user_can( 'edit_user', $user_id ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $odoo_id = isset( $_POST[ 'odoo_id' ] ) ? (int) $_POST[ 'odoo_id' ] : (int) get_user_meta( $user_id, self::USER_META_ODOO_ID, true );
        if ( !$odoo_id ) {
            wp_send_json_error( [ 'message' => 'شناسه مشتری Odoo برای این کاربر ثبت نشده است.' ] );
        }

        $request_payload = [ 'id' => $odoo_id ];
        $response        = $this->call_odoo( 'get_customer_list', $request_payload );

        if ( is_wp_error( $response ) ) {
            SyncLogger::log( [
                'sync_type'     => 'customer_single_sync',
                'product_id'    => $user_id,
                'sku'           => (string) $odoo_id,
                'action'        => 'get_customer_list',
                'request_data'  => $request_payload,
                'response_data' => [ 'error' => $response->get_error_message() ],
                'has_changes'   => 0,
                'message'       => 'خطا در اتصال به Odoo برای مشتری',
            ] );
            wp_send_json_error( [ 'message' => 'خطا در اتصال به Odoo' ] );
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ), true );
        $customer_data = $body[ 'result' ][ 0 ] ?? null;

        if ( empty( $customer_data ) || !is_array( $customer_data ) ) {
            wp_send_json_error( [ 'message' => 'مشتری در Odoo یافت نشد' ] );
        }

        $result = $this->process_odoo_customer( $customer_data, 'customer_single_sync', $user_id );

        if ( empty( $result[ 'success' ] ) ) {
            wp_send_json_error( [ 'message' => $result[ 'message' ] ?? 'خطا در همگام‌سازی مشتری' ] );
        }

        wp_send_json_success( [
            'message' => $result[ 'created' ] ? 'مشتری ایجاد و همگام‌سازی شد' : 'مشتری همگام‌سازی شد',
            'user_id' => $result[ 'user_id' ],
        ] );
    }

    public function process_odoo_customer( array $data, string $sync_type = 'customer_bulk_sync', int $known_user_id = 0 ): array
    {
        $odoo_id = isset( $data[ 'id' ] ) ? (int) $data[ 'id' ] : 0;
        if ( !$odoo_id ) {
            SyncLogger::log( [
                'sync_type'    => $sync_type,
                'action'       => 'sync_customer',
                'request_data' => $data,
                'has_changes'  => 0,
                'message'      => 'شناسه مشتری Odoo موجود نیست',
            ] );

            return [
                'success' => false,
                'message' => 'شناسه مشتری Odoo موجود نیست',
            ];
        }

        $user_id      = $known_user_id ?: $this->find_user_id_for_odoo_customer( $data );
        $was_existing = (bool) $user_id;
        $old_values   = $this->get_customer_sync_snapshot( $user_id );

        if ( !$user_id ) {
            $user_id = $this->create_wp_customer_from_odoo( $data );
            if ( is_wp_error( $user_id ) ) {
                SyncLogger::log( [
                    'sync_type'     => $sync_type,
                    'sku'           => (string) $odoo_id,
                    'action'        => 'create_customer',
                    'request_data'  => $data,
                    'response_data' => [ 'error' => $user_id->get_error_message() ],
                    'has_changes'   => 0,
                    'message'       => 'خطا در ایجاد مشتری وردپرس',
                ] );

                return [
                    'success' => false,
                    'message' => $user_id->get_error_message(),
                ];
            }
        }

        $this->update_wp_customer_from_odoo( $user_id, $data );
        $wallet_result          = $this->sync_customer_wallet( $user_id, $data );
        $new_values             = $this->get_customer_sync_snapshot( $user_id );
        $new_values[ 'wallet' ] = $wallet_result[ 'new_balance' ] ?? $new_values[ 'wallet' ];

        $has_changes = $was_existing ? ( $old_values != $new_values ) : true;

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $user_id,
            'sku'           => (string) $odoo_id,
            'action'        => $was_existing ? 'update_customer' : 'create_customer',
            'request_data'  => $data,
            'response_data' => [ 'user_id' => $user_id, 'wallet' => $wallet_result ],
            'old_data'      => $old_values,
            'new_data'      => $new_values,
            'has_changes'   => $has_changes,
            'message'       => $was_existing
                ? ( $has_changes ? 'مشتری بروزرسانی شد' : 'مشتری بدون تغییر بود' )
                : 'مشتری جدید از Odoo ایجاد شد',
        ] );

        return [
            'success'     => true,
            'user_id'     => $user_id,
            'odoo_id'     => $odoo_id,
            'created'     => !$was_existing,
            'has_changes' => $has_changes,
        ];
    }

    public function sync_customer_wallet_after_login( string $user_login, \WP_User $user ): void
    {
        $this->sync_customer_wallet_from_odoo_user( (int) $user->ID, 'customer_wallet_login' );
    }

    public function sync_current_customer_wallet_on_checkout(): void
    {
        if ( !is_user_logged_in() ) {
            return;
        }

        $this->sync_customer_wallet_from_odoo_user( get_current_user_id(), 'customer_wallet_checkout' );
    }

    public function validate_cart_with_odoo_before_checkout( array $data, \WP_Error $errors ): void
    {
        if ( !function_exists( 'WC' ) || !WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $rates = $this->get_zarsim_rates( 'checkout_validation' );
        if ( $rates === false ) {
            $errors->add(
                'zarsam_rates_unavailable',
                'در حال حاضر امکان بررسی قیمت لحظه‌ای وجود ندارد. لطفا چند دقیقه دیگر دوباره تلاش کنید.'
            );
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item[ 'data' ] ?? null;
            if ( !$product || !method_exists( $product, 'get_sku' ) ) {
                continue;
            }

            $sku        = (string) $product->get_sku();
            $product_id = (int) $product->get_id();

            if ( $sku === '' ) {
                $errors->add(
                    'zarsam_odoo_missing_sku',
                    sprintf( 'محصول «%s» شناسه SKU ندارد و امکان بررسی با Odoo وجود ندارد.', $product->get_name() )
                );
                continue;
            }

            $request_payload = [ 'default_code' => $sku ];
            $response        = $this->call_odoo( 'get_product_list', $request_payload );

            if ( is_wp_error( $response ) ) {
                SyncLogger::log( [
                    'sync_type'     => 'checkout_validation',
                    'product_id'    => $product_id,
                    'sku'           => $sku,
                    'action'        => 'get_product_list',
                    'request_data'  => $request_payload,
                    'response_data' => [ 'error' => $response->get_error_message() ],
                    'has_changes'   => 0,
                    'message'       => 'خطا در اتصال به Odoo هنگام پرداخت',
                ] );

                $errors->add(
                    'zarsam_odoo_unavailable',
                    'در حال حاضر ارتباط با Odoo برقرار نیست و سفارش ثبت نمی‌شود. لطفا چند دقیقه دیگر دوباره تلاش کنید.'
                );
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code( $response );
            $body        = json_decode( wp_remote_retrieve_body( $response ), true );
            $odoo_data   = $body[ 'result' ][ 0 ] ?? null;

            if ( $status_code < 200 || $status_code >= 300 || !empty( $body[ 'error' ] ) || empty( $odoo_data ) || !is_array( $odoo_data ) ) {
                SyncLogger::log( [
                    'sync_type'     => 'checkout_validation',
                    'product_id'    => $product_id,
                    'sku'           => $sku,
                    'action'        => 'get_product_list',
                    'request_data'  => $request_payload,
                    'response_data' => $body ?: wp_remote_retrieve_body( $response ),
                    'has_changes'   => 0,
                    'message'       => 'پاسخ نامعتبر از Odoo هنگام پرداخت',
                ] );

                $errors->add(
                    'zarsam_odoo_invalid_response',
                    sprintf( 'امکان بررسی لحظه‌ای محصول «%s» در Odoo وجود ندارد و سفارش ثبت نمی‌شود.', $product->get_name() )
                );
                continue;
            }

            $calculation   = $this->calculateProductPrice( $odoo_data, $rates );
            $live_price    = (float) ( $calculation[ 'final_price' ] ?? 0 );
            $woo_price     = (float) $product->get_price();
            $live_stock    = (int) ( $odoo_data[ 'qty_available' ] ?? 0 );
            $cart_qty      = (int) ( $cart_item[ 'quantity' ] ?? 0 );
            $price_changed = abs( $live_price - $woo_price ) > 0.01;
            $stock_changed = $live_stock < $cart_qty || $live_stock !== (int) $product->get_stock_quantity();

            if ( $price_changed || $stock_changed ) {
                $this->zarsim_process_single_product( $odoo_data, $rates, 'checkout_validation', $product_id );

                $errors->add(
                    'zarsam_odoo_product_changed',
                    sprintf( 'قیمت یا موجودی محصول «%s» تغییر کرده است. محصول به‌روزرسانی شد؛ لطفا سبد خرید را دوباره بررسی کنید.', $product->get_name() )
                );
            }
        }
    }

    public function sync_order_customer_wallet_after_payment( int $order_id ): void
    {
        $order = wc_get_order( $order_id );
        if ( !$order || !$order->get_customer_id() ) {
            return;
        }

        $this->sync_customer_wallet_from_odoo_user( (int) $order->get_customer_id(), 'customer_wallet_payment', true );
    }

    private function sync_customer_wallet_from_odoo_user( int $user_id, string $sync_type, bool $force = false ): array
    {
        if ( !$user_id ) {
            return [ 'success' => false, 'message' => 'کاربر نامعتبر است' ];
        }

        $lock_key = 'zarsam_odoo_wallet_sync_' . $sync_type . '_' . $user_id;
        if ( !$force && get_transient( $lock_key ) ) {
            return [ 'success' => true, 'message' => 'همگام‌سازی اخیرا انجام شده است' ];
        }

        set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

        $customer_data = $this->fetch_odoo_customer_for_user( $user_id, $sync_type );
        if ( empty( $customer_data ) || !is_array( $customer_data ) ) {
            return [ 'success' => false, 'message' => 'مشتری در Odoo یافت نشد' ];
        }

        return $this->process_odoo_customer( $customer_data, $sync_type, $user_id );
    }

    private function fetch_odoo_customer_for_user( int $user_id, string $sync_type ): array
    {
        $odoo_id = (int) get_user_meta( $user_id, self::USER_META_ODOO_ID, true );

        if ( $odoo_id ) {
            $request_payload = [ 'id' => $odoo_id ];
            $response        = $this->call_odoo( 'get_customer_list', $request_payload );

            if ( is_wp_error( $response ) ) {
                SyncLogger::log( [
                    'sync_type'     => $sync_type,
                    'product_id'    => $user_id,
                    'sku'           => (string) $odoo_id,
                    'action'        => 'fetch_wallet_customer',
                    'request_data'  => $request_payload,
                    'response_data' => [ 'error' => $response->get_error_message() ],
                    'has_changes'   => 0,
                    'message'       => 'خطا در دریافت مشتری برای بروزرسانی کیف پول',
                ] );

                return [];
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return is_array( $body[ 'result' ][ 0 ] ?? null ) ? $body[ 'result' ][ 0 ] : [];
        }

        $mobile = $this->sanitize_and_format_ir_mobile( $this->get_customer_mobile_for_user( $user_id ));
        if ( $mobile === '' ) {
            SyncLogger::log( [
                'sync_type'   => $sync_type,
                'product_id'  => $user_id,
                'action'      => 'fetch_wallet_customer',
                'has_changes' => 0,
                'message'     => 'برای کاربر شماره موبایل یا Odoo ID ثبت نشده است',
            ] );

            return [];
        }

        $response = $this->call_odoo( 'get_customer_list', null );
        if ( is_wp_error( $response ) ) {
            SyncLogger::log( [
                'sync_type'     => $sync_type,
                'product_id'    => $user_id,
                'action'        => 'fetch_wallet_customer_by_mobile',
                'request_data'  => [ 'mobile' => $mobile ],
                'response_data' => [ 'error' => $response->get_error_message() ],
                'has_changes'   => 0,
                'message'       => 'خطا در دریافت لیست مشتریان برای بروزرسانی کیف پول',
            ] );

            return [];
        }

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $customers = $body[ 'result' ] ?? [];
        $variants  = $this->get_customer_mobile_variants( $mobile );

        if ( !is_array( $customers ) ) {
            return [];
        }

        foreach ( $customers as $customer ) {
            if ( !is_array( $customer ) ) {
                continue;
            }

            $customer_mobile = $this->normalize_odoo_customer_value( $customer[ 'customer_mobile' ] ?? '' );
            if ( $customer_mobile !== '' && array_intersect( $variants, $this->get_customer_mobile_variants( $customer_mobile ) ) ) {
                return $customer;
            }
        }

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $user_id,
            'action'        => 'fetch_wallet_customer_by_mobile',
            'request_data'  => [ 'mobile' => $mobile ],
            'response_data' => [ 'count' => count( $customers ) ],
            'has_changes'   => 0,
            'message'       => 'مشتری با شماره موبایل کاربر در Odoo یافت نشد',
        ] );

        return [];
    }

    private function find_user_id_for_odoo_customer( array $data ): int
    {
        $odoo_id = isset( $data[ 'id' ] ) ? (int) $data[ 'id' ] : 0;
        if ( $odoo_id ) {
            $users = get_users( [
                'number'     => 1,
                'fields'     => 'ids',
                'meta_key'   => self::USER_META_ODOO_ID,
                'meta_value' => $odoo_id,
            ] );

            if ( !empty( $users[ 0 ] ) ) {
                return (int) $users[ 0 ];
            }
        }

        $mobile = $this->normalize_odoo_customer_value( $data[ 'customer_mobile' ] ?? '' );
        if ( $mobile !== '' ) {
            foreach ( $this->get_customer_mobile_variants( $mobile ) as $mobile_variant ) {
                $user = get_user_by( 'login', $mobile_variant );
                if ( $user ) {
                    return (int) $user->ID;
                }
            }

            $users = get_users( [
                'number'     => 1,
                'fields'     => 'ids',
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key'     => 'billing_phone',
                        'value'   => $this->get_customer_mobile_variants( $mobile ),
                        'compare' => 'IN',
                    ],
                    [
                        'key'     => 'digits_phone',
                        'value'   => $this->get_customer_mobile_variants( $mobile ),
                        'compare' => 'IN',
                    ],
                    [
                        'key'     => 'digits_phone_no',
                        'value'   => $this->get_customer_mobile_variants( $mobile ),
                        'compare' => 'IN',
                    ],
                    [
                        'key'     => 'digits_mobile',
                        'value'   => $this->get_customer_mobile_variants( $mobile ),
                        'compare' => 'IN',
                    ],
                    [
                        'key'     => 'phone_number',
                        'value'   => $this->get_customer_mobile_variants( $mobile ),
                        'compare' => 'IN',
                    ],
                ],
            ] );

            if ( !empty( $users[ 0 ] ) ) {
                return (int) $users[ 0 ];
            }
        }

        return 0;
    }

    private function create_wp_customer_from_odoo( array $data )
    {
        $odoo_id = (int) ( $data[ 'id' ] ?? 0 );
        $name    = $this->normalize_odoo_customer_value( $data[ 'customer_name' ] ?? '' );
        $mobile  = $this->normalize_odoo_customer_value( $data[ 'customer_mobile' ] ?? '' );
        $name    = $name !== '' ? $name : 'Odoo Customer ' . $odoo_id;
        $login   = $mobile !== ''
            ? $this->normalize_customer_mobile_for_login( $mobile )
            : sanitize_user( 'odoo_customer_' . $odoo_id, true );

        if ( username_exists( $login ) ) {
            $login .= '_' . wp_generate_password( 4, false, false );
        }

        $this->is_importing_odoo_customer = true;
        $user_id                          = wp_insert_user( [
            'user_login'   => $login,
            'user_pass'    => wp_generate_password( 24, true ),
            'display_name' => $name,
            'nickname'     => $name,
            'first_name'   => $name,
            'role'         => 'customer',
        ] );
        $this->is_importing_odoo_customer = false;

        return $user_id;
    }

    private function update_wp_customer_from_odoo( int $user_id, array $data ): void
    {
        $name = $this->normalize_odoo_customer_value( $data[ 'customer_name' ] ?? '' );
        if ( $name !== '' ) {
            wp_update_user( [
                'ID'           => $user_id,
                'display_name' => $name,
                'nickname'     => $name,
                'first_name'   => $name,
            ] );
            update_user_meta( $user_id, 'billing_first_name', $name );
        }

        $mobile = $this->normalize_odoo_customer_value( $data[ 'customer_mobile' ] ?? '' );
        if ( $mobile !== '' ) {
            update_user_meta( $user_id, 'billing_phone', $mobile );
        }

        update_user_meta( $user_id, self::USER_META_ODOO_ID, (int) $data[ 'id' ] );
        update_user_meta( $user_id, self::USER_META_RAW_DATA, wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
        update_user_meta( $user_id, self::USER_META_LAST_SYNC, current_time( 'mysql' ) );
        update_user_meta( $user_id, self::USER_META_FROM_ODOO, 1 );
        update_user_meta( $user_id, 'zarsam_customer_birthdate', $this->normalize_odoo_customer_value( $data[ 'customer_birthdate' ] ?? '' ) );
        update_user_meta( $user_id, 'zarsam_customer_partner_birthdate', $this->normalize_odoo_customer_value( $data[ 'customer_partner_birthdate' ] ?? '' ) );
        update_user_meta( $user_id, 'zarsam_customer_wedding_date', $this->normalize_odoo_customer_value( $data[ 'customer_wedding_date' ] ?? '' ) );
        update_user_meta( $user_id, 'zarsam_dobare_wallet', $this->normalize_odoo_customer_value( $data[ 'dobare_wallet' ] ?? '' ) );
    }

    private function sync_customer_wallet( int $user_id, array $data ): array
    {
        if ( !array_key_exists( 'customer_wallet', $data ) || $data[ 'customer_wallet' ] === false || $data[ 'customer_wallet' ] === null ) {
            return [ 'updated' => false, 'message' => 'موجودی کیف پول در پاسخ Odoo وجود ندارد' ];
        }

        $target_balance = (float) $data[ 'customer_wallet' ] / 10;
        $old_balance    = (float) get_user_meta( $user_id, '_woo_wallet_balance', true );
        $method         = 'user_meta';
        $description    = sprintf(
            'بروزرسانی کیف پول از Odoo: %s به %s',
            number_format( $old_balance ),
            number_format( $target_balance )
        );

        if ( function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet ) ) {
            $wallet = woo_wallet()->wallet;
            if ( method_exists( $wallet, 'get_wallet_balance' ) ) {
                $old_balance = (float) $wallet->get_wallet_balance( $user_id, 'edit' );
                $description = sprintf(
                    'بروزرسانی کیف پول از Odoo: %s به %s',
                    number_format( $old_balance ),
                    number_format( $target_balance )
                );
            }

            $diff = $target_balance - $old_balance;
            if ( abs( $diff ) > 0.0001 ) {
                if ( $diff > 0 && method_exists( $wallet, 'credit' ) ) {
                    $wallet->credit( $user_id, $diff, $description );
                    $method = 'woo_wallet_credit';
                } elseif ( $diff < 0 && method_exists( $wallet, 'debit' ) ) {
                    $wallet->debit( $user_id, abs( $diff ), $description );
                    $method = 'woo_wallet_debit';
                } else {
                    update_user_meta( $user_id, '_woo_wallet_balance', $target_balance );
                }
            }
        } else {
            update_user_meta( $user_id, '_woo_wallet_balance', $target_balance );
        }

        update_user_meta( $user_id, 'zarsam_odoo_customer_wallet', $target_balance );

        return [
            'updated'     => abs( $target_balance - $old_balance ) > 0.0001,
            'old_balance' => $old_balance,
            'new_balance' => $target_balance,
            'difference'  => $target_balance - $old_balance,
            'method'      => $method,
        ];
    }

    private function get_customer_sync_snapshot( int $user_id ): array
    {
        if ( !$user_id ) {
            return [
                'name'    => null,
                'mobile'  => null,
                'odoo_id' => null,
                'wallet'  => null,
            ];
        }

        $user = get_userdata( $user_id );

        return [
            'name'    => $user ? $user->display_name : '',
            'mobile'  => get_user_meta( $user_id, 'billing_phone', true ),
            'odoo_id' => get_user_meta( $user_id, self::USER_META_ODOO_ID, true ),
            'wallet'  => get_user_meta( $user_id, 'zarsam_odoo_customer_wallet', true ),
        ];
    }

    private function normalize_odoo_customer_value( $value ): string
    {
        if ( $value === false || $value === null ) {
            return '';
        }

        return sanitize_text_field( (string) $value );
    }

    private function get_customer_mobile_for_user( int $user_id ): string
    {
        $user       = get_userdata( $user_id );
        $candidates = [
            get_user_meta( $user_id, 'billing_phone', true ),
            get_user_meta( $user_id, 'digits_phone', true ),
            get_user_meta( $user_id, 'digits_phone_no', true ),
            get_user_meta( $user_id, 'digits_mobile', true ),
            get_user_meta( $user_id, 'phone_number', true ),
            $user ? $user->user_login : '',
        ];

        foreach ( $candidates as $candidate ) {
            $mobile = $this->normalize_odoo_customer_value( $candidate );
            if ( $mobile !== '' ) {
                return $mobile;
            }
        }

        return '';
    }

    private function normalize_customer_mobile_for_login( string $mobile ): string
    {
        $digits = preg_replace( '/\D+/', '', $mobile );

        if ( $digits === '' ) {
            return sanitize_user( $mobile, true );
        }

        if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
            return '0' . substr( $digits, 2 );
        }

        return $digits;
    }

    private function get_customer_mobile_variants( string $mobile ): array
    {
        $mobile = trim( $mobile );
        $digits = preg_replace( '/\D+/', '', $mobile );
        $values = array_filter( [
            $mobile,
            $digits,
            $this->normalize_customer_mobile_for_login( $mobile ),
        ] );

        if ( $digits !== '' ) {
            if ( strpos( $digits, '0' ) === 0 && strlen( $digits ) === 11 ) {
                $without_zero = substr( $digits, 1 );
                $values[]     = '98' . $without_zero;
                $values[]     = '+98' . $without_zero;
            }

            if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
                $without_country = substr( $digits, 2 );
                $values[]        = '0' . $without_country;
                $values[]        = $without_country;
                $values[]        = '+' . $digits;
            }
        }

        return array_values( array_unique( $values ) );
    }

    public function add_customer_sync_user_column( array $columns ): array
    {
        $columns[ 'zarsam_odoo_customer_sync' ] = 'Odoo';
        return $columns;
    }

    public function render_customer_sync_user_column( $output, string $column_name, int $user_id )
    {
        if ( $column_name !== 'zarsam_odoo_customer_sync' ) {
            return $output;
        }

        $odoo_id = (int) get_user_meta( $user_id, self::USER_META_ODOO_ID, true );
        $last    = get_user_meta( $user_id, self::USER_META_LAST_SYNC, true );

        ob_start();
        ?>
        <button type="button"
                class="button button-small zarsam-sync-customer"
                data-user-id="<?php echo (int) $user_id; ?>"
                data-odoo-id="<?php echo (int) $odoo_id; ?>"
            <?php disabled( !$odoo_id ); ?>>
            سینک Odoo
        </button>
        <?php if ( !$odoo_id ) : ?>
        <button type="button"
                class="button button-small zarsam-create-customer-odoo"
                data-user-id="<?php echo (int) $user_id; ?>">
            ایجاد کاربر در Odoo
        </button>
    <?php endif; ?>
        <span class="zarsam-sync-customer-status" style="display:block;margin-top:4px;">
            <?php echo $last ? esc_html( $last ) : ( $odoo_id ? '' : 'بدون Odoo ID' ); ?>
        </span>
        <?php
        return ob_get_clean();
    }

    public function render_customer_sync_profile_section( $user ): void
    {
        if ( !current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }

        $odoo_id   = get_user_meta( $user->ID, self::USER_META_ODOO_ID, true );
        $from_odoo = get_user_meta( $user->ID, self::USER_META_FROM_ODOO, true );
        $last_sync = get_user_meta( $user->ID, self::USER_META_LAST_SYNC, true );
        $wallet    = get_user_meta( $user->ID, 'zarsam_odoo_customer_wallet', true );
        ?>
        <h2>همگام‌سازی مشتری Odoo</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="zarsam_odoo_customer_id">شناسه مشتری Odoo</label></th>
                <td>
                    <input type="number" name="zarsam_odoo_customer_id" id="zarsam_odoo_customer_id"
                           value="<?php echo esc_attr( $odoo_id ); ?>" class="regular-text">
                    <p class="description">برای سینک تکی، شناسه مشتری در Odoo لازم است.</p>
                </td>
            </tr>
            <tr>
                <th>وضعیت</th>
                <td>
                    <p>ایجاد شده از Odoo: <?php echo $from_odoo ? 'بله' : 'خیر'; ?></p>
                    <p>آخرین همگام‌سازی: <?php echo $last_sync ? esc_html( $last_sync ) : 'انجام نشده'; ?></p>
                    <p>موجودی کیف پول
                        Odoo: <?php echo $wallet !== '' ? esc_html( number_format( (float) $wallet ) ) : 'ثبت نشده'; ?></p>
                    <button type="button"
                            class="button zarsam-sync-customer"
                            data-user-id="<?php echo (int) $user->ID; ?>"
                            data-odoo-id="<?php echo esc_attr( $odoo_id ); ?>">
                        همگام‌سازی این مشتری از Odoo
                    </button>
                    <?php if ( !$odoo_id ) : ?>
                        <button type="button"
                                class="button zarsam-create-customer-odoo"
                                data-user-id="<?php echo (int) $user->ID; ?>">
                            ایجاد کاربر در Odoo
                        </button>
                    <?php endif; ?>
                    <span class="zarsam-sync-customer-status" style="margin-right:8px;"></span>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_customer_sync_profile_fields( int $user_id ): void
    {
        if ( !current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        if ( isset( $_POST[ 'zarsam_odoo_customer_id' ] ) ) {
            update_user_meta( $user_id, self::USER_META_ODOO_ID, absint( $_POST[ 'zarsam_odoo_customer_id' ] ) );
        }
    }

    public function render_customer_sync_admin_script(): void
    {
        if ( !current_user_can( 'list_users' ) ) {
            return;
        }
        ?>
        <script>
            jQuery(function ($) {
                $(document).on('click', '.zarsam-sync-customer', function () {
                    var $button = $(this);
                    var $status = $button.siblings('.zarsam-sync-customer-status');
                    var odooId = $button.data('odoo-id') || $('#zarsam_odoo_customer_id').val();

                    if (!odooId) {
                        $status.css('color', '#b32d2e').text('ابتدا شناسه Odoo را ثبت کنید.');
                        return;
                    }

                    $button.prop('disabled', true);
                    $status.css('color', '#2271b1').text('در حال همگام‌سازی...');

                    $.post(ajaxurl, {
                        action: 'zarsim_sync_single_customer',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'zarsim_sync_customers' ) ); ?>',
                        user_id: $button.data('user-id'),
                        odoo_id: odooId
                    }).done(function (response) {
                        if (response.success) {
                            $status.css('color', '#008a20').text(response.data.message);
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 800);
                        } else {
                            $status.css('color', '#b32d2e').text(response.data.message || 'خطا در همگام‌سازی');
                            $button.prop('disabled', false);
                        }
                    }).fail(function () {
                        $status.css('color', '#b32d2e').text('خطا در ارتباط با سرور');
                        $button.prop('disabled', false);
                    });
                });

                $(document).on('click', '.zarsam-create-customer-odoo', function () {
                    var $button = $(this);
                    var $status = $button.siblings('.zarsam-sync-customer-status');

                    $button.prop('disabled', true);
                    $status.css('color', '#2271b1').text('در حال ایجاد کاربر در Odoo...');

                    $.post(ajaxurl, {
                        action: 'zarsam_odoo_create_user_customer',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'zarsam_odoo_create_user_customer' ) ); ?>',
                        user_id: $button.data('user-id')
                    }).done(function (response) {
                        if (response.success) {
                            $status.css('color', '#008a20').text(response.data.message);
                        } else {
                            $status.css('color', '#b32d2e').text(response.data.message || 'خطا در ایجاد کاربر در Odoo');
                        }
                        $button.prop('disabled', false);
                    }).fail(function () {
                        $status.css('color', '#b32d2e').text('خطا در ارتباط با سرور');
                        $button.prop('disabled', false);
                    });
                });
            });
        </script>
        <?php
    }

    public function menu()
    {
        add_menu_page(
            'تنظیمات Odoo',
            'Odoo تنظیمات',
            'manage_options',
            'odoo-sync',
            [ $this, 'settings_page' ]
        );

        add_submenu_page(
            'odoo-sync',
            'لاگ همگام‌سازی',
            'لاگ همگام‌سازی',
            'manage_options',
            'zarsam-odoo-logs',
            [ $this, 'logs_page' ]
        );
    }

    public function export_logs(): void
    {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی ندارید' );
        }

        check_admin_referer( 'zarsam_odoo_export_logs' );

        $filters = [];
        if ( !empty( $_GET[ 'has_changes' ] ) ) {
            $filters[ 'has_changes' ] = 1;
        }
        if ( !empty( $_GET[ 'sync_type' ] ) ) {
            $filters[ 'sync_type' ] = sanitize_text_field( wp_unslash( $_GET[ 'sync_type' ] ) );
        }
        if ( !empty( $_GET[ 'sku' ] ) ) {
            $filters[ 'sku' ] = sanitize_text_field( wp_unslash( $_GET[ 'sku' ] ) );
        }

        SyncLogger::export_csv( $filters );
    }

    public function logs_page(): void
    {
        $page     = isset( $_GET[ 'paged' ] ) ? max( 1, (int) $_GET[ 'paged' ] ) : 1;
        $per_page = 20;
        $filters  = [];

        if ( !empty( $_GET[ 'has_changes' ] ) ) {
            $filters[ 'has_changes' ] = 1;
        }
        if ( !empty( $_GET[ 'sync_type' ] ) ) {
            $filters[ 'sync_type' ] = sanitize_text_field( wp_unslash( $_GET[ 'sync_type' ] ) );
        }
        if ( !empty( $_GET[ 'sku' ] ) ) {
            $filters[ 'sku' ] = sanitize_text_field( wp_unslash( $_GET[ 'sku' ] ) );
        }

        $result     = SyncLogger::get_logs( $page, $per_page, $filters );
        $export_url = wp_nonce_url(
            add_query_arg( array_merge( [ 'action' => 'zarsam_odoo_export_logs' ], $filters ), admin_url( 'admin-post.php' ) ),
            'zarsam_odoo_export_logs'
        );
        $base_url   = add_query_arg( array_merge( [ 'page' => 'zarsam-odoo-logs' ], $filters ), admin_url( 'admin.php' ) );
        ?>
        <div class="wrap">
            <h1>لاگ همگام‌سازی Odoo</h1>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="zarsam-odoo-logs">
                <select name="sync_type">
                    <option value="">همه انواع</option>
                    <option value="bulk_sync" <?php selected( $filters[ 'sync_type' ] ?? '', 'bulk_sync' ); ?>>
                        همگام‌سازی گروهی
                    </option>
                    <option value="single_product" <?php selected( $filters[ 'sync_type' ] ?? '', 'single_product' ); ?>>
                        محصول تکی (Odoo)
                    </option>
                    <option value="single_rate" <?php selected( $filters[ 'sync_type' ] ?? '', 'single_rate' ); ?>>محصول
                        تکی (Rate)
                    </option>
                    <option value="customer_bulk_sync" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_bulk_sync' ); ?>>
                        مشتریان گروهی
                    </option>
                    <option value="customer_single_sync" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_single_sync' ); ?>>
                        مشتری تکی
                    </option>
                    <option value="customer_create_manual" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_create_manual' ); ?>>
                        ایجاد دستی مشتری در Odoo
                    </option>
                    <option value="customer_create_after_login" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_create_after_login' ); ?>>
                        ایجاد مشتری بعد از لاگین
                    </option>
                    <option value="customer_wallet_login" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_wallet_login' ); ?>>
                        کیف پول بعد از ورود
                    </option>
                    <option value="customer_wallet_checkout" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_wallet_checkout' ); ?>>
                        کیف پول صفحه پرداخت
                    </option>
                    <option value="customer_wallet_payment" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_wallet_payment' ); ?>>
                        کیف پول پرداخت موفق
                    </option>
                    <option value="customer_wallet_order_completed" <?php selected( $filters[ 'sync_type' ] ?? '', 'customer_wallet_order_completed' ); ?>>
                        کیف پول سفارش تکمیل‌شده
                    </option>
                    <option value="rest_api" <?php selected( $filters[ 'sync_type' ] ?? '', 'rest_api' ); ?>>REST API
                    </option>
                    <option value="rate_fetch" <?php selected( $filters[ 'sync_type' ] ?? '', 'rate_fetch' ); ?>>دریافت
                        نرخ
                    </option>
                </select>
                <input type="text" name="sku" placeholder="SKU / Odoo ID"
                       value="<?php echo esc_attr( $filters[ 'sku' ] ?? '' ); ?>">
                <label>
                    <input type="checkbox" name="has_changes"
                           value="1" <?php checked( !empty( $filters[ 'has_changes' ] ) ); ?>>
                    فقط تغییرات
                </label>
                <button type="submit" class="button">فیلتر</button>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">خروجی CSV</a>
            </form>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>تاریخ</th>
                    <th>نوع</th>
                    <th>SKU</th>
                    <th>محصول / کاربر</th>
                    <th>عملیات</th>
                    <th>تغییر</th>
                    <th>پیام</th>
                    <th>جزئیات</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( empty( $result[ 'items' ] ) ) : ?>
                    <tr>
                        <td colspan="9">لاگی یافت نشد.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $result[ 'items' ] as $row ) : ?>
                        <tr>
                            <td><?php echo (int) $row[ 'id' ]; ?></td>
                            <td><?php echo esc_html( $row[ 'created_at' ] ); ?></td>
                            <td><?php echo esc_html( $row[ 'sync_type' ] ); ?></td>
                            <td><?php echo esc_html( $row[ 'sku' ] ?? '' ); ?></td>
                            <td>
                                <?php
                                if ( !empty( $row[ 'product_id' ] ) ) {
                                    if ( strpos( (string) $row[ 'sync_type' ], 'customer_' ) === 0 ) {
                                        $user = get_userdata( (int) $row[ 'product_id' ] );
                                        if ( $user ) {
                                            echo '<a href="' . esc_url( get_edit_user_link( (int) $row[ 'product_id' ] ) ) . '">' . esc_html( $user->display_name ) . '</a>';
                                        }
                                    } else {
                                        echo '<a href="' . esc_url( get_edit_post_link( (int) $row[ 'product_id' ] ) ) . '">' . esc_html( get_the_title( (int) $row[ 'product_id' ] ) ) . '</a>';
                                    }
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $row[ 'action' ] ); ?></td>
                            <td><?php echo $row[ 'has_changes' ] ? '<span style="color:green;">بله</span>' : 'خیر'; ?></td>
                            <td><?php echo esc_html( $row[ 'message' ] ); ?></td>
                            <td>
                                <details>
                                    <summary>نمایش</summary>
                                    <?php if ( $row[ 'request_data' ] ) : ?>
                                        <p><strong>درخواست:</strong></p>
                                        <pre style="max-width:400px;white-space:pre-wrap;"><?php echo esc_html( $row[ 'request_data' ] ); ?></pre>
                                    <?php endif; ?>
                                    <?php if ( $row[ 'response_data' ] ) : ?>
                                        <p><strong>پاسخ:</strong></p>
                                        <pre style="max-width:400px;white-space:pre-wrap;"><?php echo esc_html( $row[ 'response_data' ] ); ?></pre>
                                    <?php endif; ?>
                                    <?php if ( $row[ 'old_data' ] || $row[ 'new_data' ] ) : ?>
                                        <p><strong>قبل:</strong> <?php echo esc_html( (string) $row[ 'old_data' ] ); ?>
                                        </p>
                                        <p><strong>بعد:</strong> <?php echo esc_html( (string) $row[ 'new_data' ] ); ?>
                                        </p>
                                    <?php endif; ?>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $result[ 'total_pages' ] > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $result[ 'total_pages' ],
                            'current'   => $page,
                        ] );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function settings_page()
    {

        if ( isset( $_POST[ 'base_url' ] ) ) {
            update_option( 'odoo_base_url', sanitize_text_field( $_POST[ 'base_url' ] ) );
        }

        if ( isset( $_POST[ 'db' ] ) ) {
            update_option( 'odoo_db', sanitize_text_field( $_POST[ 'db' ] ) );
        }

        if ( isset( $_POST[ 'token' ] ) ) {
            update_option( 'odoo_token', sanitize_text_field( $_POST[ 'token' ] ) );
        }

        $base_url = get_option( 'odoo_base_url' );
        $db       = get_option( 'odoo_db' );
        $token    = get_option( 'odoo_token' );
        ?>

        <div class="wrap odoo-settings-wrapper">
            <h1 class="wp-heading-inline">تنظیمات اتصال Odoo</h1>
            <hr class="wp-header-end">

            <div class="card odoo-card">
                <form method="post">
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row">
                                <label for="base_url">آدرس سرور</label>
                            </th>
                            <td>
                                <input name="base_url" type="url" id="base_url"
                                       value="<?php echo esc_attr( $base_url ); ?>" class="regular-text"
                                       placeholder="https://your-odoo-instance.com">
                                <p class="description">آدرس کامل سرور اودو خود را وارد کنید.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="token">توکن امنیتی</label>
                            </th>
                            <td>
                                <input name="token" type="password" id="token" value="<?php echo esc_attr( $token ); ?>"
                                       class="regular-text">
                                <p class="description">توکن API تولید شده در اودو را اینجا قرار دهید.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="token">نام دیتابیس</label>
                            </th>
                            <td>
                                <input name="db" type="text" id="db" value="<?php echo esc_attr( $db ); ?>"
                                       class="regular-text">
                                <p class="description">نام دیتابیس را وارد کنید.</p>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <p class="submit">
                        <button type="submit" name="submit" id="submit" class="button button-primary button-large">ذخیره
                            تنظیمات
                        </button>
                    </p>
                </form>
            </div>

            <div class="card odoo-card">
                <h2>همگام‌سازی محصولات</h2>
                <p class="description">با کلیک روی دکمه زیر، یک‌بار لیست محصولات از Odoo و نرخ‌ها از زرسام دریافت
                    می‌شود، سپس محصولات به‌صورت ۲۰تایی بروزرسانی می‌شوند.</p>

                <p>
                    <button type="button" id="zarsim-sync-products" class="button button-secondary button-large">
                        همگام‌سازی محصولات
                    </button>
                </p>

                <div id="zarsim-sync-progress" style="display:none; margin-top:15px;">
                    <div style="background:#e0e0e0; border-radius:4px; height:24px; overflow:hidden;">
                        <div id="zarsim-sync-bar"
                             style="background:#2271b1; height:100%; width:0; transition:width 0.3s;"></div>
                    </div>
                    <p id="zarsim-sync-status" style="margin-top:10px;"></p>
                </div>
            </div>

            <div class="card odoo-card">
                <h2>همگام‌سازی مشتریان</h2>
                <p class="description">لیست مشتریان از Odoo دریافت می‌شود؛ اگر کاربر وجود نداشته باشد ساخته می‌شود، متای
                    Odoo و موجودی کیف پول Woo Wallet بروزرسانی می‌شود.</p>

                <p>
                    <button type="button" id="zarsim-sync-customers" class="button button-secondary button-large">
                        همگام‌سازی مشتریان
                    </button>
                </p>

                <div id="zarsim-customers-sync-progress" style="display:none; margin-top:15px;">
                    <div style="background:#e0e0e0; border-radius:4px; height:24px; overflow:hidden;">
                        <div id="zarsim-customers-sync-bar"
                             style="background:#008a20; height:100%; width:0; transition:width 0.3s;"></div>
                    </div>
                    <p id="zarsim-customers-sync-status" style="margin-top:10px;"></p>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var syncing = false;
                var syncingCustomers = false;

                function syncBatch(offset) {
                    $.post(ajaxurl, {
                        action: 'zarsim_sync_products_batch',
                        nonce: '<?php echo wp_create_nonce( 'zarsim_sync_products' ); ?>',
                        offset: offset
                    }).done(function (response) {
                        if (!response.success) {
                            $('#zarsim-sync-status').text(response.data.message || 'خطا در همگام‌سازی');
                            $('#zarsim-sync-products').prop('disabled', false);
                            syncing = false;
                            return;
                        }

                        var data = response.data;
                        var percent = Math.round((data.offset / data.total) * 100);
                        $('#zarsim-sync-bar').css('width', percent + '%');
                        $('#zarsim-sync-status').text(data.message);

                        if (data.done) {
                            $('#zarsim-sync-products').prop('disabled', false);
                            syncing = false;
                        } else {
                            syncBatch(data.offset);
                        }
                    }).fail(function () {
                        $('#zarsim-sync-status').text('خطا در ارتباط با سرور');
                        $('#zarsim-sync-products').prop('disabled', false);
                        syncing = false;
                    });
                }

                $('#zarsim-sync-products').on('click', function () {
                    if (syncing) return;
                    syncing = true;

                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $('#zarsim-sync-progress').show();
                    $('#zarsim-sync-bar').css('width', '0');
                    $('#zarsim-sync-status').text('در حال دریافت محصولات از Odoo و نرخ از زرسام...');

                    $.post(ajaxurl, {
                        action: 'zarsim_sync_fetch_products',
                        nonce: '<?php echo wp_create_nonce( 'zarsim_sync_products' ); ?>'
                    }).done(function (response) {
                        if (!response.success) {
                            $('#zarsim-sync-status').text(response.data.message || 'خطا در دریافت محصولات');
                            $btn.prop('disabled', false);
                            syncing = false;
                            return;
                        }

                        $('#zarsim-sync-status').text(response.data.message);
                        syncBatch(0);
                    }).fail(function () {
                        $('#zarsim-sync-status').text('خطا در ارتباط با سرور');
                        $btn.prop('disabled', false);
                        syncing = false;
                    });
                });

                function syncCustomerBatch(offset) {
                    $.post(ajaxurl, {
                        action: 'zarsim_sync_customers_batch',
                        nonce: '<?php echo wp_create_nonce( 'zarsim_sync_customers' ); ?>',
                        offset: offset
                    }).done(function (response) {
                        if (!response.success) {
                            $('#zarsim-customers-sync-status').text(response.data.message || 'خطا در همگام‌سازی مشتریان');
                            $('#zarsim-sync-customers').prop('disabled', false);
                            syncingCustomers = false;
                            return;
                        }

                        var data = response.data;
                        var percent = Math.round((data.offset / data.total) * 100);
                        $('#zarsim-customers-sync-bar').css('width', percent + '%');
                        $('#zarsim-customers-sync-status').text(data.message);

                        if (data.done) {
                            $('#zarsim-sync-customers').prop('disabled', false);
                            syncingCustomers = false;
                        } else {
                            syncCustomerBatch(data.offset);
                        }
                    }).fail(function () {
                        $('#zarsim-customers-sync-status').text('خطا در ارتباط با سرور');
                        $('#zarsim-sync-customers').prop('disabled', false);
                        syncingCustomers = false;
                    });
                }

                $('#zarsim-sync-customers').on('click', function () {
                    if (syncingCustomers) return;
                    syncingCustomers = true;

                    var $btn = $(this);
                    $btn.prop('disabled', true);
                    $('#zarsim-customers-sync-progress').show();
                    $('#zarsim-customers-sync-bar').css('width', '0');
                    $('#zarsim-customers-sync-status').text('در حال دریافت مشتریان از Odoo...');

                    $.post(ajaxurl, {
                        action: 'zarsim_sync_fetch_customers',
                        nonce: '<?php echo wp_create_nonce( 'zarsim_sync_customers' ); ?>'
                    }).done(function (response) {
                        if (!response.success) {
                            $('#zarsim-customers-sync-status').text(response.data.message || 'خطا در دریافت مشتریان');
                            $btn.prop('disabled', false);
                            syncingCustomers = false;
                            return;
                        }

                        $('#zarsim-customers-sync-status').text(response.data.message);
                        syncCustomerBatch(0);
                    }).fail(function () {
                        $('#zarsim-customers-sync-status').text('خطا در ارتباط با سرور');
                        $btn.prop('disabled', false);
                        syncingCustomers = false;
                    });
                });
            });
        </script>

        <style>
            .odoo-settings-wrapper {
                margin-top: 20px;
                max-width: 800px;
            }

            .odoo-card {
                max-width: 100%;
                margin-top: 20px;
                padding: 20px 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            }

            .odoo-card .form-table th {
                width: 150px;
                font-weight: 600;
            }

            .odoo-card input[type="text"],
            .odoo-card input[type="url"],
            .odoo-card input[type="password"] {
                border-radius: 4px;
                padding: 6px 12px;
                transition: all 0.3s ease;
                border: 1px solid #ccc;
            }

            .odoo-card input:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }

            .odoo-card .button-primary {
                padding: 0 30px !important;
                height: 40px !important;
                line-height: 38px !important;
                font-size: 14px !important;
            }

            /* راست‌چین کردن توضیحات برای زبان فارسی */
            .description {
                margin-top: 8px !important;
                font-style: italic;
                color: #666;
            }
        </style>


        <?php
    }

    private function call_odoo( $method, $data )
    {

        $base = get_option( 'odoo_base_url' );
        $db   = get_option( 'odoo_db' );
        $args = $data === null ? [] : [ $data ];

        $body = [
            "jsonrpc" => "2.0",
            "method"  => "call",
            "params"  => [
                "service" => "object",
                "method"  => "execute_kw",
                "args"    => [
                    $db,
                    $this->uid,
                    $this->api_key,
                    "res.partner.api",
                    $method,
                    $args
                ]
            ],
            "id"      => 1
        ];

        $result = wp_remote_post( $base . '/jsonrpc', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer'      => home_url(),
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );
        return $result;
    }

    private function add_odoo_order_note( $order, string $method, $response ): void
    {
        if ( !$order || !method_exists( $order, 'add_order_note' ) ) {
            return;
        }

        if ( is_wp_error( $response ) ) {
            $note = sprintf(
                "نتیجه درخواست Odoo (%s):\nخطا: %s",
                $method,
                $response->get_error_message()
            );
        } else {
            $body         = wp_remote_retrieve_body( $response );
            $decoded_body = json_decode( $body, true );
            $note_body    = $decoded_body
                ? wp_json_encode( $decoded_body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
                : $body;

            $note = sprintf(
                "نتیجه درخواست Odoo (%s):\nHTTP Status: %s\nResponse:\n%s",
                $method,
                wp_remote_retrieve_response_code( $response ),
                $note_body
            );
        }

        $order->add_order_note( $note, false, true );
    }

    public function register_order_sync_meta_box(): void
    {
        $screens = [ 'shop_order' ];

        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }

        foreach ( array_unique( $screens ) as $screen ) {
            add_meta_box(
                'zarsam_odoo_order_sync',
                'همگام‌سازی سفارش Odoo',
                [ $this, 'render_order_sync_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_order_sync_meta_box( $post_or_order_object ): void
    {
        $order = $post_or_order_object instanceof \WP_Post
            ? wc_get_order( $post_or_order_object->ID )
            : $post_or_order_object;

        if ( !$order || !method_exists( $order, 'get_id' ) ) {
            echo '<p>سفارش نامعتبر است.</p>';
            return;
        }

        $order_id    = (int) $order->get_id();
        $last_sync   = $order->get_meta( 'zarsam_odoo_order_last_sync' );
        $last_status = $order->get_meta( 'zarsam_odoo_order_last_status' );
        $nonce       = wp_create_nonce( 'zarsim_sync_order_' . $order_id );
        ?>
        <p>
            <strong>آخرین وضعیت:</strong>
            <?php echo $last_status ? esc_html( $last_status ) : 'ثبت نشده'; ?>
        </p>
        <p>
            <strong>آخرین ارسال:</strong>
            <?php echo $last_sync ? esc_html( $last_sync ) : 'انجام نشده'; ?>
        </p>
        <p>
            <button type="button"
                    class="button button-primary"
                    id="zarsam-sync-order-to-odoo"
                    data-order-id="<?php echo esc_attr( $order_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                ارسال دوباره سفارش به Odoo
            </button>
        </p>
        <p id="zarsam-sync-order-status" style="margin-top:8px;"></p>
        <script>
            jQuery(function ($) {
                $('#zarsam-sync-order-to-odoo').on('click', function () {
                    var $button = $(this);
                    var $status = $('#zarsam-sync-order-status');

                    $button.prop('disabled', true);
                    $status.css('color', '#2271b1').text('در حال ارسال سفارش به Odoo...');

                    $.post(ajaxurl, {
                        action: 'zarsim_sync_single_order',
                        nonce: $button.data('nonce'),
                        order_id: $button.data('order-id')
                    }).done(function (response) {
                        if (response.success) {
                            $status.css('color', '#008a20').text(response.data.message);
                            window.setTimeout(function () {
                                window.location.reload();
                            }, 900);
                        } else {
                            $status.css('color', '#b32d2e').text(response.data.message || 'خطا در ارسال سفارش');
                            $button.prop('disabled', false);
                        }
                    }).fail(function () {
                        $status.css('color', '#b32d2e').text('خطا در ارتباط با سرور');
                        $button.prop('disabled', false);
                    });
                });
            });
        </script>
        <?php
    }

    public function ajax_sync_single_order(): void
    {
        $order_id = isset( $_POST[ 'order_id' ] ) ? (int) $_POST[ 'order_id' ] : 0;

        if ( !$order_id ) {
            wp_send_json_error( [ 'message' => 'سفارش نامعتبر است' ] );
        }

        check_ajax_referer( 'zarsim_sync_order_' . $order_id, 'nonce' );

        if ( !current_user_can( 'edit_post', $order_id ) && !current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $result = $this->sync_order_to_odoo( $order_id, true );

        if ( empty( $result[ 'success' ] ) ) {
            wp_send_json_error( [ 'message' => $result[ 'message' ] ?? 'خطا در ارسال سفارش به Odoo' ] );
        }

        wp_send_json_success( [
            'message' => 'درخواست ایجاد سفارش به Odoo ارسال شد و نتیجه در یادداشت سفارش ثبت شد.',
        ] );
    }

    private function sanitize_and_format_ir_mobile( $phone )
    {
        if ( empty( $phone ) ) {
            return false;
        }

        // ۱. تبدیل اعداد فارسی و عربی به انگلیسی
        $persian = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];
        $arabic  = [ '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ];
        $english = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];

        $phone = str_replace( $persian, $english, $phone );
        $phone = str_replace( $arabic, $english, $phone );

        // ۲. حذف هر کاراکتری که عدد نیست (مثل +، فاصله، خط تیره و...)
        $phone = preg_replace( '/\D/', '', $phone );

        // ۳. بررسی و استخراج بخش اصلی شماره (۱۰ رقم آخر که باید با 9 شروع شود)
        // شماره موبایل ایران بدون کد کشور همیشه ۱۰ رقم است و با ۹ شروع می‌شود.
        if ( preg_match( '/^(0098|98|0)?(9\d{9})$/', $phone, $matches ) ) {
            // $matches[2] حاوی ۱۰ رقم اصلی شماره موبایل است (مثلاً 9123456789)
            return '+98' . $matches[ 2 ];
        }

        // اگر شماره با الگوی موبایل ایران سازگار نبود
        return false;
    }

    private function build_order_payload( $order ): array
    {
        $products = [];

        foreach ( $order->get_items() as $item ) {
            $product_item = wc_get_product( $item->get_product_id() );

            $products[] = [
                "products id in order" => $item->get_product_id(),
                "sku"                  => $product_item->get_sku(),
                "fee"                  => $item->get_total() * 10,
                "qty"                  => $item->get_quantity()
            ];
        }
        $phone = $this->sanitize_and_format_ir_mobile( (string) $order->get_billing_phone() );
        return [
            "warehouse_id"               => 1,
            "woo_order_id"               => $order->get_id(),
            "create_order_date"          => $order->get_date_created()->date( 'Y-m-d' ),
            "product_ids"                => $products,
            "price"                      => $order->get_total() * 10,
            "dobare_discount"            => 0,
            "price_with_discount"        => $order->get_total() * 10,
            "customer_id"                => $order->get_customer_id(),
            "customer_name"              => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            "customer_mobile"            => $phone,
            "customer_birthdate"         => "1990-12-12",
            "customer_partner_birthdate" => "1990-12-12",
            "customer_wedding_date"      => "1990-12-12"
        ];
    }

    private function sync_order_to_odoo( int $order_id, bool $manual = false ): array
    {
        $order = wc_get_order( $order_id );

        if ( !$order ) {
            return [
                'success' => false,
                'message' => 'سفارش پیدا نشد',
            ];
        }

        $this->db = 'create_order';
        $data     = $this->build_order_payload( $order );

        $response = $this->call_odoo( 'create_order', $this->build_order_payload( $order ) );
        $this->add_odoo_order_note( $order, $manual ? 'create_order_manual' : 'create_order', $response );

        $success = !is_wp_error( $response );
        $body    = $success ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];

        if ( $success && !empty( $body[ 'error' ] ) ) {
            $success = false;
        }

        $order->update_meta_data( 'zarsam_odoo_order_last_sync', current_time( 'mysql' ) );
        $order->update_meta_data( 'zarsam_odoo_order_last_status', $success ? 'success' : 'failed' );
        $order->save();

        if ( $order->get_customer_id() ) {
            $this->sync_customer_wallet_from_odoo_user( (int) $order->get_customer_id(), 'customer_wallet_order_completed', true );
        }

        return [
            'success'  => $success,
            'message'  => $success ? 'سفارش به Odoo ارسال شد' : 'ارسال سفارش به Odoo ناموفق بود',
            'response' => $response,
        ];
    }

    public function create_customer( $user_id )
    {
//        if ( $this->is_importing_odoo_customer ) {
//            return;
//        }

        $user = get_userdata( $user_id );
        if ( !$user ) {
            return null;
        }

        $data = [
            "customer_id"                => $user_id,
            "customer_name"              => $user->display_name,
            "customer_mobile"            => $this->sanitize_and_format_ir_mobile($this->get_customer_mobile_for_user( (int) $user_id )),
            "customer_birthdate"         => false,
            "customer_partner_birthdate" => false,
            "customer_wedding_date"      => false
        ];

        $response = $this->call_odoo( "create_customer", $data );
        $success  = !is_wp_error( $response );
        $body     = $success ? json_decode( wp_remote_retrieve_body( $response ), true ) : [];

        if ( $success && empty( $body[ 'error' ] ) ) {
            update_user_meta( (int) $user_id, self::USER_META_CREATE_SENT, current_time( 'mysql' ) );
        }

        return $response;
    }

    public function create_order( $order_id )
    {
        $this->sync_order_to_odoo( (int) $order_id );
    }

}
