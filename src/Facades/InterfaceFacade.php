<?php

namespace Experteam\ApiLaravelInterface\Facades;


class InterfaceFacade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor()
    {
        return 'InterfaceService';
    }
}
