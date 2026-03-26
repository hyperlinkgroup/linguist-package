<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;

final class PullTranslations
{
	use AsAction;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {
	}

	/**
	 * Pull translations from Linguist and overwrite local files.
	 */
	public function handle(string $projectSlug): SyncResult
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

			$keysProcessed = 0;

			foreach ($languages as $language) {
				$result = $this->apiClient->downloadLanguageExportWithPrefixFallback($language);

				if ($result['success']) {
					$languageResults[$language] = ['success' => true];
					$keysProcessed += $result['key_count'];

					$body = $result['body'] ?? null;
					if (is_string($body) && $body !== '') {
						$this->writeDownloadedTranslations($language, $body);
					}
				} else {
					$message = $result['message'] ?? 'Failed to fetch export URL for this language.';
					$languageResults[$language] = [
						'success' => false,
						'message' => $message,
					];
					$errors[] = "{$language}: {$message}";
				}
			}

			$successCount = count(array_filter($languageResults, fn ($r) => $r['success']));

			return new SyncResult(
				overallSuccess: $successCount === count($languageResults),
				languageResults: $languageResults,
				errors: $errors,
				keysProcessed: $keysProcessed,
			);

		} catch (NoLanguageActivatedException $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: ['No languages are activated in your Linguist project.'],
			);
		} catch (\Exception $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: [$e->getMessage()],
			);
		}
	}

	private function writeDownloadedTranslations(string $language, string $body): void
	{
		$langDir = lang_path(strtoupper($language));
		File::ensureDirectoryExists($langDir);
		File::put("{$langDir}/linguist.json", $body);
	}
}
