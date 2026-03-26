<?php

use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Http\Client\Request;
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
		'https://api.linguist.eu/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/projects/test-project/translation-keys' => Http::response([
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
		->and($result->keysProcessed)->toBe(4)
		->and($result->keysFailed)->toBe(0);

	Http::assertSentCount(2);

	Http::assertSent(function (Request $request) {
		if ($request->url() !== 'https://api.linguist.eu/projects/test-project/translation-keys') {
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
		'https://api.linguist.eu/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/projects/test-project/translation-keys' => Http::response([
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
