<?php

use Hyperlinkgroup\Linguist\Actions\SyncTranslations;
use Hyperlinkgroup\Linguist\Events\PullCompleted;
use Hyperlinkgroup\Linguist\Events\PushCompleted;
use Hyperlinkgroup\Linguist\Events\SyncCompleted;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
	$this->syncTranslationsPreviousLangPath = $this->app->langPath();
	$isolatedLangPath = storage_path('linguist-sync-translations-' . uniqid('', true));
	File::ensureDirectoryExists($isolatedLangPath);
	$this->app->useLangPath($isolatedLangPath);
});

afterEach(function () {
	cleanTranslationFiles();
	if (isset($this->syncTranslationsPreviousLangPath)) {
		$this->app->useLangPath($this->syncTranslationsPreviousLangPath);
		unset($this->syncTranslationsPreviousLangPath);
	}
});

test('sync dispatches sync completion event only', function () {
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
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
		'https://api.linguist.eu/export/de' => Http::response([
			'hello' => 'Hallo',
		], 200),
	]);

	Event::fake([
		SyncCompleted::class,
		PushCompleted::class,
		PullCompleted::class,
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new SyncTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue();

	Event::assertDispatched(SyncCompleted::class, function (SyncCompleted $event) {
		return $event->projectSlug === 'test-project'
			&& $event->result->overallSuccess === true;
	});
	Event::assertNotDispatched(PushCompleted::class);
	Event::assertNotDispatched(PullCompleted::class);
});
