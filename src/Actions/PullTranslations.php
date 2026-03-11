<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;

final class PullTranslations
{
	use AsAction;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
		private readonly string $temporaryDirectory = 'tmp/translations',
	) {
	}

	/**
	 * Pull translations from Linguist and overwrite local files.
	 */
	public function handle(string $projectSlug): SyncResult
	{
		$languageResults = [];

		try {
			$this->apiClient->setProjectSlug($projectSlug);

			$languagesResponse = $this->apiClient->getLanguages();

			if (! $languagesResponse->successful()) {
				return new SyncResult(
					overallSuccess: false,
					errors: ['Failed to fetch languages: ' . $languagesResponse->body()],
				);
			}

			$languages = collect($languagesResponse->json('data', []));

			if ($languages->isEmpty()) {
				throw new NoLanguageActivatedException();
			}

			File::ensureDirectoryExists(storage_path($this->temporaryDirectory));

			$exportRoutes = $this->fetchExportRoutes($languages);
			$downloadedLanguageKeyCounts = $this->downloadFiles($exportRoutes);

			foreach ($languages as $language) {
				if (array_key_exists($language, $downloadedLanguageKeyCounts)) {
					$languageResults[$language] = ['success' => true];
				} else {
					$languageResults[$language] = [
						'success' => false,
						'message' => 'Failed to download translations',
					];
				}
			}

			File::deleteDirectory(storage_path($this->temporaryDirectory));

			$successCount = count(array_filter($languageResults, fn ($r) => $r['success']));
			$keysProcessed = array_sum($downloadedLanguageKeyCounts);

			return new SyncResult(
				overallSuccess: $successCount === count($languageResults),
				languageResults: $languageResults,
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

	/**
	 * Fetch export URLs for all languages.
	 */
	private function fetchExportRoutes(Collection $languages): array
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
	 * Download translation files to temp storage.
	 *
	 * @return array<string, int> Downloaded language codes with processed key count
	 */
	private function downloadFiles(array $routes): array
	{
		$downloaded = [];

		foreach ($routes as $language => $url) {
			$response = $this->apiClient->downloadExport($url);

			if ($response->successful()) {
				$decoded = json_decode($response->body(), true);
				$keyCount = is_array($decoded) ? $this->countTranslationLeafs($decoded) : 0;

				$tempPath = storage_path($this->temporaryDirectory . "/{$language}.json");
				File::put($tempPath, $response->body());

				$langDir = lang_path(strtoupper($language));
				File::ensureDirectoryExists($langDir);
				File::put("{$langDir}/linguist.json", $response->body());

				$downloaded[$language] = $keyCount;
			}
		}

		return $downloaded;
	}

	private function countTranslationLeafs(array $translations): int
	{
		$count = 0;

		array_walk_recursive($translations, static function () use (&$count): void {
			$count++;
		});

		return $count;
	}
}
