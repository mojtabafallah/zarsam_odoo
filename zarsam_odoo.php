<?php
/**
 * Plugin Name: Zarsam Odoo
 * Plugin URI:  https://zarsam.com/
 * Description: ب
 * Version:     1.0.0
 * Author:      Mojtaba Fallah
 * Author URI:  https://github.com/mojtabafallah/
 */

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

final class ZarsamOdoo
{
    private static $instance;

    public function __construct()
    {
        require_once 'vendor/autoload.php';
        require_once 'functions.php';
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

add_action( 'rest_api_init', function () {
    register_rest_route( "zarsam_odoo/v1", "login", array (
        'methods'             => "POST",
        'callback'            => 'login',
        'permission_callback' => '__return_true',
    ) );
} );