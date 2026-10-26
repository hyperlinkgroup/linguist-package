<?php

use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Events\PullCompleted;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

afterEach(function () {
	cleanTranslationFiles();
});

test('pull counts processed keys from downloaded translation files', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
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
		->and($result->keysProcessed)->toBe(2)
		->and($result->languageResults)->toHaveKeys(['EN', 'DE']);
});

test('pull supports language objects from languages endpoint', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => [
				['id' => 1, 'code' => 'en'],
				['id' => 2, 'code' => 'DE'],
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

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and($result->keysProcessed)->toBe(1)
		->and($result->languageResults)->toHaveKeys(['EN', 'DE']);
});

test('pull reports processed keys using largest successful language payload', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
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
		->and($result->keysProcessed)->toBe(2)
		->and($result->languageResults)->toHaveKeys(['EN', 'DE']);
});

test('pull retries export download without prefix when prefixed export is empty', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en-prefixed',
		], 200),
		'https://api.linguist.eu/export/en-prefixed' => Http::response([], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN' => Http::response([
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

test('pull writes lowercase laravel locale json files', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
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

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue()
		->and(File::exists(lang_path('en.json')))->toBeTrue()
		->and(File::exists(lang_path('de.json')))->toBeTrue()
		->and(File::exists(lang_path('EN/linguist.json')))->toBeFalse()
		->and(File::exists(lang_path('DE/linguist.json')))->toBeFalse();
});

test('pull removes existing legacy managed language file', function () {
	File::ensureDirectoryExists(lang_path('DE'));
	File::put(lang_path('DE/linguist.json'), json_encode(['old' => 'value']));

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'DE']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
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
		->and(File::exists(lang_path('de.json')))->toBeTrue()
		->and(File::exists(lang_path('DE/linguist.json')))->toBeFalse();
});

test('pull dispatches completion event on success', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	Event::fake([PullCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeTrue();

	Event::assertDispatched(PullCompleted::class, function (PullCompleted $event) {
		return $event->projectSlug === 'test-project'
			&& $event->result->overallSuccess === true;
	});
});

test('pull writes pretty-printed json by default', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$action->handle('test-project');

	$content = File::get(lang_path('en.json'));
	expect($content)->toContain("\n");
});

test('pull writes minified json when pull_minified is enabled', function () {
	config(['linguist.pull_minified' => true]);

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$action->handle('test-project');

	$content = File::get(lang_path('en.json'));
	expect(trim($content))->toBe('{"hello":"Hello"}');
});

test('pull does not dispatch completion event on failure', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([], 500),
	]);

	Event::fake([PullCompleted::class]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$action = new PullTranslations($client);
	$result = $action->handle('test-project');

	expect($result->overallSuccess)->toBeFalse();

	Event::assertNotDispatched(PullCompleted::class);
});

test('pull keeps the existing file when the download is not a json object', function (mixed $downloadResponse) {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo"}');

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'DE']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/DE*' => Http::response([
			'url' => 'https://api.linguist.eu/export/de',
		], 200),
		'https://api.linguist.eu/export/de' => $downloadResponse,
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->getFailedLanguages())->toBe(['DE'])
		->and(File::get(lang_path('de.json')))->toBe('{"hello": "Hallo"}');
})->with([
	'html error page with status 200' => fn () => Http::response('<html><body>Bad Gateway</body></html>', 200),
	'json list' => fn () => Http::response(['a', 'b'], 200),
]);

test('pull refuses language codes that are not language tags', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['../x'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->getFailedLanguages())->toBe(['../X'])
		->and(File::exists(lang_path('en.json')))->toBeTrue()
		->and(File::exists(dirname(lang_path()) . '/x.json'))->toBeFalse();

	Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->url(), '/export/json/..'));
});

test('pull leaves skipped languages untouched', function () {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"local": "Nur lokal"}');

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['EN', 'DE'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/en',
		], 200),
		'https://api.linguist.eu/export/en' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project', skipLanguages: ['de']);

	expect($result->getFailedLanguages())->toBe(['DE'])
		->and($result->languageResults['EN']['success'])->toBeTrue()
		->and(File::get(lang_path('de.json')))->toBe('{"local": "Nur lokal"}')
		->and(json_decode(File::get(lang_path('en.json')), true))->toBe(['hello' => 'Hello']);
});

test('pull marks the run as aborted when it stops unexpectedly', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::failedConnection(),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project');

	expect($result->overallSuccess)->toBeFalse()
		->and($result->aborted)->toBeTrue();
});

test('pull matches skipped languages regardless of the separator', function () {
	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'pt-BR']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['pt-BR'],
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project', skipLanguages: ['PT_BR']);

	expect($result->getFailedLanguages())->toBe(['PT-BR'])
		->and(File::exists(lang_path('pt-br.json')))->toBeFalse();
});

test('pull records a write failure for one language and continues with the others', function () {
	// A directory where the language file should be makes the write fail.
	File::ensureDirectoryExists(lang_path('de.json'));

	Http::preventStrayRequests();
	Http::fake([
		'https://api.linguist.eu/v2/projects/test-project' => Http::response([
			'data' => ['language' => ['code' => 'EN']],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/languages' => Http::response([
			'data' => ['DE', 'EN'],
		], 200),
		'https://api.linguist.eu/v2/projects/test-project/export/json/*' => Http::response([
			'url' => 'https://api.linguist.eu/export/any',
		], 200),
		'https://api.linguist.eu/export/any' => Http::response([
			'hello' => 'Hello',
		], 200),
	]);

	$client = new LinguistApiClient(
		baseUrl: 'https://api.linguist.eu',
		token: 'test-token',
		projectSlug: 'test-project',
	);

	$result = (new PullTranslations($client))->handle('test-project');

	expect($result->aborted)->toBeFalse()
		->and($result->languageResults['DE'])->toBe(['success' => false, 'message' => "Could not write '" . lang_path('de.json') . "'."])
		->and($result->languageResults['EN']['success'])->toBeTrue()
		->and(json_decode(File::get(lang_path('en.json')), true))->toBe(['hello' => 'Hello']);

	File::deleteDirectory(lang_path('de.json'));
});
