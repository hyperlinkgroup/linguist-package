<?php

use Hyperlinkgroup\Linguist\Actions\CollectLocalTranslations;
use Illuminate\Support\Facades\File;

afterEach(function () {
	cleanTranslationFiles();
});

test('action falls back to base_path lang when lang_path is not a usable directory', function () {
	$previous = $this->app->langPath();

	File::ensureDirectoryExists(base_path('lang/EN'));
	File::put(base_path('lang/EN/fallback.json'), json_encode(['k' => 'v'], JSON_PRETTY_PRINT));

	try {
		$this->app->useLangPath(storage_path('linguist-missing-lang-' . uniqid()));

		$action = new CollectLocalTranslations;
		$translations = $action->handle('fallback');

		expect($translations)->toHaveKey('EN')
			->and($translations['EN']['k'])->toBe('v')
			->and(CollectLocalTranslations::translationFileSearchRoots())->toBe([base_path('lang')]);
	} finally {
		$this->app->useLangPath($previous);
		if (File::isDirectory(base_path('lang'))) {
			File::deleteDirectory(base_path('lang'));
		}
	}
});

test('action falls back to base_path lang when lang_path is an empty string', function () {
	$previous = $this->app->langPath();

	File::ensureDirectoryExists(base_path('lang/EN'));
	File::put(base_path('lang/EN/empty-path.json'), json_encode(['x' => 'y'], JSON_PRETTY_PRINT));

	try {
		$this->app->useLangPath('');

		$action = new CollectLocalTranslations;
		$translations = $action->handle('empty-path');

		expect($translations)->toHaveKey('EN')
			->and($translations['EN']['x'])->toBe('y');
	} finally {
		$this->app->useLangPath($previous);
		if (File::isDirectory(base_path('lang'))) {
			File::deleteDirectory(base_path('lang'));
		}
	}
});

test('action collects translations from local directories', function () {
	File::ensureDirectoryExists(lang_path('DE'));
	File::ensureDirectoryExists(lang_path('EN'));
	File::ensureDirectoryExists(lang_path('spark'));

	File::put(lang_path('DE/linguist.json'), json_encode([
		'managed' => 'Managed DE',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('de.json'), json_encode([
		'hello' => 'Hallo',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('EN/linguist.json'), json_encode([
		'managed' => 'Managed EN',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('spark/en.json'), json_encode([
		'goodbye' => 'Bye from spark',
	], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$translations = $action->handle();

	expect($translations)->toHaveKeys(['EN', 'DE'])
		->and($translations['EN'])->toHaveKeys(['managed', 'goodbye'])
		->and($translations['DE'])->toHaveKeys(['managed', 'hello']);
});

test('action detects available languages', function () {
	File::ensureDirectoryExists(lang_path('EN'));
	File::ensureDirectoryExists(lang_path('spark'));

	File::put(lang_path('EN/linguist.json'), json_encode(['managed' => 'Managed EN'], JSON_PRETTY_PRINT));
	File::put(lang_path('spark/de.json'), json_encode(['hello' => 'Hallo'], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$languages = $action->detectLanguages();

	expect($languages)->toHaveCount(2)
		->and($languages)->toContain('EN')
		->and($languages)->toContain('DE');
});

test('action resolves language from directory for nested project files', function () {
	File::ensureDirectoryExists(lang_path('EN'));

	File::put(lang_path('EN/project.json'), json_encode([
		'nested' => 'From project file',
	], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$allTranslations = $action->handle('project');

	expect($allTranslations)->toHaveKey('EN')
		->and($allTranslations)->not->toHaveKey('PROJECT')
		->and($allTranslations['EN'])->toHaveKey('nested')
		->and($allTranslations['EN']['nested'])->toBe('From project file');
});

test('action filters non-managed files by project slug when provided', function () {
	File::ensureDirectoryExists(lang_path('EN'));

	File::put(lang_path('EN/project-a.json'), json_encode([
		'a.only' => 'A value',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('EN/project-b.json'), json_encode([
		'b.only' => 'B value',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('EN/linguist.json'), json_encode([
		'managed' => 'Managed EN',
	], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$translations = $action->handle('project-a');

	expect($translations)->toHaveKey('EN')
		->and($translations['EN'])->toHaveKey('a.only')
		->and($translations['EN'])->toHaveKey('managed')
		->and($translations['EN'])->not->toHaveKey('b.only');
});

test('action includes locale json files when project slug is provided', function () {
	File::ensureDirectoryExists(lang_path());

	File::put(lang_path('de.json'), json_encode([
		'hello' => 'Hallo',
	], JSON_PRETTY_PRINT));
	File::put(lang_path('messages.json'), json_encode([
		'ignored' => 'not locale file',
	], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$translations = $action->handle('project-a');

	expect($translations)->toHaveKey('DE')
		->and($translations['DE'])->toHaveKey('hello')
		->and($translations)->not->toHaveKey('MESSAGES');
});

test('action parses nested translation files', function () {
	File::ensureDirectoryExists(lang_path());

	File::put(
		lang_path('en.json'),
		json_encode([
			'user' => [
				'profile' => [
					'title' => 'User Profile',
				],
			],
			'hello' => 'Hello World',
		], JSON_PRETTY_PRINT)
	);

	$action = new CollectLocalTranslations;
	$translations = $action->handle();

	expect($translations)->toHaveKey('EN')
		->and($translations['EN'])->toHaveKey('user.profile.title')
		->and($translations['EN']['user.profile.title'])->toBe('User Profile')
		->and($translations['EN']['hello'])->toBe('Hello World');
});

test('action returns empty array for non-existent language', function () {
	$action = new CollectLocalTranslations;
	$translations = $action->handle('nonexistent');

	expect($translations)->toBe([]);
});

test('linguist managed keys override non linguist keys on collision', function () {
	File::ensureDirectoryExists(lang_path('EN'));
	File::ensureDirectoryExists(lang_path('spark'));

	File::put(lang_path('en.json'), json_encode([
		'hello' => 'Hello from base',
	], JSON_PRETTY_PRINT));

	File::put(lang_path('spark/en.json'), json_encode([
		'hello' => 'Hello from spark',
	], JSON_PRETTY_PRINT));

	File::put(lang_path('EN/linguist.json'), json_encode([
		'hello' => 'Hello from linguist',
	], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations;
	$translations = $action->handle();

	expect($translations['EN']['hello'])->toBe('Hello from linguist');
});

test('action can be run as an invokable', function () {
	createTestTranslationFiles('test-project', ['EN']);

	$action = new CollectLocalTranslations;
	$translations = $action('test-project');

	expect($translations)->toHaveKey('EN');
});
