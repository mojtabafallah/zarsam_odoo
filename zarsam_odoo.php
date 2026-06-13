<?php
/**
 * Plugin Name: Zarsam Odoo
 * Plugin URI:  https://zarsam.com/
 * Description: این پلاگین اختصاصی برای سایت زرسام توسعه داده شده است و برای ارتباط با odoo میباشد
 * Version:     1.1.0
 * Author:      Mojtaba Fallah
 * Author URI:  https://github.com/mojtabafallah/
 */

use Mojtaba\ZarsamOdoo\OdooWooSync;
use Mojtaba\ZarsamOdoo\SyncLogger;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ZARSAM_ODOO_VERSION', '1.1.0' );

final class ZarsamOdoo
{
    private static $instance;

    public function __construct()
    {
        require_once 'vendor/autoload.php';
        require_once 'functions.php';
        require_once 'routes/products.php';

        new OdooWooSync();

    }

    public static function get_instance()
    {
        if ( null === self::$instance ) {
            return self::$instance = new self();
        }
        return self::$instance;
    }
}

ZarsamOdoo::get_instance();

register_activation_hook( __FILE__, function () {
    SyncLogger::install_table();
    update_option( 'zarsam_odoo_db_version', ZARSAM_ODOO_VERSION );
} );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'zarsam_odoo_db_version' ) !== ZARSAM_ODOO_VERSION ) {
        SyncLogger::install_table();
        update_option( 'zarsam_odoo_db_version', ZARSAM_ODOO_VERSION );
    }
} );

add_action( 'rest_api_init', function () {
    register_rest_route( "zarsam_odoo/v1", "login", array (
        'methods'             => "POST",
        'callback'            => 'login',
        'permission_callback' => '__return_true',
    ) );
} );

// Disable WordPress Heartbeat
add_action( 'init', function() {
    wp_deregister_script('heartbeat');
}, 1 );

// Disable emoji scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

// Disable embeds
function disable_embeds_code_init() {
    remove_action( 'rest_api_init', 'wp_oembed_register_route' );
    remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
    remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
}
add_action( 'init', 'disable_embeds_code_init', 9999 );

// ۱. اضافه کردن بازه زمانی ۱۰ دقیقه به لیست زمان‌بندی‌های وردپرس
add_filter( 'cron_schedules', 'zarsim_add_ten_minutes_cron_schedule' );
function zarsim_add_ten_minutes_cron_schedule( $schedules ) {
    $schedules['ten_minutes'] = array(
        'interval' => 10, // ۱۰ دقیقه به ثانیه
        'display'  => esc_html__( 'Every 10 Minutes' ),
    );
    return $schedules;
}

// ۲. برنامه‌ریزی برای اجرای خودکار در صورت عدم وجود
if ( ! wp_next_scheduled( 'zarsim_product_sync_hook' ) ) {
    wp_schedule_event( time(), 'ten_minutes', 'zarsim_product_sync_hook' );
}
