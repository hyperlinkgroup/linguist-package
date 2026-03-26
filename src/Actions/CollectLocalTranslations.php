<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;

final class CollectLocalTranslations
{
	use AsAction;

	private const LINGUIST_FILENAME = 'linguist.json';

	/**
	 * Collect all translation files from the local lang directory.
	 *
	 * @return array<string, array<string, string>> Array of language => [key => value]
	 */
	public function handle(?string $projectSlug = null): array
	{
		$translations = [];
		$discoveredFiles = self::discoverTranslationFiles();

		// Process managed files last so linguist.json always wins collisions.
		usort($discoveredFiles, function (array $left, array $right): int {
			if ($left['is_linguist_file'] === $right['is_linguist_file']) {
				return strcmp($left['path'], $right['path']);
			}

			return $left['is_linguist_file'] ? 1 : -1;
		});

		foreach ($discoveredFiles as $discoveredFile) {
			if (! self::shouldIncludeForProjectSlug($discoveredFile, $projectSlug)) {
				continue;
			}

			$language = $discoveredFile['language'];

			$translations[$language] = array_merge(
				$translations[$language] ?? [],
				self::parseTranslationFile($discoveredFile['path'])
			);
		}

		return $translations;
	}

	/**
	 * Parse a JSON translation file and flatten keys.
	 *
	 * @return array<string, string>
	 */
	public static function parseTranslationFile(string $path): array
	{
		if (! File::exists($path)) {
			return [];
		}

		$content = File::get($path);
		$decoded = json_decode($content, true);

		if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
			return [];
		}

		return self::flattenTranslations($decoded);
	}

	/**
	 * Detect available languages from local translation files.
	 *
	 * @return array<string>
	 */
	public static function detectLanguages(): array
	{
		return collect(self::discoverTranslationFiles())
			->pluck('language')
			->unique()
			->values()
			->toArray();
	}

	/**
	 * Absolute paths scanned for JSON translation files (same logic as discovery).
	 *
	 * @return array<int, string>
	 */
	public static function translationFileSearchRoots(): array
	{
		return self::translationFileRoots();
	}

	/**
	 * Reasons no JSON translation search roots are available.
	 *
	 * @return array<int, string>
	 */
	public static function translationFileSearchRootIssues(): array
	{
		$roots = self::translationFileRoots();

		if ($roots !== []) {
			return [];
		}

		$configured = lang_path();
		$configuredPath = is_string($configured) ? $configured : '';
		$fallback = base_path('lang');

		$reasons = [];

		if ($configuredPath === '') {
			$reasons[] = 'lang_path() is empty';
		} elseif (! File::isDirectory($configuredPath)) {
			$reasons[] = "lang_path() is not a directory ({$configuredPath})";
		}

		if (! File::isDirectory($fallback)) {
			$reasons[] = "no directory at base_path('lang') ({$fallback})";
		}

		return $reasons !== [] ? $reasons : ['no searchable directories'];
	}

	/**
	 * Roots to scan for JSON translations: Laravel's lang_path() when usable, else project /lang.
	 *
	 * @return array<int, string>
	 */
	private static function translationFileRoots(): array
	{
		$primary = lang_path();

		if (is_string($primary) && $primary !== '' && File::isDirectory($primary)) {
			return [$primary];
		}

		$fallback = base_path('lang');

		return File::isDirectory($fallback) ? [$fallback] : [];
	}

	/**
	 * Discover translatable JSON files recursively from translation roots.
	 *
	 * @return array<int, array{language: string, path: string, filename_without_extension: string, is_linguist_file: bool}>
	 */
	private static function discoverTranslationFiles(): array
	{
		$discovered = [];

		foreach (self::translationFileRoots() as $langPath) {
			foreach (File::allFiles($langPath) as $file) {
				if ($file->getExtension() !== 'json') {
					continue;
				}

				$absolutePath = $file->getPathname();
				$relativePath = ltrim(str_replace($langPath, '', $absolutePath), DIRECTORY_SEPARATOR);

				if ($relativePath === '' || str_starts_with($relativePath, 'vendor' . DIRECTORY_SEPARATOR)) {
					continue;
				}

				$filenameWithoutExtension = $file->getFilenameWithoutExtension();
				$isLinguistFile = strtolower($file->getFilename()) === self::LINGUIST_FILENAME;
				$pathSegments = explode(DIRECTORY_SEPARATOR, $relativePath);
				$topLevelSegment = $pathSegments[0] ?? '';

				$language = $isLinguistFile
					? basename($file->getPath())
					: (self::isLanguageCodeLike($topLevelSegment) ? $topLevelSegment : $filenameWithoutExtension);

				if ($isLinguistFile && $file->getPath() === $langPath) {
					continue;
				}

				if ($language === '' || strtolower($language) === 'vendor') {
					continue;
				}

				$discovered[] = [
					'language' => strtoupper($language),
					'path' => $absolutePath,
					'filename_without_extension' => $filenameWithoutExtension,
					'is_linguist_file' => $isLinguistFile,
				];
			}
		}

		return $discovered;
	}

	/**
	 * Determine whether a path segment looks like a locale/language code.
	 */
	private static function isLanguageCodeLike(string $segment): bool
	{
		return (bool) preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $segment);
	}

	/**
	 * @param  array{language: string, path: string, filename_without_extension: string, is_linguist_file: bool}  $discoveredFile
	 */
	private static function shouldIncludeForProjectSlug(array $discoveredFile, ?string $projectSlug): bool
	{
		if ($projectSlug === null || $discoveredFile['is_linguist_file']) {
			return true;
		}

		if ($discoveredFile['filename_without_extension'] === $projectSlug) {
			return true;
		}

		// Allow locale-style files like lang/en.json or lang/de.json in sync mode.
		return self::isLanguageCodeLike($discoveredFile['filename_without_extension']);
	}

	/**
	 * Flatten nested translation arrays to dot notation.
	 *
	 * @return array<string, string>
	 */
	private static function flattenTranslations(array $translations, string $prefix = ''): array
	{
		$result = [];

		foreach ($translations as $key => $value) {
			$newKey = $prefix === '' ? $key : "{$prefix}.{$key}";

			if (is_array($value)) {
				$result = array_merge($result, self::flattenTranslations($value, $newKey));
			} else {
				$result[$newKey] = (string) $value;
			}
		}

		return $result;
	}

}
