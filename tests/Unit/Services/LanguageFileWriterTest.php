<?php

use Hyperlinkgroup\Linguist\Exceptions\InvalidLanguageCodeException;
use Hyperlinkgroup\Linguist\Exceptions\InvalidTranslationFileException;
use Hyperlinkgroup\Linguist\Services\LanguageFileWriter;
use Illuminate\Support\Facades\File;

afterEach(function () {
	cleanTranslationFiles();
});

test('writes a lowercase locale file and returns the key count', function () {
	$count = (new LanguageFileWriter)->write('DE', json_encode(['hello' => 'Hallo', 'bye' => 'Tschüss']));

	expect($count)->toBe(2)
		->and(json_decode(File::get(lang_path('de.json')), true))->toBe(['hello' => 'Hallo', 'bye' => 'Tschüss']);
});

test('refuses an invalid language code before touching the file system', function () {
	(new LanguageFileWriter)->write('../x', json_encode(['hello' => 'Hallo']));
})->throws(InvalidLanguageCodeException::class, "Invalid language code '../x'.");

test('refuses a body that is not a json object and keeps the existing file', function (string $body) {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo"}');

	expect(fn () => (new LanguageFileWriter)->write('DE', $body))
		->toThrow(InvalidTranslationFileException::class);

	expect(File::get(lang_path('de.json')))->toBe('{"hello": "Hallo"}');
})->with([
	'html error page' => '<html><body>502 Bad Gateway</body></html>',
	'json list' => '["a", "b"]',
	'json string' => '"hello"',
	'json null' => 'null',
]);

test('refuses an empty catalog when it would replace an existing file', function (string $body) {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo"}');

	expect(fn () => (new LanguageFileWriter)->write('DE', $body))
		->toThrow(InvalidTranslationFileException::class, "Downloaded translations for 'DE' are empty.");

	expect(File::get(lang_path('de.json')))->toBe('{"hello": "Hallo"}');
})->with([
	'empty object' => '{}',
	'empty list' => '[]',
	'only empty groups' => '{"auth": {}, "nav": []}',
]);

test('writes an empty catalog for a language without a local file', function (string $body) {
	expect((new LanguageFileWriter)->write('DE', $body))->toBe(0)
		->and(File::get(lang_path('de.json')))->toBe("{}\n");
})->with([
	'empty object' => '{}',
	'empty list' => '[]',
]);

test('keeps accepting an empty catalog when the local file is empty as well', function (string $existing) {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), $existing);

	expect((new LanguageFileWriter)->write('DE', '[]'))->toBe(0)
		->and(File::get(lang_path('de.json')))->toBe("{}\n");
})->with([
	'empty object' => "{}\n",
	'empty list' => '[]',
	'empty file' => '',
]);

test('refuses an empty catalog when the local file is not readable', function () {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo",}');

	expect(fn () => (new LanguageFileWriter)->write('DE', '{}'))
		->toThrow(InvalidTranslationFileException::class);

	expect(File::get(lang_path('de.json')))->toBe('{"hello": "Hallo",}');
});

test('refuses an empty catalog when it would remove a legacy file with translations', function () {
	File::ensureDirectoryExists(lang_path('DE'));
	File::put(lang_path('DE/my-project.json'), '{"hello": "Hallo"}');

	expect(fn () => (new LanguageFileWriter)->write('DE', '{}', 'my-project.json'))
		->toThrow(InvalidTranslationFileException::class, "Downloaded translations for 'DE' are empty.");

	expect(File::get(lang_path('DE/my-project.json')))->toBe('{"hello": "Hallo"}')
		->and(File::exists(lang_path('de.json')))->toBeFalse();
});

test('refuses a catalog that cannot be encoded again and keeps the existing file', function () {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo"}');

	expect(fn () => (new LanguageFileWriter)->write('DE', '{"huge": 1e400}'))
		->toThrow(InvalidTranslationFileException::class);

	expect(File::get(lang_path('de.json')))->toBe('{"hello": "Hallo"}');
});

test('keeps json objects as objects', function () {
	(new LanguageFileWriter)->write('DE', '{"0": "Null", "1": "Eins", "group": {}}');

	expect(File::get(lang_path('de.json')))->toBe("{\n    \"0\": \"Null\",\n    \"1\": \"Eins\",\n    \"group\": {}\n}\n");
});

test('does not escape slashes', function () {
	(new LanguageFileWriter)->write('DE', '{"link": "https:\\/\\/example.com\\/de"}');

	expect(File::get(lang_path('de.json')))->toContain('"https://example.com/de"');
});

test('keeps the permissions of an existing file', function () {
	File::ensureDirectoryExists(lang_path());
	File::put(lang_path('de.json'), '{"hello": "Hallo"}');
	chmod(lang_path('de.json'), 0640);

	(new LanguageFileWriter)->write('DE', '{"hello": "Hallo neu"}');

	clearstatcache();
	expect(fileperms(lang_path('de.json')) & 0777)->toBe(0640);
});

test('creates new files without the executable bit', function () {
	(new LanguageFileWriter)->write('DE', '{"hello": "Hallo"}');

	expect(fileperms(lang_path('de.json')) & 0777)->toBe(0666 & ~umask());
});

test('removes the legacy managed file with the given name', function () {
	File::ensureDirectoryExists(lang_path('DE'));
	File::put(lang_path('DE/my-project.json'), '{}');

	(new LanguageFileWriter)->write('de', '{"hello": "Hallo"}', 'my-project.json');

	expect(File::exists(lang_path('DE/my-project.json')))->toBeFalse();
});

test('writes through a symlinked language file instead of replacing the link', function () {
	File::ensureDirectoryExists(lang_path('shared'));
	File::put(lang_path('shared/de.json'), '{"hello": "Alt"}');
	symlink(lang_path('shared/de.json'), lang_path('de.json'));

	(new LanguageFileWriter)->write('DE', '{"hello": "Neu"}');

	expect(is_link(lang_path('de.json')))->toBeTrue()
		->and(json_decode(File::get(lang_path('shared/de.json')), true))->toBe(['hello' => 'Neu']);

	unlink(lang_path('de.json'));
});
