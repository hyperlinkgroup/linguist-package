<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Services;

use Hyperlinkgroup\Linguist\Exceptions\InvalidLanguageCodeException;
use Hyperlinkgroup\Linguist\Exceptions\InvalidTranslationFileException;
use Hyperlinkgroup\Linguist\Exceptions\TranslationFileWriteException;
use Hyperlinkgroup\Linguist\Support\LanguageCode;
use Illuminate\Support\Facades\File;
use JsonException;
use stdClass;

final class LanguageFileWriter
{
	/**
	 * Validate a downloaded catalog and atomically replace lang/{locale}.json with it.
	 *
	 * @return int Number of top-level keys written
	 *
	 * @throws InvalidLanguageCodeException
	 * @throws InvalidTranslationFileException
	 * @throws TranslationFileWriteException
	 */
	public function write(string $language, string $body, string $legacyFileName = 'linguist.json'): int
	{
		if (! LanguageCode::isValid($language)) {
			throw new InvalidLanguageCodeException("Invalid language code '{$language}'.");
		}

		// Decode into objects so JSON objects stay objects, including numeric keys and nested empty groups.
		$decoded = json_decode($body);

		// PHP APIs encode an empty catalog as a list.
		if ($decoded === []) {
			$decoded = new stdClass;
		}

		if (! $decoded instanceof stdClass) {
			throw new InvalidTranslationFileException("Downloaded translations for '{$language}' are not a JSON object.");
		}

		$path = lang_path(strtolower($language) . '.json');
		$legacyPath = lang_path(strtoupper($language) . '/' . $legacyFileName);
		$fileExists = File::exists($path);
		$keyCount = count(get_object_vars($decoded));

		// An empty download must not wipe an existing catalog (including the legacy file removed below),
		// but may create or keep an empty one. Empty groups hold no translations either.
		if (! $this->hasTranslations($decoded) && (! $this->isEmptyCatalogFile($path) || ! $this->isEmptyCatalogFile($legacyPath))) {
			throw new InvalidTranslationFileException("Downloaded translations for '{$language}' are empty.");
		}

		// Content checks on the decoded catalog (see LGT-202) belong here, before the file is replaced.

		$flags = config('linguist.pull_minified', false)
			? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		try {
			$encoded = json_encode($decoded, $flags | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new InvalidTranslationFileException("Downloaded translations for '{$language}' cannot be encoded: {$e->getMessage()}", previous: $e);
		}

		// Keep the permissions of an existing file, otherwise use the default for new files.
		$permissions = $fileExists ? @fileperms($path) : false;

		File::ensureDirectoryExists(lang_path());
		$this->replaceAtomically($path, $encoded . "\n", $permissions !== false ? $permissions & 0777 : 0666 & ~umask());

		// Cleanup legacy managed file format (lang/DE/linguist.json) to avoid stale overrides.
		if (File::exists($legacyPath)) {
			File::delete($legacyPath);
		}

		return $keyCount;
	}

	/**
	 * Write to a temporary file next to the target and rename it only once the content is complete.
	 * Unlike File::replace(), a failed or partial write (e.g. a full disk) leaves the existing file untouched.
	 *
	 * @throws TranslationFileWriteException
	 */
	private function replaceAtomically(string $path, string $content, int $permissions): void
	{
		// Write through a symlink to its target instead of replacing the link itself.
		clearstatcache(true, $path);
		$targetPath = realpath($path) ?: $path;

		$temporaryPath = @tempnam(dirname($targetPath), basename($targetPath));

		if ($temporaryPath === false) {
			throw new TranslationFileWriteException("Could not create a temporary file for '{$path}'.");
		}

		if (@file_put_contents($temporaryPath, $content) !== strlen($content)) {
			@unlink($temporaryPath);

			throw new TranslationFileWriteException("Could not write '{$path}'.");
		}

		// Some mounts do not support chmod; the content is what matters, as with File::replace().
		@chmod($temporaryPath, $permissions);

		if (! @rename($temporaryPath, $targetPath)) {
			@unlink($temporaryPath);

			throw new TranslationFileWriteException("Could not write '{$path}'.");
		}
	}

	/**
	 * Whether a local file is missing or holds no translations. Unreadable content counts as not empty, so it is kept.
	 */
	private function isEmptyCatalogFile(string $path): bool
	{
		if (! File::exists($path)) {
			return true;
		}

		$content = File::get($path);

		if (trim($content) === '') {
			return true;
		}

		$decoded = json_decode($content);

		return $decoded === [] || ($decoded instanceof stdClass && ! $this->hasTranslations($decoded));
	}

	/**
	 * Whether a decoded catalog contains at least one translation value, at any depth.
	 */
	private function hasTranslations(mixed $node): bool
	{
		if (! $node instanceof stdClass && ! is_array($node)) {
			return true;
		}

		foreach ((array) $node as $child) {
			if ($this->hasTranslations($child)) {
				return true;
			}
		}

		return false;
	}
}
