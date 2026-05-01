<?php

namespace Mojtaba\ZarsamOdoo;

class ApiProducts
{
    public function zarsam_odoo_update_product( \WP_REST_Request $request )
    {
        $warehouse_id  = $request->get_param( 'warehouse_id' );
        $product_id    = $request->get_param( 'product_id' );
        $price         = $request->get_param( 'price' );
        $category_name = $request->get_param( 'category_name' );
        $category_id   = $request->get_param( 'category_id' );
        $product_count = $request->get_param( 'product_count' );

        if ( !$product_id ) {
            return new \WP_REST_Response( [
                'status'  => false,
                'message' => 'product_id is required'
            ], 400 );
        }

        $product = wc_get_product( $product_id );

        if ( !$product ) {
            return new \WP_REST_Response( [
                'status'  => false,
                'message' => 'Product not found'
            ], 404 );
        }

        // آپدیت قیمت
        if ( $price !== null ) {
            $product->set_regular_price( $price );
        }

        // آپدیت موجودی
        if ( $product_count !== null ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $product_count );
        }

        // آپدیت دسته‌بندی
        if ( $category_id || $category_name ) {

            $term_id = $category_id;

            // اگر category_id نبود با نام بساز
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

        // ذخیره محصول
        $product->save();

        // ذخیره warehouse در متا (اختیاری)
        if ( $warehouse_id ) {
            update_post_meta( $product_id, '_warehouse_id', $warehouse_id );
        }

        return new \WP_REST_Response( [
            'status'     => true,
            'product_id' => $product_id,
            'message'    => 'Product updated successfully'
        ], 200 );
    }
}