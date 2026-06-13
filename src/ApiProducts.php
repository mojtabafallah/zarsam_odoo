<?php

namespace Mojtaba\ZarsamOdoo;

class ApiProducts
{
    public function zarsam_odoo_update_product( \WP_REST_Request $request ): \WP_REST_Response
    {
        $data = $request->get_json_params();

        if ( empty( $data ) || !is_array( $data ) ) {
            $data = $request->get_params();
        }

        $sync   = OdooWooSync::get_instance();
        $result = $sync->process_odoo_api_product( $data );

        if ( empty( $result[ 'success' ] ) ) {
            return getResponse(
                [],
                $result[ 'message' ] ?? 'خطا در بروزرسانی محصول',
                $result[ 'status' ] ?? 400,
                false
            );
        }

        $message = $result[ 'message' ] ?? 'محصول با موفقیت بروزرسانی شد';
        unset( $result[ 'success' ], $result[ 'status' ], $result[ 'message' ] );

        return getResponse( $result, $message );
    }
}
