<?php

use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\Http;

test('count translation keys reads numeric totals from nested metadata', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([
			'meta' => [
				'pagination' => [
					'total' => '758',
				],
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->countTranslationKeys())->toBe(758);
});

test('count translation keys reads totals from pagination headers', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([
			'data' => [['id' => 1]],
		], 200, [
			'X-Total-Count' => '1514',
		]),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->countTranslationKeys())->toBe(1514);
});

test('count translation keys throws when request fails', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([], 403),
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([], 403),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$client->countTranslationKeys();
})->throws(RuntimeException::class);

test('count translation keys falls back to export payload when list endpoint fails', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([], 403),
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'auth' => [
				'login' => 'Login',
				'logout' => 'Logout',
			],
			'common' => [
				'ok' => 'Ok',
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->countTranslationKeys())->toBe(3);
});

test('count translation keys fallback supports language objects from languages endpoint', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([], 403),
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => [
				['id' => 1, 'code' => 'en'],
				['id' => 2, 'code' => 'DE'],
			],
		], 200),
		'https://api.linguist.eu/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'auth' => [
				'login' => 'Login',
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->countTranslationKeys())->toBe(1);
});

test('count translation keys fallback retries export URL without prefix when prefixed export is empty', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects/test-project/translation-keys?per_page=1&page=1' => Http::response([], 403),
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
			'ok' => 'Ok',
			'cancel' => 'Cancel',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->countTranslationKeys())->toBe(2);
});

test('get project language id map falls back to single project when list omits languages', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'name' => 'Test',
			]],
		], 200),
		'https://api.linguist.eu/projects/test-project' => Http::response([
			'data' => [
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->getProjectLanguageIdMap())->toBe([
		'EN' => 10,
		'DE' => 20,
	]);
});

test('get project language id map reads available_languages from project payload', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'available_languages' => [
					['id' => 7, 'code' => 'fr'],
				],
			]],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->getProjectLanguageIdMap())->toBe(['FR' => 7]);
});

test('get project language id map falls back to languages endpoint with id and code objects', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
			]],
		], 200),
		'https://api.linguist.eu/projects/test-project' => Http::response([
			'data' => ['slug' => 'test-project'],
		], 200),
		'https://api.linguist.eu/projects/test-project/languages' => Http::response([
			'data' => [
				['id' => 1, 'code' => 'EN'],
				['id' => 2, 'code' => 'DE'],
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	expect($client->getProjectLanguageIdMap())->toBe([
		'EN' => 1,
		'DE' => 2,
	]);
});
