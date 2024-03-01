<?php

namespace Hyperlinkgroup\Linguist\Facades;

use Hyperlinkgroup\Linguist\Linguist as LinguistClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @see LinguistClass
 *
 * @method static void handle()
 * @method static Collection getLanguages()
 * @method static LinguistClass setLanguages(Collection $collect)
 * @method static LinguistClass start()
 */
class Linguist extends Facade
{
	protected static function getFacadeAccessor(): string
	{
		return LinguistClass::class;
	}
}
