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
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=:' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/DE?prefix=:' => Http::response([
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
