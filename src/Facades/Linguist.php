<?php

namespace Hyperlinkgroup\Linguist\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hyperlinkgroup\Linguist\Linguist
 */
class Linguist extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Hyperlinkgroup\Linguist\Linguist::class;
    }
}
