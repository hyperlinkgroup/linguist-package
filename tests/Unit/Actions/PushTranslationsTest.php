<?php

use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Events\PushCompleted;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
	$this->pushTranslationsPreviousLangPath = $this->app->langPath();
	$isolatedLangPath = storage_path('linguist-push-translations-' . uniqid('', true));
	File::ensureDirectoryExists($isolatedLangPath);
	$this->app->useLangPath($isolatedLangPath);
});

afterEach(function () {
	cleanTranslationFiles();
	if (isset($this->pushTranslationsPreviousLangPath)) {
		$this->app->useLangPath($this->pushTranslationsPreviousLangPath);
		unset($this->pushTranslationsPreviousLangPath);
	}
});

test('push sends one request per unique key with merged language payload', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(2)
		->and($result->keysFailed)->toBe(0);

	Http::assertSentCount(3);

	Http::assertSent(function (Request $request) {
		if ($request->url() !== 'https://api.linguist.eu/v2/projects/test-project/translation-keys') {
			return false;
		}

		$data = $request->data();

		if (! isset($data['keys']) || ! is_array($data['keys'])) {
			return false;
		}

		$firstEntry = $data['keys'][0] ?? null;

		if (! is_array($firstEntry)) {
			return false;
		}

		return isset($firstEntry['translations'][10], $firstEntry['translations'][20]);
	});
});

test('push reports progress for each uploaded key', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$progressEvents = [];

	$action = new PushTranslations($client);
	$result = $action->handle(
		'test-project',
		false,
		null,
		function (int $current, int $total, string $key) use (&$progressEvents): void {
			$progressEvents[] = compact('current', 'total', 'key');
		}
	);

	expect($result->overallSuccess)->toBeTrue()
		->and($progressEvents)->toHaveCount(2)
		->and($progressEvents[0]['current'])->toBe(1)
		->and($progressEvents[0]['total'])->toBe(2)
		->and($progressEvents[1]['current'])->toBe(2)
		->and($progressEvents[1]['total'])->toBe(2);
});

test('push dispatches completion event on success', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	Event::fake([PushCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue();

	Event::assertDispatched(PushCompleted::class, function (PushCompleted $event) {
		return $event->projectSlug === 'test-project'
			&& $event->result->overallSuccess === true;
	});
});

test('push does not dispatch completion event on failure', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [],
		], 200),
	]);

	Event::fake([PushCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeFalse();

	Event::assertNotDispatched(PushCompleted::class);
});

test('push converts laravel variable syntax to linguist format', function () {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('en.json'), json_encode([
		'welcome' => 'Welcome, :name!',
		'count' => 'You have :count messages',
		'no_var' => 'No variables here',
	], JSON_PRETTY_PRINT));

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$action->handle('test-project');

	Http::assertSent(function (Request $request): bool {
		if ($request->url() !== 'https://api.linguist.eu/v2/projects/test-project/translation-keys') {
			return false;
		}

		$keys = collect($request->data()['keys'] ?? []);

		$welcome = $keys->firstWhere('key', 'welcome');
		$count = $keys->firstWhere('key', 'count');
		$noVar = $keys->firstWhere('key', 'no_var');

		return $welcome['translations'][10] === 'Welcome, {{ name }}!'
			&& $count['translations'][10] === 'You have {{ count }} messages'
			&& $noVar['translations'][10] === 'No variables here';
	});
});

test('push skips existing remote keys when overwrite is false', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [
				['id' => 9, 'key' => 'hello'],
			],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$result = $action->handle('test-project', overwrite: false);

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(1);

	Http::assertSent(function (Request $request): bool {
		if ($request->url() !== 'https://api.linguist.eu/v2/projects/test-project/translation-keys') {
			return false;
		}

		$keys = $request->data()['keys'] ?? [];

		return count($keys) === 1 && ($keys[0]['key'] ?? null) === 'goodbye';
	});
});

test('push uploads existing remote keys when overwrite is true', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PushTranslations($client);
	$result = $action->handle('test-project', overwrite: true);

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(2);

	Http::assertNotSent(function (Request $request): bool {
		return $request->url() === 'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1';
	});
});
