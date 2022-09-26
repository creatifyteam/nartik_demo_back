<?php

namespace App\Traits;

use \GuzzleHttp\Client;
use  Session;
use Config;
use App\Models\Accesstoken;

trait getAccessTokenTrait
{
    /** check expire date of access token */
    public static function checkExpDate($accesstokenObj)
    {
        $is_valid = false;
        if ($accesstokenObj) {
            $subTime = $accesstokenObj->expires_in - time();
            if ($subTime > 0)
                $is_valid = true;
        }
        return $is_valid;
    }

    /** Generate new access token */
    public static function generateAccessToken($accesstokenObj)
    {

        $client = new Client();
        $encoded_access_token = Config::get('sabre.encoded_clientid_and_secretkey', 'VmpFNk1Xd3hOVzl6Tkc5NWJtd3dlbUoyYWpwRVJWWkRSVTVVUlZJNlJWaFU6ZGtWV1REVnpObTA9'); //for test api
        try {
            $response = $client->request('POST', Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com') . '/v2/auth/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . $encoded_access_token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'grant_type' => 'client_credentials'
                ]
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            if ($e->hasResponse()) {
                return $response = $e->getResponse()->getBody(true);
            }
            return $e->getMessage();
        }
        $response = $response->getBody()->getContents();
        $response = json_decode($response);
        $expires_in = time() + $response->expires_in;
        if ($accesstokenObj) {
            $accesstokenObj->Update([
                'access_token' => $response->access_token,
                'expires_in' => $expires_in,
                'token_type' => $response->token_type
            ]);
        } else {
            $accesstokenObj = Accesstoken::Create([
                'access_token' => $response->access_token,
                'expires_in' => $expires_in,
                'token_type' => $response->token_type
            ]);
        }
        return $accesstokenObj->access_token;
    }

    /** get access token */
    public static function getAccessToken()
    {
        $accesstokenObj = Accesstoken::first();
        if (self::checkExpDate($accesstokenObj)) {
            $access_token = $accesstokenObj->access_token;
        } else {
            $access_token = self::generateAccessToken($accesstokenObj);
        }
        return $access_token;
    }
}
