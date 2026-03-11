<?php

use Hyperlinkgroup\Linguist\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('.');

/**
 * Configure valid Linguist settings for testing.
 */
function configureValidLinguistConfig(string $project = 'test-project', string $token = 'test-token'): void
{
	config([
		'linguist.project' => $project,
		'linguist.token' => $token,
		'linguist.url' => 'https://api.linguist.eu',
		'linguist.temporary_directory' => 'tmp/translations',
	]);
}

/**
 * Fake HTTP responses for common Linguist API endpoints.
 */
function fakeLinguistApi(array $responses = []): void
{
	Http::preventStrayRequests();

	$defaultResponses = [
		'https://api.linguist.eu/projects/*/languages' => Http::response([
			'data' => ['EN', 'DE'],
		]),
		'https://api.linguist.eu/projects/*/export/json/*' => Http::response([
			'url' => 'https://api.linguist.eu/export/test-export-uuid',
		]),
		'https://api.linguist.eu/export/*' => Http::response([
			'hello' => 'Hello World',
			'goodbye' => 'Goodbye World',
		]),
	];

	Http::fake(array_merge($defaultResponses, $responses));
}

/**
 * Create a mock HTTP response for project list endpoint.
 */
function fakeProjectListResponse(array $projects = []): void
{
	$defaultProjects = [
		['id' => 1, 'slug' => 'project-one', 'name' => 'Project One'],
		['id' => 2, 'slug' => 'project-two', 'name' => 'Project Two'],
	];

	Http::fake([
		'https://api.linguist.eu/projects' => Http::response([
			'data' => empty($projects) ? $defaultProjects : $projects,
		]),
	]);
}

/**
 * Clean up test directories and files.
 */
function cleanTranslationFiles(): void
{
	if (File::exists(lang_path())) {
		collect(File::files(lang_path(), true))->each(function (SplFileInfo $file) {
			File::delete($file->getPathname());
		});

		File::deleteDirectory(lang_path());
	}

	if (File::exists(storage_path('tmp/translations'))) {
		File::deleteDirectory(storage_path('tmp/translations'));
	}

	if (File::exists(storage_path('tmp'))) {
		File::deleteDirectory(storage_path('tmp'));
	}
}

/**
 * Create test translation files.
 */
function createTestTranslationFiles(string $project, array $languages = ['EN', 'DE']): void
{
	File::ensureDirectoryExists(lang_path());

	foreach ($languages as $language) {
		File::put(
			lang_path(strtolower($language) . '.json'),
			json_encode([
				'hello' => "Hello in {$language}",
				'goodbye' => "Goodbye in {$language}",
			], JSON_PRETTY_PRINT)
		);
	}
}
