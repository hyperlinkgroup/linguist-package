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
	 * @return array<string, array<string, string >> Array of language => [key => value]
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

		if (json_last_error() !== JSON_ERROR_NONE) {
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
	 * Get translations for a specific language.
	 *
	 * @return array<string, string>
	 */
	public static function getTranslationsForLanguage(string $language, ?string $projectSlug = null): array
	{
		$translations = [];
		$discoveredFiles = self::discoverTranslationFiles();

		// Keep precedence consistent with handle(): linguist.json should override collisions.
		usort($discoveredFiles, function (array $left, array $right): int {
			if ($left['is_linguist_file'] === $right['is_linguist_file']) {
				return strcmp($left['path'], $right['path']);
			}

			return $left['is_linguist_file'] ? 1 : -1;
		});

		foreach ($discoveredFiles as $discoveredFile) {
			if ($discoveredFile['language'] !== strtoupper($language)) {
				continue;
			}

			if ($projectSlug !== null
				&& ! $discoveredFile['is_linguist_file']
				&& $discoveredFile['filename_without_extension'] !== $projectSlug) {
				continue;
			}

			$translations = array_merge(
				$translations,
				self::parseTranslationFile($discoveredFile['path'])
			);
		}

		return $translations;
	}

	/**
	 * Write translations to a language file.
	 */
	public static function writeTranslations(string $language, string $projectSlug, array $translations): bool
	{
		$directory = lang_path(strtoupper($language));
		$path = "{$directory}/" . self::LINGUIST_FILENAME;

		if (! File::isDirectory($directory)) {
			File::makeDirectory($directory, 0755, true);
		}

		$nested = self::unflattenTranslations($translations);
		$json = json_encode($nested, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		return File::put($path, $json) !== false;
	}

	/**
	 * Discover translatable JSON files recursively from lang_path().
	 *
	 * @return array<int, array{language: string, path: string, filename_without_extension: string, is_linguist_file: bool}>
	 */
	private static function discoverTranslationFiles(): array
	{
		$langPath = lang_path();

		if (! File::isDirectory($langPath)) {
			return [];
		}

		$discovered = [];

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

			$language = $isLinguistFile
				? basename($file->getPath())
				: $filenameWithoutExtension;

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

		return $discovered;
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

	/**
	 * Convert dot-notation keys back to nested array.
	 */
	private static function unflattenTranslations(array $flat): array
	{
		$result = [];

		foreach ($flat as $key => $value) {
			$parts = explode('.', $key);
			$current = &$result;

			foreach ($parts as $i => $part) {
				if ($i === count($parts) - 1) {
					$current[$part] = $value;
				} else {
					if (! isset($current[$part]) || ! is_array($current[$part])) {
						$current[$part] = [];
					}
					$current = &$current[$part];
				}
			}
		}

		return $result;
	}
}
