<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Traits\CallApiTrait;
use App\Traits\getAccessTokenTrait;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(CallApiTrait::class, function ($request, $parameters) {
            if (isset($parameters['body'])) {
                return CallApiTrait::callPostApi($parameters['url'], getAccessTokenTrait::getAccessToken(), $parameters['body']);
            } else {
                return CallApiTrait::callGetApi($parameters['url'], getAccessTokenTrait::getAccessToken());
            }
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
