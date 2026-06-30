<?php

namespace Hyperlinkgroup\Linguist;

use Hyperlinkgroup\Linguist\Actions\CollectLocalTranslations;
use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Actions\SyncTranslations;
use Hyperlinkgroup\Linguist\Commands\LinguistCommand;
use Hyperlinkgroup\Linguist\Commands\LinguistSetupCommand;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LinguistServiceProvider extends PackageServiceProvider
{
	public function configurePackage(Package $package): void
	{
		$package
			->name('linguist')
			->hasConfigFile()
			->hasCommands([
				LinguistCommand::class,
				LinguistSetupCommand::class,
			]);
	}

	public function registeringPackage(): void
	{
		parent::registeringPackage();

		// Register legacy Linguist class
		$this->app->singleton(Linguist::class, function () {
			return new Linguist;
		});

		// Register API Client as transient — setProjectSlug() mutates instance state
		$this->app->bind(LinguistApiClient::class, function () {
			return new LinguistApiClient(
				baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
				token: config('linguist.token', ''),
				projectSlug: config('linguist.project', ''),
			);
		});

		// Stateless actions can be singletons
		$this->app->singleton(PersistLinguistConfig::class);
		$this->app->singleton(CollectLocalTranslations::class);

		// Stateful actions (hold a LinguistApiClient reference) must be transient
		$this->app->bind(PullTranslations::class);
		$this->app->bind(PushTranslations::class);
		$this->app->bind(SyncTranslations::class);
	}

	public function boot(): void
	{
		parent::boot();
	}
}
