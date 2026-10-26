<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Events\PullCompleted;
use Hyperlinkgroup\Linguist\Exceptions\InvalidTranslationFileException;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Hyperlinkgroup\Linguist\Exceptions\TranslationFileWriteException;
use Hyperlinkgroup\Linguist\Services\LanguageFileWriter;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Hyperlinkgroup\Linguist\Support\LanguageCode;
use Lorisleiva\Actions\Concerns\AsAction;

final class PullTranslations
{
	use AsAction;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {}

	/**
	 * Pull translations from Linguist and overwrite local files.
	 *
	 * @param  array<string>  $skipLanguages  Languages to leave untouched locally and report as failed (e.g. because their push failed)
	 * @param  array<string>  $keepLanguages  Languages to leave untouched locally on purpose (e.g. upload skipped by option).
	 *                                        Only relevant when the languages endpoint lists a language the project's ID map lacks.
	 */
	public function handle(string $projectSlug, bool $emitEvent = true, array $skipLanguages = [], array $keepLanguages = []): SyncResult
	{
		$languageResults = [];
		$errors = [];

		try {
			$this->apiClient->setProjectSlug($projectSlug);

			$languagesResponse = $this->apiClient->getLanguages();

			if (! $languagesResponse->successful()) {
				return new SyncResult(
					overallSuccess: false,
					errors: ['Failed to fetch languages: ' . $languagesResponse->body()],
				);
			}

			$languages = $this->apiClient->extractLanguageCodes($languagesResponse->json('data', []));

			if ($languages === []) {
				throw new NoLanguageActivatedException;
			}

			// Also include the source language, which is not returned by the languages endpoint
			$projectResponse = $this->apiClient->getProject();
			if ($projectResponse->successful()) {
				$sourceLanguage = $projectResponse->json('data.language.code') ?? $projectResponse->json('language.code');
				if (is_string($sourceLanguage) && $sourceLanguage !== '') {
					$sourceCode = strtoupper($sourceLanguage);
					if (! in_array($sourceCode, $languages, true)) {
						$languages[] = $sourceCode;
					}
				}
			}

			$maxKeysProcessed = 0;
			$skippedLanguages = array_map(LanguageCode::normalize(...), $skipLanguages);
			$keptLanguages = array_map(LanguageCode::normalize(...), $keepLanguages);
			$writer = new LanguageFileWriter;
			$recordFailure = function (string $language, string $message) use (&$languageResults, &$errors): void {
				$languageResults[$language] = [
					'success' => false,
					'message' => $message,
				];
				$errors[] = "{$language}: {$message}";
			};

			foreach ($languages as $language) {
				if (in_array(LanguageCode::normalize($language), $skippedLanguages, true)) {
					$recordFailure($language, 'Skipped because this language was not pushed successfully.');

					continue;
				}

				if (in_array(LanguageCode::normalize($language), $keptLanguages, true)) {
					$languageResults[$language] = [
						'success' => true,
						'message' => 'Not pulled because its upload was skipped.',
					];

					continue;
				}

				$result = $this->apiClient->downloadLanguageExportWithPrefixFallback($language);

				if ($result['success']) {
					$body = $result['body'] ?? null;
					if (is_string($body) && $body !== '') {
						try {
							$maxKeysProcessed = max($maxKeysProcessed, $writer->write($language, $body));
						} catch (InvalidTranslationFileException|TranslationFileWriteException $e) {
							$recordFailure($language, $e->getMessage());

							continue;
						}
					}

					$languageResults[$language] = ['success' => true];
				} else {
					$recordFailure($language, $result['message'] ?? 'Failed to fetch export URL for this language.');
				}
			}

			$successCount = count(array_filter($languageResults, fn ($r) => $r['success']));

			$result = new SyncResult(
				overallSuccess: $successCount === count($languageResults),
				languageResults: $languageResults,
				errors: $errors,
				keysProcessed: $maxKeysProcessed,
			);

			if ($emitEvent && $result->overallSuccess) {
				event(new PullCompleted($projectSlug, $result));
			}

			return $result;

		} catch (NoLanguageActivatedException $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: ['No languages are activated in your Linguist project.'],
			);
		} catch (\Exception $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: [$e->getMessage()],
				aborted: true,
			);
		}
	}
}
