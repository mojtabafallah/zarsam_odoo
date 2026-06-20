<?php

namespace Mojtaba\ZarsamOdoo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SyncLogger
{
    private const ADMIN_NOTIFICATIONS_OPTION = 'zarsam_odoo_admin_notifications';
    private const MAX_ADMIN_NOTIFICATIONS      = 50;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'zarsam_odoo_logs';
    }

    public static function install_table(): void
    {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL DEFAULT '',
            product_id bigint(20) unsigned DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            action varchar(100) NOT NULL DEFAULT '',
            request_data longtext,
            response_data longtext,
            old_data longtext,
            new_data longtext,
            has_changes tinyint(1) NOT NULL DEFAULT 0,
            message text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_type (sync_type),
            KEY product_id (product_id),
            KEY sku (sku),
            KEY has_changes (has_changes),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function log( array $args ): int
    {
        global $wpdb;

        $defaults = [
            'sync_type'     => '',
            'product_id'    => null,
            'sku'           => null,
            'action'        => '',
            'request_data'  => null,
            'response_data' => null,
            'old_data'      => null,
            'new_data'      => null,
            'has_changes'   => 0,
            'message'       => '',
        ];

        $data = wp_parse_args( $args, $defaults );

        if ( self::should_notify_error( $data ) ) {
            self::notify_error( $data );
        }

        $wpdb->insert(
            self::table_name(),
            [
                'sync_type'     => sanitize_text_field( $data['sync_type'] ),
                'product_id'    => $data['product_id'] ? (int) $data['product_id'] : null,
                'sku'           => $data['sku'] ? sanitize_text_field( $data['sku'] ) : null,
                'action'        => sanitize_text_field( $data['action'] ),
                'request_data'  => self::encode( $data['request_data'] ),
                'response_data' => self::encode( $data['response_data'] ),
                'old_data'      => self::encode( $data['old_data'] ),
                'new_data'      => self::encode( $data['new_data'] ),
                'has_changes'   => (int) (bool) $data['has_changes'],
                'message'       => sanitize_text_field( $data['message'] ),
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public static function get_logs( int $page = 1, int $per_page = 20, array $filters = [] ): array
    {
        global $wpdb;

        $table  = self::table_name();
        $offset = max( 0, ( $page - 1 ) * $per_page );
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['has_changes'] ) ) {
            $where[]  = 'has_changes = %d';
            $params[] = 1;
        }

        if ( ! empty( $filters['sync_type'] ) ) {
            $where[]  = 'sync_type = %s';
            $params[] = sanitize_text_field( $filters['sync_type'] );
        }

        if ( ! empty( $filters['sku'] ) ) {
            $where[]  = 'sku LIKE %s';
            $params[] = '%' . $wpdb->esc_like( sanitize_text_field( $filters['sku'] ) ) . '%';
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

        $query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[]  = $per_page;
        $params[]  = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$params ), ARRAY_A );

        return [
            'items'       => $rows ?: [],
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    public static function export_csv( array $filters = [] ): void
    {
        $page     = 1;
        $per_page = 500;
        $filename = 'zarsam-odoo-logs-' . gmdate( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, [
            'ID',
            'تاریخ',
            'نوع',
            'SKU',
            'محصول',
            'عملیات',
            'تغییر',
            'پیام',
            'درخواست',
            'پاسخ',
            'قبل',
            'بعد',
        ] );

        do {
            $result = self::get_logs( $page, $per_page, $filters );

            foreach ( $result['items'] as $row ) {
                $product_title = '';
                if ( ! empty( $row['product_id'] ) ) {
                    $product_title = get_the_title( (int) $row['product_id'] );
                }

                fputcsv( $output, [
                    $row['id'],
                    $row['created_at'],
                    $row['sync_type'],
                    $row['sku'],
                    $product_title,
                    $row['action'],
                    $row['has_changes'] ? 'بله' : 'خیر',
                    $row['message'],
                    $row['request_data'],
                    $row['response_data'],
                    $row['old_data'],
                    $row['new_data'],
                ] );
            }

            $page++;
        } while ( $page <= $result['total_pages'] );

        fclose( $output );
        exit;
    }

    public static function get_notification_emails(): array
    {
        $raw = (string) get_option( 'odoo_error_notification_emails', '' );
        if ( $raw === '' ) {
            return [];
        }

        $parts  = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        $emails = [];

        foreach ( $parts as $part ) {
            $email = sanitize_email( trim( $part ) );
            if ( is_email( $email ) ) {
                $emails[] = $email;
            }
        }

        return array_values( array_unique( $emails ) );
    }

    public static function notify_error( array $args ): void
    {
        self::notify_error_emails( $args );
        self::add_admin_notification( $args );
        do_action( 'zarsam_odoo_report_issue', $args );
    }

    public static function notify_error_emails( array $args ): void
    {
        $emails = self::get_notification_emails();
        if ( empty( $emails ) ) {
            return;
        }

        $dedup_key = 'zarsam_odoo_err_mail_' . self::build_error_dedup_key( $args );
        if ( get_transient( $dedup_key ) ) {
            return;
        }

        set_transient( $dedup_key, 1, 2 * MINUTE_IN_SECONDS );

        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $action    = sanitize_text_field( (string) ( $args['action'] ?? 'درخواست' ) );
        $subject   = sprintf( '[%s] خطای Odoo: %s', $site_name, $action );

        wp_mail(
            $emails,
            $subject,
            self::build_error_email_body( $args ),
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
    }

    public static function add_admin_notification( array $args ): void
    {
        $dedup_key = 'zarsam_odoo_err_admin_' . self::build_error_dedup_key( $args );
        if ( get_transient( $dedup_key ) ) {
            return;
        }

        set_transient( $dedup_key, 1, 2 * MINUTE_IN_SECONDS );

        $notifications = get_option( self::ADMIN_NOTIFICATIONS_OPTION, [] );
        if ( ! is_array( $notifications ) ) {
            $notifications = [];
        }

        array_unshift( $notifications, [
            'id'        => uniqid( 'odoo_', true ),
            'time'      => current_time( 'mysql' ),
            'sync_type' => sanitize_text_field( (string) ( $args['sync_type'] ?? '' ) ),
            'action'    => sanitize_text_field( (string) ( $args['action'] ?? '' ) ),
            'sku'       => sanitize_text_field( (string) ( $args['sku'] ?? '' ) ),
            'message'   => sanitize_text_field( (string) ( $args['message'] ?? '' ) ),
            'read'      => false,
        ] );

        $notifications = array_slice( $notifications, 0, self::MAX_ADMIN_NOTIFICATIONS );
        update_option( self::ADMIN_NOTIFICATIONS_OPTION, $notifications, false );
    }

    public static function get_admin_notifications( int $limit = 20 ): array
    {
        $notifications = get_option( self::ADMIN_NOTIFICATIONS_OPTION, [] );
        if ( ! is_array( $notifications ) ) {
            return [];
        }

        return array_slice( $notifications, 0, $limit );
    }

    public static function get_unread_notification_count(): int
    {
        $notifications = get_option( self::ADMIN_NOTIFICATIONS_OPTION, [] );
        if ( ! is_array( $notifications ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $notifications as $notification ) {
            if ( empty( $notification['read'] ) ) {
                $count++;
            }
        }

        return $count;
    }

    public static function mark_all_notifications_read(): void
    {
        $notifications = get_option( self::ADMIN_NOTIFICATIONS_OPTION, [] );
        if ( ! is_array( $notifications ) ) {
            return;
        }

        foreach ( $notifications as &$notification ) {
            $notification['read'] = true;
        }
        unset( $notification );

        update_option( self::ADMIN_NOTIFICATIONS_OPTION, $notifications, false );
    }

    private static function should_notify_error( array $data ): bool
    {
        if ( ! empty( $data['suppress_notify'] ) ) {
            return false;
        }

        if ( ! empty( $data['is_error'] ) ) {
            return true;
        }

        $message = (string) ( $data['message'] ?? '' );
        if ( $message !== '' && preg_match( '/خطا|ناموفق|نامعتبر|rate_fetch_failed/u', $message ) ) {
            return true;
        }

        return self::response_contains_error( $data['response_data'] ?? null );
    }

    private static function response_contains_error( $response_data ): bool
    {
        if ( is_array( $response_data ) ) {
            return ! empty( $response_data['error'] );
        }

        if ( is_string( $response_data ) && $response_data !== '' ) {
            $decoded = json_decode( $response_data, true );
            return is_array( $decoded ) && ! empty( $decoded['error'] );
        }

        return false;
    }

    private static function build_error_dedup_key( array $args ): string
    {
        $signature = [
            'sync_type'     => $args['sync_type'] ?? '',
            'action'        => $args['action'] ?? '',
            'sku'           => $args['sku'] ?? '',
            'message'       => $args['message'] ?? '',
            'response_data' => $args['response_data'] ?? null,
        ];

        return md5( wp_json_encode( $signature, JSON_UNESCAPED_UNICODE ) );
    }

    private static function build_error_email_body( array $args ): string
    {
        $lines = [
            'یک خطا در درخواست Odoo/Zarsam رخ داده است.',
            '',
            'زمان: ' . current_time( 'mysql' ),
            'سایت: ' . home_url(),
            'نوع: ' . ( $args['sync_type'] ?? '-' ),
            'عملیات: ' . ( $args['action'] ?? '-' ),
            'SKU / شناسه: ' . ( $args['sku'] ?? '-' ),
            'پیام: ' . ( $args['message'] ?? '-' ),
        ];

        if ( ! empty( $args['product_id'] ) ) {
            $lines[] = 'شناسه محصول/کاربر: ' . (int) $args['product_id'];
        }

        if ( ! empty( $args['request_data'] ) ) {
            $lines[] = '';
            $lines[] = 'درخواست:';
            $lines[] = self::encode( $args['request_data'] );
        }

        if ( ! empty( $args['response_data'] ) ) {
            $lines[] = '';
            $lines[] = 'پاسخ:';
            $lines[] = self::encode( $args['response_data'] );
        }

        return implode( "\n", $lines );
    }

    private static function encode( $data ): ?string
    {
        if ( $data === null ) {
            return null;
        }

        if ( is_string( $data ) ) {
            return $data;
        }

        return wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
    }
}
