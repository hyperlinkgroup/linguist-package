<?php

namespace Hyperlinkgroup\Linguist;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Hyperlinkgroup\Linguist\Commands\LinguistCommand;

class LinguistServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('linguist')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_linguist_table')
            ->hasCommand(LinguistCommand::class);
    }
}
