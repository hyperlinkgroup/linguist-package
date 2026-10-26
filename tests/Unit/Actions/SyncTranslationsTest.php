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
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => [
				'slug' => 'test-project',
				'language' => ['code' => 'EN'],
			],
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

test('sync defaults to remote as source and skips existing key updates on push', function () {
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
				['id' => 1, 'key' => 'hello'],
			],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => Http::response([
			'data' => ['id' => 1],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => [
				'slug' => 'test-project',
				'language' => ['code' => 'EN'],
			],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Remote Hello',
		], 200),
		'https://api.linguist.eu/export/de' => Http::response([
			'hello' => 'Remote Hallo',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new SyncTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue();

	Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
		if ($request->url() !== 'https://api.linguist.eu/v2/projects/test-project/translation-keys') {
			return false;
		}

		$keys = $request->data()['keys'] ?? [];

		return count($keys) === 1 && ($keys[0]['key'] ?? null) === 'goodbye';
	});
});

test('sync with local source overwrites existing remote keys', function () {
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
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => [
				'slug' => 'test-project',
				'language' => ['code' => 'EN'],
			],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Remote Hello',
		], 200),
		'https://api.linguist.eu/export/de' => Http::response([
			'hello' => 'Remote Hallo',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new SyncTranslations($client);
	$result = $action->handle('test-project', syncSource: 'local');

	expect($result->overallSuccess)->toBeTrue();

	Http::assertNotSent(function (\Illuminate\Http\Client\Request $request): bool {
		return $request->url() === 'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1';
	});

	Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
		if ($request->url() !== 'https://api.linguist.eu/v2/projects/test-project/translation-keys') {
			return false;
		}

		$keys = $request->data()['keys'] ?? [];

		return count($keys) === 2;
	});
});

/**
 * @return array<string, mixed>
 */
function syncApiFakesWithStore(mixed $storeResponse, mixed $existingKeysResponse = null): array
{
	return [
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => $existingKeysResponse ?? Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys' => $storeResponse,
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => [
				'slug' => 'test-project',
				'language' => ['code' => 'EN'],
			],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/*' => Http::response([
			'url' => 'https://api.linguist.eu/export/remote',
		], 200),
		'https://api.linguist.eu/export/remote' => Http::response([
			'hello' => 'Remote Hello',
		], 200),
	];
}

test('sync keeps local files byte-identical when the push fails', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);
	$enBefore = File::get(lang_path('en.json'));
	$deBefore = File::get(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake(syncApiFakesWithStore(Http::response(['message' => 'Server Error'], 500)));

	Event::fake([SyncCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->getFailedLanguages())->toContain('EN', 'DE')
		->and($result->languageResults['DE']['message'] ?? '')->toContain('Pull: Skipped because this language was not pushed successfully.')
		->and(File::get(lang_path('en.json')))->toBe($enBefore)
		->and(File::get(lang_path('de.json')))->toBe($deBefore);

	Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/export/'));
	Event::assertNotDispatched(SyncCompleted::class);
});

test('sync skips the whole pull when the push fails without per-language results', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);
	$enBefore = File::get(lang_path('en.json'));
	$deBefore = File::get(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake(syncApiFakesWithStore(
		storeResponse: Http::response(['data' => ['id' => 1]], 200),
		existingKeysResponse: Http::failedConnection(),
	));

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->errors)->toContain('Pull phase: Pull skipped because push failed.')
		->and(File::get(lang_path('en.json')))->toBe($enBefore)
		->and(File::get(lang_path('de.json')))->toBe($deBefore);

	Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/languages'));
});

test('sync does not pull languages that were not uploaded before the push', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);
	$deBefore = File::get(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		// DE is active remotely, but the language map cannot resolve its ID, so it is neither activated nor pushed.
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
				],
			]],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->languageResults['DE']['success'])->toBeFalse()
		->and($result->languageResults['EN']['success'])->toBeTrue()
		->and(File::get(lang_path('de.json')))->toBe($deBefore)
		->and(json_decode(File::get(lang_path('en.json')), true))->toBe(['hello' => 'Remote Hello']);
});

test('sync reports a language as failed when its remote activation did not take effect', function () {
	createTestTranslationFiles('test-project', ['EN', 'FR']);

	$projectWithoutFrench = [
		'data' => [[
			'slug' => 'test-project',
			'languages' => [
				['id' => 10, 'code' => 'EN'],
			],
		]],
	];

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		// FR can be activated, but the refreshed language map still does not contain it.
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::sequence()
			->push($projectWithoutFrench, 200)
			->push([
				'data' => [[
					'slug' => 'test-project',
					'languages' => [
						['id' => 10, 'code' => 'EN'],
					],
					'available_languages' => [
						['id' => 30, 'code' => 'FR'],
					],
				]],
			], 200)
			->whenEmpty(Http::response($projectWithoutFrench, 200)),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
	]);

	Event::fake([SyncCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->languageResults['FR'])->toBe([
			'success' => false,
			'message' => 'Failed to activate this local language remotely before upload.',
		])
		->and($result->languageResults['EN']['success'])->toBeTrue()
		->and($result->errors)->toContain('Failed to activate local languages remotely before upload: FR.');

	Event::assertNotDispatched(SyncCompleted::class);
});

test('sync does not overwrite a local file that is not valid json', function () {
	createTestTranslationFiles('test-project', ['EN']);
	File::put(lang_path('de.json'), "{\n    \"hello\": \"Hallo\",\n}\n");
	$deBefore = File::get(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake(syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)));

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->languageResults['DE']['success'])->toBeFalse()
		->and($result->errors)->toContain("Push phase: DE: Local file '" . lang_path('de.json') . "' is not valid JSON.")
		->and(File::get(lang_path('de.json')))->toBe($deBefore)
		->and(json_decode(File::get(lang_path('en.json')), true))->toBe(['hello' => 'Remote Hello']);
});

test('sync does not prune remote keys while a local file is not valid json', function () {
	createTestTranslationFiles('test-project', ['EN']);
	File::put(lang_path('de.json'), '{"only_in_de": "Nur hier",}');

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		'https://api.linguist.eu/v2/projects/test-project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [
				['id' => 7, 'key' => 'only_in_de'],
			],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project', pruneRemoteKeys: true);

	expect($result->overallSuccess)->toBeFalse()
		->and($result->errors)->toContain('Skipped pruning remote keys because local files are not valid JSON: ' . lang_path('de.json') . '.');

	Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->method() === 'DELETE');
});

test('sync reports a failed activation request only once', function () {
	createTestTranslationFiles('test-project', ['EN', 'FR']);

	$projectWithoutFrench = [
		'data' => [[
			'slug' => 'test-project',
			'languages' => [
				['id' => 10, 'code' => 'EN'],
			],
		]],
	];

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::sequence()
			->push($projectWithoutFrench, 200)
			->push([
				'data' => [[
					'slug' => 'test-project',
					'languages' => [
						['id' => 10, 'code' => 'EN'],
					],
					'available_languages' => [
						['id' => 30, 'code' => 'FR'],
					],
				]],
			], 200)
			->whenEmpty(Http::response($projectWithoutFrench, 200)),
		'https://api.linguist.eu/v2/projects/test-project' => fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'PATCH'
			? Http::response('Server Error', 500)
			: Http::response(['data' => ['slug' => 'test-project', 'language' => ['code' => 'EN']]], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->errors)->toBe(['Failed to activate missing local languages remotely: Server Error'])
		->and($result->languageResults['FR']['success'])->toBeFalse();
});

test('sync leaves languages untouched without failing when their upload was skipped on purpose', function () {
	createTestTranslationFiles('test-project', ['EN', 'DE']);
	$deBefore = File::get(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
				],
			]],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project', activateMissingLanguages: false);

	expect($result->overallSuccess)->toBeTrue()
		->and($result->languageResults['DE']['success'])->toBeTrue()
		->and($result->languageResults['DE']['message'])->toContain('Pull: Not pulled because its upload was skipped.')
		->and(File::get(lang_path('de.json')))->toBe($deBefore)
		->and(json_decode(File::get(lang_path('en.json')), true))->toBe(['hello' => 'Remote Hello']);
});

test('sync reports a language whose activation did not take effect next to an unresolvable one', function () {
	createTestTranslationFiles('test-project', ['EN', 'FR', 'IT']);

	$projectWithEnglishOnly = [
		'data' => [[
			'slug' => 'test-project',
			'languages' => [
				['id' => 10, 'code' => 'EN'],
			],
		]],
	];

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		// IT can be activated but stays missing from the refreshed map; FR has no remote ID at all.
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::sequence()
			->push($projectWithEnglishOnly, 200)
			->push([
				'data' => [[
					'slug' => 'test-project',
					'languages' => [
						['id' => 10, 'code' => 'EN'],
					],
					'available_languages' => [
						['id' => 40, 'code' => 'IT'],
					],
				]],
			], 200)
			->whenEmpty(Http::response($projectWithEnglishOnly, 200)),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->errors)->toBe([
			'Could not resolve remote language IDs for local languages: FR.',
			'Failed to activate local languages remotely before upload: IT.',
		]);
});

test('sync reports a language once when local and remote codes use different separators', function () {
	createTestTranslationFiles('test-project', ['EN']);
	File::put(lang_path('pt_BR.json'), '{"hello": "Olá"}');
	$ptBefore = File::get(lang_path('pt_BR.json'));

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'test-project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 50, 'code' => 'pt-BR'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'pt-BR'],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect(array_keys($result->languageResults))->toEqualCanonicalizing(['EN', 'PT_BR'])
		->and($result->languageResults['PT_BR']['success'])->toBeFalse()
		->and(File::get(lang_path('pt_BR.json')))->toBe($ptBefore)
		->and(File::exists(lang_path('pt-br.json')))->toBeFalse();
});

test('sync explains when activated languages cannot be reloaded', function () {
	createTestTranslationFiles('test-project', ['EN', 'IT']);

	$projectWithEnglishOnly = [
		'data' => [[
			'slug' => 'test-project',
			'languages' => [
				['id' => 10, 'code' => 'EN'],
			],
		]],
	];

	Http::preventStrayRequests();
	Http::fake([
		...syncApiFakesWithStore(Http::response(['data' => ['id' => 1]], 200)),
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::sequence()
			->push($projectWithEnglishOnly, 200)
			->push([
				'data' => [[
					'slug' => 'test-project',
					'languages' => [
						['id' => 10, 'code' => 'EN'],
					],
					'available_languages' => [
						['id' => 40, 'code' => 'IT'],
					],
				]],
			], 200)
			// The refresh after activation fails to deliver the project.
			->push(['data' => []], 200)
			->whenEmpty(Http::response($projectWithEnglishOnly, 200)),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new SyncTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->errors)->toBe(['Activated local languages remotely, but could not reload their IDs for upload: IT.']);
});
