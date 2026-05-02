<?php

namespace Zielu92\FilamentImageLabeler\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Zielu92\FilamentImageLabeler\FilamentImageLabeler
 */
class FilamentImageLabeler extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Zielu92\FilamentImageLabeler\FilamentImageLabeler::class;
    }
}
