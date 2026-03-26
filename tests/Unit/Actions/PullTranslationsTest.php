<?php

use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\Http;

afterEach(function () {
	cleanTranslationFiles();
});

test('pull counts processed keys from downloaded translation files', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
			'goodbye' => 'Goodbye',
		], 200),
		'https://api.linguist.eu/export/de' => Http::response([
			'hello' => 'Hallo',
			'goodbye' => 'Tschuess',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(4)
		->and($result->languageResults)->toHaveKeys(['EN', 'DE']);
});

test('pull supports language objects from languages endpoint', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => [
				['id' => 1, 'code' => 'en'],
				['id' => 2, 'code' => 'DE'],
			],
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
		'https://api.linguist.eu/export/de' => Http::response([
			'hello' => 'Hallo',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(2)
		->and($result->languageResults)->toHaveKeys(['EN', 'DE']);
});

test('pull retries export download without prefix when prefixed export is empty', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en-prefixed',
		], 200),
		'https://api.linguist.eu/export/en-prefixed' => Http::response([], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN' => Http::response([
			'url' => 'https://api.linguist.eu/export/en-no-prefix',
		], 200),
		'https://api.linguist.eu/export/en-no-prefix' => Http::response([
			'welcome' => 'Welcome',
			'bye' => 'Bye',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(2)
		->and($result->languageResults)->toHaveKey('EN');
});
