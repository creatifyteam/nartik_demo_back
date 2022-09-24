<?php

namespace App\Traits;
use Response;

trait ApiResponseTrait
{
    /*{
    'data' =>
    'status' => true , false
    'error' =>
    }*/
    public function apiResponse($data = null,$message =null, $error = null, $code = 200)
    {
        $array = [
            'data' => $data,
            'code' => $code,
            'message' => $message,
            'status' => in_array($code, successCode()),
            'error' => $error,
        ];
        return Response::json($array, $code);

    }
}
