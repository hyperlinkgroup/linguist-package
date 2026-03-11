<?php

use Hyperlinkgroup\Linguist\Actions\CollectLocalTranslations;
use Illuminate\Support\Facades\File;

afterEach(function () {
	cleanTranslationFiles();
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

	$action = new CollectLocalTranslations();
	$translations = $action->handle('test-project');

	expect($translations)->toHaveKeys(['EN', 'DE'])
		->and($translations['EN'])->toHaveKeys(['managed', 'goodbye'])
		->and($translations['DE'])->toHaveKeys(['managed', 'hello']);
});

test('action detects available languages', function () {
	File::ensureDirectoryExists(lang_path('EN'));
	File::ensureDirectoryExists(lang_path('spark'));

	File::put(lang_path('EN/linguist.json'), json_encode(['managed' => 'Managed EN'], JSON_PRETTY_PRINT));
	File::put(lang_path('spark/de.json'), json_encode(['hello' => 'Hallo'], JSON_PRETTY_PRINT));

	$action = new CollectLocalTranslations();
	$languages = $action->detectLanguages();

	expect($languages)->toHaveCount(2)
		->and($languages)->toContain('EN')
		->and($languages)->toContain('DE');
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

	$action = new CollectLocalTranslations();
	$translations = $action->getTranslationsForLanguage('EN');

	expect($translations)->toHaveKey('user.profile.title')
		->and($translations['user.profile.title'])->toBe('User Profile')
		->and($translations['hello'])->toBe('Hello World');
});

test('action writes translations to file', function () {
	$action = new CollectLocalTranslations();

	$translations = [
		'hello' => 'Hello World',
		'user.profile.title' => 'User Profile',
	];

	$success = $action->writeTranslations('EN', 'test-project', $translations);

	expect($success)->toBeTrue();

	$filePath = lang_path('EN/linguist.json');
	expect(File::exists($filePath))->toBeTrue();

	$content = json_decode(File::get($filePath), true);
	expect($content)->toHaveKey('hello')
		->and($content['hello'])->toBe('Hello World')
		->and($content)->toHaveKey('user')
		->and($content['user']['profile']['title'])->toBe('User Profile');
});

test('action returns empty array for non-existent language', function () {
	$action = new CollectLocalTranslations();
	$translations = $action->getTranslationsForLanguage('XX', 'nonexistent');

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

	$action = new CollectLocalTranslations();
	$translations = $action->handle('test-project');
	$english = $action->getTranslationsForLanguage('EN', 'test-project');

	expect($translations['EN']['hello'])->toBe('Hello from linguist')
		->and($english['hello'])->toBe('Hello from linguist');
});

test('action can be run as an invokable', function () {
	createTestTranslationFiles('test-project', ['EN']);

	$action = new CollectLocalTranslations();
	$translations = $action('test-project');

	expect($translations)->toHaveKey('EN');
});
