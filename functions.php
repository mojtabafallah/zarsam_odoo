<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function login( \WP_REST_Request $request )
{
    //Validation
    if ( !$request->get_param( 'user_name' ) || !$request->get_param( 'password' ) ) {
        wp_send_json_error( [ 'message' => 'Validation Error' ] );
    }

    $userName = $request->get_param( 'user_name' );
    $password = $request->get_param( 'password' );

    $user = wp_signon(
        [ 'user_login'    => $userName,
          'user_password' => $password ] );

    if ( is_wp_error( $user ) ) {

        wp_send_json_error(
            [
                'message' => $user->get_error_message()
            ], 401
        );
    }
    if ( current_user_can( 'administrator' ) ) {
        wp_send_json_error(
            [
                'message' => 'شما دسترسی ندارید'
            ], 401
        );
    }

    $key     = 'KkdikUSUiPFDbncIUIUDCDIUVCCudicush123iiu9woih9cachsd8ohc90wqhfo';
    $payload = [
        'iss'  => 'zarsam-rest-api',
        'aud'  => 'zarsam-profile',
        'iat'  => time(),
        'nbf'  => time(),
        'exp'  => time() + 3600,
        'data' => [
            'userId' => $user->ID
        ]
    ];

    $jwt = JWT::encode( $payload, $key, 'HS256' );

    return getResponse( [ 'token' => $jwt ], 'ورود با موفقیت انجام شد' );
}

function getResponse( $data, $message = 'Success', $status = 200, $success = true ): WP_REST_Response
{
    $result          = (object) [];
    $result->success = $success;
    $result->data    = $data;
    $result->message = $message;
    $result->status  = $status;

    $response = new WP_REST_Response();
    $response->set_status( $status );
    $response->set_data( $result );
    return $response;
}

function permission( \WP_REST_Request $request ): bool
{
    $token1 = $request->get_header( 'Authorization' );
    $token1 = getBearerToken( $token1 );

    $k = new Key( 'KkdikUSUiPFDbncIUIUDCDIUVCCudicush123iiu9woih9cachsd8ohc90wqhfo', 'HS256' );
    try {
        if ( is_null( $token1 ) ) return false;
        $token = JWT::decode( $token1, $k );
    } catch ( Exception $e ) {
        return false;
    }

    $now        = time();
    $serverName = "zarsam-rest-api";

    if ( $token->iss !== $serverName ||
        $token->nbf > $now ||
        $token->exp < $now ) {
        return false;
    }

    //get user
    $data     = $token->data;
    $userId   = $data->userId;
    $userItem = get_user_by( 'id', $userId );

    if ( !$userItem ) {
        return false;
    }

    if ( !in_array( 'administrator', $userItem->roles ) ) {
        return false;
    }
    return true;
}

function getBearerToken( $token )
{
    // HEADER: Get the access token from the header
    if ( preg_match( '/Bearer\s(\S+)/', $token, $matches ) ) {
        return $matches[ 1 ];
    }
    return null;
}