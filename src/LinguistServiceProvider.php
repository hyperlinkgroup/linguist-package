<?php

namespace Hyperlinkgroup\Linguist;

use Hyperlinkgroup\Linguist\Commands\LinguistCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
