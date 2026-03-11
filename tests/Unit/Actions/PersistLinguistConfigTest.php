<?php

use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Illuminate\Support\Facades\File;

afterEach(function () {
	$envPath = base_path('.env');
	if (File::exists($envPath)) {
		File::delete($envPath);
	}
});

test('action persists config values to env file', function () {
	$action = new PersistLinguistConfig();

	$results = $action->handle([
		'token' => 'test-token-123',
		'project' => 'my-project',
		'url' => 'https://api.linguist.eu',
	]);

	expect($results)->toHaveKey('token')
		->and($results['token'])->toBeTrue()
		->and($results['project'])->toBeTrue();

	$envContent = File::get(base_path('.env'));
	expect($envContent)->toContain('LINGUIST_TOKEN=test-token-123')
		->and($envContent)->toContain('LINGUIST_PROJECT=my-project');
});

test('action escapes values with special characters', function () {
	$action = new PersistLinguistConfig();

	$action->handle([
		'token' => 'token with spaces and #hash',
	]);

	$envContent = File::get(base_path('.env'));
	expect($envContent)->toContain('"');
});

test('action updates existing values', function () {
	$action = new PersistLinguistConfig();

	// First write
	$action->handle(['token' => 'first-token']);

	// Second write should update
	$action->handle(['token' => 'second-token']);

	$envContent = File::get(base_path('.env'));
	expect($envContent)->toContain('LINGUIST_TOKEN=second-token')
		->and($envContent)->not->toContain('first-token');
});

test('action masks token for display', function () {
	$action = new PersistLinguistConfig();
	config(['linguist.token' => 'test-token-1234']);

	$masked = $action->getConfigSummary();

	expect($masked['token'])->toContain('...');
});

test('action can be run as an invokable', function () {
	$action = new PersistLinguistConfig();

	$results = $action(['token' => 'invokable-token']);

	expect($results['token'])->toBeTrue();
});
