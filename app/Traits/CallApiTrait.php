<?php

namespace App\Traits;

use GuzzleHttp\Client as GuzzleHttp;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException ;

trait CallApiTrait
{
    /* this function is to call sabre in apis that needs get requests
    $url : the url that will be called, with its parameters
    $access_token: a valid session less access-token
    return :
        success : array of data
        error   : exception instance of GuzzleHttp\Exception\GuzzleException
    */
    public static function callGetApi($url, $access_token)
    {


        try {
            $response = Http::withToken($access_token)->withHeaders(
                [ 'Content-Type' => 'application/json']
            )->get($url);

            $response->throw();
        } catch (\Throwable $e) {
            return $e;
        }

            return $response;
    }

    /* this function is to call sabre in apis that needs post requests
    $url : the url that will be called, with its parameters
    $access_token: a valid session less access-token
    $body : the body of the request that will be sent to sabre
    return :
        success : array of data
        error   : exception instance of GuzzleHttp\Exception\GuzzleException
    */
    public static function callPostApi($url, $access_token, $body)
    {
        try {

            $response = Http::withToken($access_token)->withHeaders(
                [ 'Content-Type' => 'application/json']
            )->post($url,$body);
            $response->throw();
        } catch (\Throwable $e) {
            return $e;
        }

        return $response;
    }
}
