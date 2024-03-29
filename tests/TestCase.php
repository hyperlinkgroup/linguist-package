<?php

namespace Hyperlinkgroup\Linguist\Tests;

use Hyperlinkgroup\Linguist\LinguistServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
	protected function setUp(): void
	{
		parent::setUp();

		Factory::guessFactoryNamesUsing(
			static fn (string $modelName) => 'Hyperlinkgroup\\Linguist\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
		);
	}

	protected function getPackageProviders($app): array
	{
		return [
			LinguistServiceProvider::class,
		];
	}

	public function getEnvironmentSetUp($app): void
	{
		config()->set('database.default', 'testing');

		/*
		$migration = include __DIR__.'/../database/migrations/create_skeleton_table.php.stub';
		$migration->up();
		*/
	}
}
