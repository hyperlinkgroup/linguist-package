<?php

use Hyperlinkgroup\Linguist\Exceptions\ConfigBrokenException;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Hyperlinkgroup\Linguist\Facades\Linguist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;

beforeEach(function () {
	cleanUp();
});

afterEach(function () {
	cleanUp();
});

function cleanUp(): void
{
	if (File::exists(lang_path())) {
		collect(File::files(lang_path()))->each(function (SplFileInfo $file) {
			File::delete($file->getPathname());
		});

		File::deleteDirectory(lang_path());
	}

	if (File::exists(storage_path('tmp/translations'))) {
		collect(File::files(storage_path('tmp/translations')))->each(function (SplFileInfo $file) {
			File::delete($file->getPathname());
		});
	}

	if (File::exists(storage_path('tmp'))) {
		collect(File::files(storage_path('tmp')))->each(function (SplFileInfo $file) {
			File::delete($file->getPathname());
		});
	}

	File::deleteDirectory(storage_path('tmp'));
}

test('that we get an exception when project is not set', function () {
	config(['linguist.project' => '']);

	Linguist::handle();
})->throws(ConfigBrokenException::class, 'The linguist project is not available');

test('that we get an exception when token is not set', function () {
	config(['linguist.project' => 'not-empty']);
	config(['linguist.token' => '']);

	Linguist::handle();
})->throws(ConfigBrokenException::class, 'The linguist token is not available');

test('that we can get all languages', function () {
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects/project/languages' => Http::response([
			'data' => [
				'EN',
				'DE',
			],
		]),
	]);

	expect(Linguist::getLanguages())->toBeInstanceOf(Collection::class)
		->toHaveCount(2)
		->toContain('EN')
		->toContain('DE');
});

test('that we get an exception while getting all languages if the list is empty', function () {
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects/project/languages' => Http::response([
			'data' => [],
		]),
	]);

	Linguist::getLanguages();
})->throws(NoLanguageActivatedException::class);

test('that we can create directories', function () {
	$languages = collect(['EN', 'DE']);
	config(['linguist.temporary_directory' => 'tmp/translations']);

	Linguist::setLanguages($languages)
		->createDirectories();

	assertFileExists(lang_path());

	assertFileExists(storage_path(config('linguist.temporary_directory')));
});

test('that we can download the files', function () {
	$languages = collect(['EN', 'DE']);
	config(['linguist.temporary_directory' => 'tmp/translations']);
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects/project/export/json/DE?prefix=:' => Http::response([
			'url' => 'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test German Translation',
		]),
		'https://api.linguist.eu/v2/projects/project/export/json/EN?prefix=:' => Http::response([
			'url' => 'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test English Translation',
		]),
	]);

	Linguist::setLanguages($languages)
		->createDirectories()
		->downloadFiles();

	$languages->each(function (string $language) {
		assertFileExists(storage_path(config('linguist.temporary_directory') . "/$language.json"));
	});
});

test('that we can move the files', function () {
	$languages = collect(['EN', 'DE']);
	config(['linguist.temporary_directory' => 'tmp/translations']);
	config(['linguist.project' => 'project']);

	Linguist::setLanguages($languages)
		->createDirectories();

	File::put(storage_path('tmp/translations/EN.json'), json_encode(['Test' => 'Test English Translation'], JSON_THROW_ON_ERROR));
	File::put(storage_path('tmp/translations/DE.json'), json_encode(['Test' => 'Test German Translation'], JSON_THROW_ON_ERROR));

	Linguist::moveFiles();

	$languages->each(function (string $language) {
		assertFileExists(lang_path(strtolower($language) . '.json'));
	});

	assertFileDoesNotExist(storage_path(config('linguist.temporary_directory')));
});

test('that we can execute the command', function () {
	config(['linguist.temporary_directory' => 'tmp/translations']);
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	$languages = collect(['EN', 'DE']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/project/translation-keys' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/project/languages' => Http::response([
			'data' => $languages->all(),
		]),
		'https://api.linguist.eu/v2/projects/project/export/json/DE?prefix=:' => Http::response([
			'url' => 'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test German Translation',
		]),
		'https://api.linguist.eu/v2/projects/project/export/json/EN?prefix=:' => Http::response([
			'url' => 'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test English Translation',
		]),
	]);

	Linguist::handle();

	$languages->each(function (string $language) {
		assertFileExists(lang_path(strtolower($language) . '.json'));
	});

	assertFileDoesNotExist(storage_path(config('linguist.temporary_directory')));
});

test('artisan command succeeds with valid configuration', function () {
	config(['linguist.temporary_directory' => 'tmp/translations']);
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	$languages = collect(['EN', 'DE']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects?per_page=100' => Http::response([
			'data' => [[
				'slug' => 'project',
				'languages' => [
					['id' => 10, 'code' => 'EN'],
					['id' => 20, 'code' => 'DE'],
				],
			]],
		], 200),
		'https://api.linguist.eu/v2/projects/project/translation-keys?per_page=100&page=1' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/project/translation-keys' => Http::response([
			'data' => [],
		], 200),
		'https://api.linguist.eu/v2/projects/project/languages' => Http::response([
			'data' => $languages->all(),
		]),
		'https://api.linguist.eu/v2/projects/project' => Http::response([
			'data' => [
				'slug' => 'project',
				'language' => ['code' => 'EN'],
			],
		]),
		'https://api.linguist.eu/v2/projects/project/export/json/DE?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/dd9d79d3-135e-4f7e-b439-c35024ee0376?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test German Translation',
		]),
		'https://api.linguist.eu/v2/projects/project/export/json/EN?prefix=%3A' => Http::response([
			'url' => 'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900',
		]),
		'https://api.linguist.eu/export/18682c32-2615-447e-8bdf-a4069a7bc8f2?project=project&signature=363063c742f891af8dbeb4ac7d1940743ff083cdb0d30bbb736e0e773e694900' => Http::response([
			'Test' => 'Test English Translation',
		]),
	]);

	$this->artisan('linguist:sync --mode=sync --sync-source=remote --no-interaction')
		->assertSuccessful()
		->expectsOutputToContain('Starting linguist sync...')
		->expectsOutputToContain('Sync completed');

	$languages->each(function (string $language) {
		assertFileExists(lang_path(strtolower($language) . '.json'));
	});
});

test('artisan command fails when project is not configured', function () {
	config(['linguist.project' => '']);
	config(['linguist.token' => 'token']);

	$this->artisan('linguist:sync')
		->assertFailed()
		->expectsOutputToContain('Linguist is not configured');
});

test('artisan command fails when token is not configured', function () {
	config(['linguist.project' => 'project']);
	config(['linguist.token' => '']);

	$this->artisan('linguist:sync')
		->assertFailed()
		->expectsOutputToContain('Linguist is not configured');
});

test('artisan command fails when no languages are activated', function () {
	config(['linguist.project' => 'project']);
	config(['linguist.token' => 'token']);

	Http::preventStrayRequests();

	Http::fake([
		'https://api.linguist.eu/v2/projects/project/languages' => Http::response([
			'data' => [],
		]),
	]);

	$this->artisan('linguist:sync --mode=pull')
		->assertFailed()
		->expectsOutputToContain('No languages are activated in your Linguist project.');
});
