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

    public function __construct()
    {
        $this->api_key = get_option( 'odoo_token' );
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'user_register', [ $this, 'create_customer' ] );
        add_action( 'woocommerce_order_status_changed', [ $this, 'create_order' ] );

        add_action( 'post_submitbox_misc_actions', [ $this, 'add_product_api_button' ] );
        add_action( 'wp_ajax_get_zarsim_rates', [ $this, 'handle_zarsim_rates_request' ] );

        // ۳. اتصال اکشن به تابع اصلی همگام‌سازی
//add_action( 'zarsim_product_sync_hook', 'zarsim_sync_products_from_api' );
        add_action( 'init', [ $this, 'zarsim_sync_products_from_api' ] );
    }

    function handle_zarsim_rates_request() {
        $product_sku = $_POST['product_id'];
        $response = $this->call_odoo( 'get_product_list', ['default_code' => $product_sku] );
        if ( is_wp_error( $response ) ) {
            return; // در صورت خطا در اتصال، عملیات متوقف شود
        }

        $products_data = json_decode( wp_remote_retrieve_body( $response ), true );
        $products_data = $products_data[ 'result' ];
        $products_data = $products_data[0];
//        $product_id = wc_get_product_id_by_sku($product_sku);
//        $product = wc_get_product( $product_id );
        $final_price = $this->zarsim_process_single_product( $products_data );

        echo 'بروز رسانی قیمت: ' . number_format( $final_price );
        exit();
    }

    public function add_product_api_button()
    {
        global $post;

        if ( $post->post_type !== 'product' ) {
            return;
        }
        $sku = get_post_meta( $post->ID, '_sku', true );

        ?>
        <div class="misc-pub-section">
            <button type="button" class="button button-primary" id="send-api-request">
                دریافت نرخ
            </button>
            <span id="api-result" style="display:block;margin-top:8px;"></span>
        </div>

        <script>
            jQuery(document).ready(function ($) {

                $('#send-api-request').on('click', function () {

                    $('#api-result').text('در حال دریافت...');

                    $.post(ajaxurl, {
                        action: 'get_zarsim_rates',
                        product_id: <?php echo $sku; ?>
                    }, function (response) {
                        $('#api-result').text(response);
                    });

                });

            });
        </script>
        <?php
    }

    public function zarsim_sync_products_from_api()
    {

        if ( !isset( $_GET[ 'debug' ] ) ) {
            return;
        }

        $response = $this->call_odoo( 'get_product_list', '' );
        if ( is_wp_error( $response ) ) {
            return; // در صورت خطا در اتصال، عملیات متوقف شود
        }

        $products_data = json_decode( wp_remote_retrieve_body( $response ), true );
        $products_data = $products_data[ 'result' ];
        if ( !empty( $products_data ) && is_array( $products_data ) ) {
            foreach ( $products_data as $product_item ) {
                $this->zarsim_process_single_product( $product_item );
            }
        }
    }

    public function get_zarsim_rates()
    {
        $url = 'https://zarsimjewelry.com/wp-json/zarsim/v1/rates-simple';

        // ارسال درخواست GET
        $response = wp_remote_get( $url, array (
            'timeout'   => 15,    // زمان انتظار برای پاسخ
            'sslverify' => false, // اگر سایت مقصد مشکل SSL داشت این را false بگذارید
        ) );

        // بررسی وجود خطا در ارتباط
        if ( is_wp_error( $response ) ) {
            return false;
        }

        // دریافت بدنه پاسخ (Body)
        $body = wp_remote_retrieve_body( $response );

        // تبدیل فرمت JSON به آرایه یا آبجکت PHP
        $data = json_decode( $body, true );

        if ( empty( $data ) ) {
            return false;
        }

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

    public function zarsim_process_single_product( $data )
    {
        // فرض بر این است که $data حاوی SKU یا یک شناسه منحصر به فرد است
        $sku        = $data[ 'default_code' ];
        $product_id = wc_get_product_id_by_sku( $sku );
        $rates      = $this->get_zarsim_rates();
        if ( $product_id ) {
            // به‌روزرسانی محصول موجود
            $product = wc_get_product( $product_id );
            $product->set_stock_quantity( $data[ 'qty_available' ] );
            $result = $this->calculateProductPrice( $data, $rates );
            $product->set_price( $result[ 'final_price' ] );
            $product->save();
        } else {
            // ایجاد محصول جدید اگر وجود نداشت (اختیاری)
            $new_product = new WC_Product_Simple();
            $new_product->set_name( $data[ 'name' ] );
            $new_product->set_sku( $sku );
            $result = $this->calculateProductPrice( $data, $rates );
            $new_product->set_price( $result[ 'final_price' ] );
            $new_product->set_stock_quantity( $data[ 'qty_available' ] );
            $new_product->save();
        }

        update_post_meta( $product_id, 'zarsam_odoo_product_id', $data[ 'id' ] );
        return $result[ 'final_price' ];
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
        </div>

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
                    [ $data ]
                ]
            ],
            "id"      => 1
        ];

        $result = wp_remote_post( $base . '/jsonrpc', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => json_encode( $body )
        ] );
        return $result;
    }

    public function create_customer( $user_id )
    {

        $user = get_userdata( $user_id );

        $data = [
            "customer_id"                => $user_id,
            "customer_name"              => $user->display_name,
            "customer_mobile"            => get_user_meta( $user_id, 'billing_phone', true ),
            "customer_birthdate"         => "",
            "customer_partner_birthdate" => "",
            "customer_wedding_date"      => ""
        ];

        $this->call_odoo( "create_customer", $data );
    }

    public function create_order( $order_id )
    {

        $order = wc_get_order( $order_id );

        $products = [];

        foreach ( $order->get_items() as $item ) {

            $products[] = [
                "products id in order" => $item->get_product_id(),
                "fee"                  => $item->get_total(),
                "qty"                  => $item->get_quantity()
            ];
        }

        $data     = [
            "warehouse_id"               => 1,
            "woo_order_id"               => $order_id,
            "create_order_date"          => $order->get_date_created()->date( 'Y-m-d' ),
            "product_ids"                => $products,
            "price"                      => $order->get_total(),
            "dobare_discount"            => 0,
            "price_with_discount"        => $order->get_total(),
            "customer_id"                => $order->get_customer_id(),
            "customer_name"              => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            "customer_mobile"            => $order->get_billing_phone(),
            "customer_birthdate"         => "1990-12-12",
            "customer_partner_birthdate" => "1990-12-12",
            "customer_wedding_date"      => "1990-12-12"
        ];
        $this->db = 'create_order';

        $this->call_odoo( "create_order", $data );
    }

}
