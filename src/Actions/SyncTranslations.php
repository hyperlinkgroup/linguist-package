<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncTranslations
{
	use AsAction;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
		private readonly string $temporaryDirectory = 'tmp/translations',
	) {
	}

	/**
	 * Two-way sync: merge local and remote translations.
	 */
	public function handle(
		string $projectSlug,
		bool $pruneRemoteKeys = false,
		bool $activateMissingLanguages = true
	): SyncResult {
		$languageResults = [];
		$errors = [];
		$keysProcessed = 0;
		$keysFailed = 0;

		try {
			$this->apiClient->setProjectSlug($projectSlug);

			$languagesResponse = $this->apiClient->getLanguages();

			if (! $languagesResponse->successful()) {
				return new SyncResult(
					overallSuccess: false,
					errors: ['Failed to fetch languages: ' . $languagesResponse->body()],
				);
			}

			$remoteLanguages = collect($languagesResponse->json('data', []));
			$localLanguages = collect(CollectLocalTranslations::detectLanguages());

			File::ensureDirectoryExists(storage_path($this->temporaryDirectory));

			$exportRoutes = $this->fetchExportRoutes($remoteLanguages);
			$remoteFiles = $this->downloadToMemory($exportRoutes);
			$localTranslations = CollectLocalTranslations::run($projectSlug);

			$allLanguages = $remoteLanguages->merge($localLanguages)->unique();

			foreach ($allLanguages as $language) {
				$remoteTranslations = $remoteFiles[$language] ?? [];
				$localTranslationsForLang = $localTranslations[$language] ?? [];

				$merged = array_merge($localTranslationsForLang, $remoteTranslations);

				if (! empty($merged)) {
					$success = CollectLocalTranslations::writeTranslations($language, $projectSlug, $merged);

					$languageResults[$language] = [
						'success' => $success,
						'message' => $success ? null : 'Failed to write translations',
					];

					if ($success) {
						$keysProcessed += count($merged);
					} else {
						$keysFailed += count($merged);
					}
				} else {
					$languageResults[$language] = ['success' => true];
				}
			}

			File::deleteDirectory(storage_path($this->temporaryDirectory));

			$successCount = count(array_filter($languageResults, fn ($r) => $r['success']));

			return new SyncResult(
				overallSuccess: $successCount > 0,
				languageResults: $languageResults,
				errors: $errors,
				keysProcessed: $keysProcessed,
				keysFailed: $keysFailed,
			);

		} catch (\Exception $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: [$e->getMessage()],
				keysProcessed: $keysProcessed,
				keysFailed: $keysFailed,
			);
		}
	}

	/**
	 * Fetch export URLs for all languages.
	 */
	private function fetchExportRoutes(\Illuminate\Support\Collection $languages): array
	{
		$routes = [];

		$languages->each(function ($language) use (&$routes) {
			$response = $this->apiClient->getExportUrl($language);

			if ($response->successful()) {
				$routes[$language] = $response->json('url');
			}
		});

		return $routes;
	}

	/**
	 * Download translations to memory.
	 */
	private function downloadToMemory(array $routes): array
	{
		$files = [];

		foreach ($routes as $language => $url) {
			$response = $this->apiClient->downloadExport($url);

			if ($response->successful()) {
				$files[$language] = json_decode($response->body(), true) ?? [];
			}
		}

		return $files;
	}
}
