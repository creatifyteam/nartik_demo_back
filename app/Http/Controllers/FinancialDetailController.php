<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponseTrait;
use Exception;
use Illuminate\Http\Request;
use TomorrowIdeas\Plaid\Plaid;
use Illuminate\Support\Facades\Validator;

class FinancialDetailController extends Controller
{
    use ApiResponseTrait;

    private $client_id = '62a0807992894d0012b1f057';
    private $client_secret = '81949f1ae620a967c5f8135b1537e9';
    private $plaid_products = 'auth,transactions,identity,assets,income,investments,liabilities';
    private $access_token = 'access-sandbox-ec00706f-2b7f-4216-9da4-d48800e2b3db';
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $validator =Validator::make($request->all(), [
            'access_token' =>['required'],
        ]);


        if ($validator->fails()) {
            return $this->apiResponse(null,'invalid input',$validator->messages(),422);
        }
        try{
            $plaid = new Plaid($this->client_id, $this->client_secret, "sandbox");
            $auth = $plaid->auth->get($request->access_token);

            // $balance = $plaid->accounts->getBalance($this->access_token);
            return $this->apiResponse($auth);

        }catch(Exception $e){
            return $this->apiResponse(null,'error',$e->getMessage(),400);

        }

        }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
