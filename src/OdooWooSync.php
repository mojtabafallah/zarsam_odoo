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

    private static $instance = null;

    public function __construct()
    {
        self::$instance = $this;
        $this->api_key = get_option( 'odoo_token' );
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'user_register', [ $this, 'create_customer' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'create_order' ] );

        add_action( 'add_meta_boxes', [ $this, 'register_product_meta_box' ] );
        add_action( 'wp_ajax_get_zarsim_rates', [ $this, 'handle_zarsim_rates_request' ] );
        add_action( 'wp_ajax_zarsim_update_product_rate', [ $this, 'ajax_update_product_rate' ] );
        add_action( 'wp_ajax_zarsim_sync_fetch_products', [ $this, 'ajax_fetch_products' ] );
        add_action( 'wp_ajax_zarsim_sync_products_batch', [ $this, 'ajax_sync_products_batch' ] );
        add_action( 'admin_post_zarsam_odoo_export_logs', [ $this, 'export_logs' ] );
    }

    public static function get_instance(): self
    {
        return self::$instance;
    }

    private function assign_product_category( int $product_id, array $data ): void
    {
        $category_id   = $data['category_id'] ?? null;
        $category_name = $data['category_name'] ?? null;

        if ( ! $category_id && ! $category_name ) {
            return;
        }

        $term_id = $category_id;

        if ( ! $term_id && $category_name ) {
            $term = term_exists( $category_name, 'product_cat' );

            if ( ! $term ) {
                $term = wp_insert_term( $category_name, 'product_cat' );
            }

            if ( ! is_wp_error( $term ) ) {
                $term_id = $term['term_id'];
            }
        }

        if ( $term_id ) {
            wp_set_object_terms( $product_id, (int) $term_id, 'product_cat' );
        }
    }

    private function assign_product_warehouse( int $product_id, array $data ): void
    {
        if ( ! empty( $data['warehouse_id'] ) ) {
            update_post_meta( $product_id, '_warehouse_id', $data['warehouse_id'] );
        }
    }

    public function process_odoo_api_product( array $data ): array
    {
        $sku = $data['default_code'] ?? '';

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
            'success'         => true,
            'message'         => $result['created'] ? 'محصول ایجاد شد' : 'محصول بروزرسانی شد',
            'status'          => 200,
            'product_id'      => $result['woo_product_id'],
            'odoo_id'         => $data['id'] ?? null,
            'sku'             => $sku,
            'name'            => $data['name'] ?? '',
            'created'         => $result['created'],
            'updated'         => ! $result['created'],
            'weight'          => (float) ( $data['zarsim_weight'] ?? 0 ),
            'product_type'    => (string) ( $data['zarsim_product_type'] ?? '' ),
            'qty_available'   => (int) ( $data['qty_available'] ?? 0 ),
            'list_price'      => (float) ( $data['list_price'] ?? 0 ),
            'final_price'     => $result['final_price'],
            'unit_price'      => $result['unit_price'],
            'category_id'     => $data['category_id'] ?? null,
            'category_name'   => $data['category_name'] ?? null,
            'warehouse_id'    => $data['warehouse_id'] ?? null,
            'calculation'     => $result,
            'has_changes'     => $result['has_changes'],
        ];
    }

    function handle_zarsim_rates_request()
    {
        check_ajax_referer( 'zarsim_product_odoo', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'دسترسی ندارید' );
        }

        $product_sku = sanitize_text_field( $_POST['product_id'] ?? '' );
        $post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

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
        $products_data = $body['result'][0] ?? null;

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

        echo 'بروزرسانی قیمت: ' . number_format( $result['final_price'] );
        exit();
    }

    public function ajax_update_product_rate()
    {
        check_ajax_referer( 'zarsim_product_odoo', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id ) {
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
            'message'     => 'قیمت بروزرسانی شد: ' . number_format( $result['final_price'] ),
            'calculation' => $result,
            'rates'       => $rates,
        ] );
    }

    public function ajax_fetch_products()
    {
        check_ajax_referer( 'zarsim_sync_products', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی ندارید' ] );
        }

        $response = $this->call_odoo( 'get_product_list', '' );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'خطا در اتصال به Odoo' ] );
        }

        $body          = json_decode( wp_remote_retrieve_body( $response ), true );
        $products_data = $body[ 'result' ] ?? [];

        if ( empty( $products_data ) || ! is_array( $products_data ) ) {
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

        if ( ! current_user_can( 'manage_options' ) ) {
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
                <button type="button" class="button button-primary" id="zarsam-refresh-from-odoo" <?php disabled( empty( $sku ) ); ?>>
                    دریافت از Odoo + نرخ
                </button>
                <button type="button" class="button" id="zarsam-refresh-rate-only" <?php disabled( empty( $raw_data ) ); ?>>
                    بروزرسانی نرخ (فقط Rate)
                </button>
            </p>
            <p id="zarsam-odoo-status" style="margin:8px 0;"></p>

            <?php if ( ! empty( $odoo_data ) ) : ?>
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

            <?php if ( ! empty( $calc_data ) ) : ?>
                <h4 style="margin-top:20px;">آخرین محاسبه قیمت</h4>
                <table class="widefat striped">
                    <tbody>
                    <?php if ( ! empty( $calc_data['calculation'] ) ) : ?>
                        <?php foreach ( $calc_data['calculation'] as $key => $value ) : ?>
                            <tr>
                                <th style="width:200px;"><?php echo esc_html( $key ); ?></th>
                                <td><?php echo esc_html( is_numeric( $value ) ? number_format( (float) $value ) : (string) $value ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $calc_data['rates_updated_at'] ) ) : ?>
                        <tr>
                            <th>زمان نرخ</th>
                            <td><?php echo esc_html( $calc_data['rates_updated_at'] ); ?></td>
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

                <?php if ( ! empty( $raw_data ) ) : ?>
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
        if ( ! $product ) {
            return [];
        }

        $old_values = [
            'price' => $product->get_price(),
            'stock' => $product->get_stock_quantity(),
        ];

        $result = $this->calculateProductPrice( $odoo_data, $rates );
        $product->set_price( $result['final_price'] );
        $product->set_regular_price( $result['final_price'] );
        $product->save();

        $new_values = [
            'price' => $result['final_price'],
            'stock' => $product->get_stock_quantity(),
        ];

        $this->save_product_calculation_meta( $product_id, $odoo_data, $result, $rates );

        $has_changes = ( (float) $old_values['price'] !== (float) $new_values['price'] );

        SyncLogger::log( [
            'sync_type'     => $sync_type,
            'product_id'    => $product_id,
            'sku'           => $odoo_data['default_code'] ?? get_post_meta( $product_id, '_sku', true ),
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
        update_post_meta( $product_id, 'zarsam_odoo_product_id', $odoo_data['id'] ?? '' );
    }

    private function save_product_calculation_meta( int $product_id, array $odoo_data, array $calculation, array $rates ): void
    {
        update_post_meta( $product_id, self::META_CALCULATION, wp_json_encode( [
            'calculation'       => $calculation,
            'rates'             => $rates,
            'rates_updated_at'  => current_time( 'mysql' ),
        ], JSON_UNESCAPED_UNICODE ) );
    }

    public function zarsim_process_single_product( $data, $rates = null, $sync_type = 'bulk_sync', $known_product_id = 0 )
    {
        $sku         = $data['default_code'] ?? '';
        $product_id  = $known_product_id ?: wc_get_product_id_by_sku( $sku );
        $was_existing = (bool) $product_id;

        if ( $rates === null ) {
            $rates = $this->get_zarsim_rates( $sync_type, $product_id ?: null, $sku );
        }

        $old_values = [ 'price' => null, 'stock' => null ];
        $result     = $this->calculateProductPrice( $data, $rates ?: [] );

        if ( $product_id ) {
            $product             = wc_get_product( $product_id );
            $old_values['price'] = $product->get_price();
            $old_values['stock'] = $product->get_stock_quantity();
            $product->set_name( $data['name'] ?? $product->get_name() );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $data['qty_available'] ?? 0 );
            $product->set_price( $result['final_price'] );
            $product->set_regular_price( $result['final_price'] );
            $product->save();
        } else {
            $new_product = new WC_Product_Simple();
            $new_product->set_name( $data['name'] ?? $sku );
            $new_product->set_sku( $sku );
            $new_product->set_manage_stock( true );
            $new_product->set_price( $result['final_price'] );
            $new_product->set_regular_price( $result['final_price'] );
            $new_product->set_stock_quantity( $data['qty_available'] ?? 0 );
            $new_product->save();
            $product_id = $new_product->get_id();
        }

        $this->assign_product_category( $product_id, $data );
        $this->assign_product_warehouse( $product_id, $data );
        $this->save_product_odoo_meta( $product_id, $data );
        $this->save_product_calculation_meta( $product_id, $data, $result, $rates ?: [] );

        $new_values = [
            'price' => $result['final_price'],
            'stock' => $data['qty_available'] ?? 0,
        ];

        $has_changes = ( (float) $old_values['price'] !== (float) $new_values['price'] )
            || ( (int) $old_values['stock'] !== (int) $new_values['stock'] );

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
            'created'        => ! $was_existing,
            'has_changes'    => $has_changes,
        ] );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی ندارید' );
        }

        check_admin_referer( 'zarsam_odoo_export_logs' );

        $filters = [];
        if ( ! empty( $_GET['has_changes'] ) ) {
            $filters['has_changes'] = 1;
        }
        if ( ! empty( $_GET['sync_type'] ) ) {
            $filters['sync_type'] = sanitize_text_field( wp_unslash( $_GET['sync_type'] ) );
        }
        if ( ! empty( $_GET['sku'] ) ) {
            $filters['sku'] = sanitize_text_field( wp_unslash( $_GET['sku'] ) );
        }

        SyncLogger::export_csv( $filters );
    }

    public function logs_page(): void
    {
        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page = 20;
        $filters  = [];

        if ( ! empty( $_GET['has_changes'] ) ) {
            $filters['has_changes'] = 1;
        }
        if ( ! empty( $_GET['sync_type'] ) ) {
            $filters['sync_type'] = sanitize_text_field( wp_unslash( $_GET['sync_type'] ) );
        }
        if ( ! empty( $_GET['sku'] ) ) {
            $filters['sku'] = sanitize_text_field( wp_unslash( $_GET['sku'] ) );
        }

        $result       = SyncLogger::get_logs( $page, $per_page, $filters );
        $export_url   = wp_nonce_url(
            add_query_arg( array_merge( [ 'action' => 'zarsam_odoo_export_logs' ], $filters ), admin_url( 'admin-post.php' ) ),
            'zarsam_odoo_export_logs'
        );
        $base_url     = add_query_arg( array_merge( [ 'page' => 'zarsam-odoo-logs' ], $filters ), admin_url( 'admin.php' ) );
        ?>
        <div class="wrap">
            <h1>لاگ همگام‌سازی Odoo</h1>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="zarsam-odoo-logs">
                <select name="sync_type">
                    <option value="">همه انواع</option>
                    <option value="bulk_sync" <?php selected( $filters['sync_type'] ?? '', 'bulk_sync' ); ?>>همگام‌سازی گروهی</option>
                    <option value="single_product" <?php selected( $filters['sync_type'] ?? '', 'single_product' ); ?>>محصول تکی (Odoo)</option>
                    <option value="single_rate" <?php selected( $filters['sync_type'] ?? '', 'single_rate' ); ?>>محصول تکی (Rate)</option>
                    <option value="rest_api" <?php selected( $filters['sync_type'] ?? '', 'rest_api' ); ?>>REST API</option>
                    <option value="rate_fetch" <?php selected( $filters['sync_type'] ?? '', 'rate_fetch' ); ?>>دریافت نرخ</option>
                </select>
                <input type="text" name="sku" placeholder="SKU" value="<?php echo esc_attr( $filters['sku'] ?? '' ); ?>">
                <label>
                    <input type="checkbox" name="has_changes" value="1" <?php checked( ! empty( $filters['has_changes'] ) ); ?>>
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
                    <th>محصول</th>
                    <th>عملیات</th>
                    <th>تغییر</th>
                    <th>پیام</th>
                    <th>جزئیات</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( empty( $result['items'] ) ) : ?>
                    <tr><td colspan="9">لاگی یافت نشد.</td></tr>
                <?php else : ?>
                    <?php foreach ( $result['items'] as $row ) : ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><?php echo esc_html( $row['sync_type'] ); ?></td>
                            <td><?php echo esc_html( $row['sku'] ?? '' ); ?></td>
                            <td>
                                <?php
                                if ( ! empty( $row['product_id'] ) ) {
                                    echo '<a href="' . esc_url( get_edit_post_link( (int) $row['product_id'] ) ) . '">' . esc_html( get_the_title( (int) $row['product_id'] ) ) . '</a>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $row['action'] ); ?></td>
                            <td><?php echo $row['has_changes'] ? '<span style="color:green;">بله</span>' : 'خیر'; ?></td>
                            <td><?php echo esc_html( $row['message'] ); ?></td>
                            <td>
                                <details>
                                    <summary>نمایش</summary>
                                    <?php if ( $row['request_data'] ) : ?>
                                        <p><strong>درخواست:</strong></p>
                                        <pre style="max-width:400px;white-space:pre-wrap;"><?php echo esc_html( $row['request_data'] ); ?></pre>
                                    <?php endif; ?>
                                    <?php if ( $row['response_data'] ) : ?>
                                        <p><strong>پاسخ:</strong></p>
                                        <pre style="max-width:400px;white-space:pre-wrap;"><?php echo esc_html( $row['response_data'] ); ?></pre>
                                    <?php endif; ?>
                                    <?php if ( $row['old_data'] || $row['new_data'] ) : ?>
                                        <p><strong>قبل:</strong> <?php echo esc_html( (string) $row['old_data'] ); ?></p>
                                        <p><strong>بعد:</strong> <?php echo esc_html( (string) $row['new_data'] ); ?></p>
                                    <?php endif; ?>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $result['total_pages'] > 1 ) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $result['total_pages'],
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
                <p class="description">با کلیک روی دکمه زیر، یک‌بار لیست محصولات از Odoo و نرخ‌ها از زرسام دریافت می‌شود، سپس محصولات به‌صورت ۲۰تایی بروزرسانی می‌شوند.</p>

                <p>
                    <button type="button" id="zarsim-sync-products" class="button button-secondary button-large">
                        همگام‌سازی محصولات
                    </button>
                </p>

                <div id="zarsim-sync-progress" style="display:none; margin-top:15px;">
                    <div style="background:#e0e0e0; border-radius:4px; height:24px; overflow:hidden;">
                        <div id="zarsim-sync-bar" style="background:#2271b1; height:100%; width:0; transition:width 0.3s;"></div>
                    </div>
                    <p id="zarsim-sync-status" style="margin-top:10px;"></p>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var syncing = false;

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

        $result = wp_remote_post($base . '/jsonrpc', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer'      => home_url(),
            ],
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ]);
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
