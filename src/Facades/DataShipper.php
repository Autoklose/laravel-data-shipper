<?php

namespace Autoklose\DataShipper\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Autoklose\DataShipper\DataShipper
 */
class DataShipper extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Autoklose\DataShipper\DataShipper::class;
    }
}
