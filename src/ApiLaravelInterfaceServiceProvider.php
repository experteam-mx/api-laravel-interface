<?php

namespace Experteam\ApiLaravelInterface;

use Illuminate\Support\ServiceProvider;

class ApiLaravelInterfaceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        app()->bind('InterfaceService', function () {
            return new \Experteam\ApiLaravelInterface\Utils\InterfaceService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
