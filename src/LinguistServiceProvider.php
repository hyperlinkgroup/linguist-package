<?php

namespace Hyperlinkgroup\Linguist;

use Hyperlinkgroup\Linguist\Commands\LinguistCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LinguistServiceProvider extends PackageServiceProvider
{
	public function configurePackage(Package $package): void
	{
		$package
			->name('linguist')
			->hasConfigFile()
			->hasCommand(LinguistCommand::class);
	}

	public function boot(): void
	{
		parent::boot();

		$this->app->singleton(Linguist::class, function () {
			return new Linguist();
		});
	}
}
