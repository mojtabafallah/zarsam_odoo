<?php

namespace Mojtaba\ZarsamOdoo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SyncLogger
{
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
