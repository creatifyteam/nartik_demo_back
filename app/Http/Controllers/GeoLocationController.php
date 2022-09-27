<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponseTrait;
use App\Traits\CallApiTrait;
use \GuzzleHttp\Client;
use Illuminate\Http\Request;
use Config;
class GeoLocationController extends Controller
{
    use ApiResponseTrait;

    //http://127.0.0.1:8000/api/geo-location?code=NYC&category=AIR

    public function geo_location(Request $request)
    {
		if(isset($request->category))
		{
			$category = $request->category;
		}
		else
		{
			$category = 'AIR';
		}

        $url =  Config::get('sabre.api_url', 'https://api-crt.cert.havail.sabre.com').'/v1/lists/utilities/geoservices/autocomplete?query=' . $request->code . '&' . $category .'&limit=5';

        $result = app()->makeWith(CallApiTrait::class, ['url' => $url]);

        if (!$result instanceof \Throwable)  // check if result is not containing any exception or error
        {
            if (array_key_exists('grouped', $result['Response'])) {

                if($category == 'AIR') {
                    $response = $result['Response']['grouped']['category:AIR']['doclist']['docs'];
                }elseif($category == 'CITY'){
                    $response = $result['Response']['grouped']['category:CITY']['doclist']['docs'];
                }

                $return_res = [];

                foreach ($response as $res) {
//                unset($res['name']);
//                unset($res['city']);
//                unset($res['country']);
                    unset($res['countryName']);
                    unset($res['stateName']);
                    unset($res['state']);
                    unset($res['category']);
//                unset($res['id']);
                    unset($res['dataset']);
                    unset($res['datasource']);
                    unset($res['confidenceFactor']);
//                    unset($res['latitude']);
//                    unset($res['longitude']);
                    unset($res['iataCityCode']);
                    unset($res['ranking']);
                    array_push($return_res, $res);
                }

                return $this->apiResponse($return_res, null, 200);
            } else {
                return $this->apiResponse(null, 'The search result empty.', 401);
            }
        }
        return $this->apiResponse(null, $result->getMessage(), $result->getCode());
    }
}
