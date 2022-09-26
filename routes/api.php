<?php

use App\Http\Controllers\BarginFinderMaxController;
use App\Http\Controllers\FinancialDetailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LinkMyDealsCoupon;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::resource('coupons', LinkMyDealsCoupon::class);
Route::post('/financial-details', [FinancialDetailController::class,'index']);


Route::post('search',  [BarginFinderMaxController::class,'index']);
Route::get('autocomplete',  [BarginFinderMaxController::class,'airports']);

Route::post('search/filter',  [BarginFinderMaxController::class,'filter']);

Route::get('paginate-results',  [BarginFinderMaxController::class,'paginate']);

Route::get('/flights/{tagId}/{tripType}',  [BarginFinderMaxController::class,'show']);
