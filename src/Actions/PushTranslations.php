<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Events\PushCompleted;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Lorisleiva\Actions\Concerns\AsAction;

final class PushTranslations
{
	use AsAction;

	private const BATCH_SIZE = 250;

	private const API_PER_PAGE = 100;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {}

	/**
	 * Push local translations to Linguist.
	 */
	public function handle(
		string $projectSlug,
		bool $overwrite = false,
		?array $specificLanguages = null,
		?callable $onProgress = null,
		bool $emitEvent = true
	): SyncResult {
		$languageResults = [];
		$errors = [];
		$keysProcessed = 0;
		$keysFailed = 0;

		try {
			$this->apiClient->setProjectSlug($projectSlug);

			$localTranslations = CollectLocalTranslations::run($projectSlug);

			if (empty($localTranslations)) {
				return new SyncResult(
					overallSuccess: false,
					errors: ['No local translation files found.'],
				);
			}

			if ($specificLanguages !== null) {
				$localTranslations = array_intersect_key($localTranslations, array_flip($specificLanguages));
			}

			$translationsByKey = [];
			$keyLanguages = [];
			$languageErrors = [];
			$languageIdMap = $this->apiClient->getProjectLanguageIdMap();

			foreach ($localTranslations as $language => $translations) {
				$languageErrors[$language] = [];
				$languageId = $languageIdMap[strtoupper($language)] ?? null;

				if (! is_int($languageId)) {
					$languageErrors[$language][] = "Remote language mapping for '{$language}' not found.";
					$keysFailed += count($translations);

					continue;
				}

				foreach ($translations as $key => $text) {
					$translationsByKey[$key][$languageId] = $this->convertToLinguistVariableFormat($text);
					$keyLanguages[$key][] = $language;
				}
			}

			$totalKeys = count($translationsByKey);
			$currentKey = 0;

			if (! $overwrite && $translationsByKey !== []) {
				$existingRemoteKeys = $this->fetchExistingRemoteKeySet();

				$translationsByKey = array_filter(
					$translationsByKey,
					static fn (string $key): bool => ! isset($existingRemoteKeys[$key]),
					ARRAY_FILTER_USE_KEY
				);

				$totalKeys = count($translationsByKey);
			}

			$batches = array_chunk($translationsByKey, self::BATCH_SIZE, true);

			foreach ($batches as $batch) {
				$payload = [];

				foreach ($batch as $key => $translationsForKey) {
					$payload[] = [
						'key' => (string) $key,
						'translations' => $translationsForKey,
					];
				}

				try {
					$response = $this->apiClient->storeTranslationKeys($payload);

					if ($response->successful()) {
						foreach ($batch as $key => $translationsForKey) {
							$keysProcessed++;
							$currentKey++;

							if (is_callable($onProgress)) {
								$onProgress($currentKey, $totalKeys, (string) $key);
							}
						}

						continue;
					}

					$errors[] = sprintf(
						'Batch upload failed (status %d). Response: %s',
						$response->status(),
						substr($response->body(), 0, 300)
					);
				} catch (\Exception $e) {
					$errors[] = 'Batch upload threw exception. ' . $e->getMessage();
				}

				foreach ($batch as $key => $translationsForKey) {
					$keysFailed += count($translationsForKey);

					foreach (array_unique($keyLanguages[(string) $key] ?? []) as $language) {
						$languageErrors[$language][] = "Failed to store key '{$key}' in batch upload.";
					}

					$currentKey++;

					if (is_callable($onProgress)) {
						$onProgress($currentKey, $totalKeys, (string) $key);
					}
				}
			}

			foreach ($localTranslations as $language => $translations) {
				$languageSuccess = empty($languageErrors[$language]);
				$languageResults[$language] = [
					'success' => $languageSuccess,
					'message' => $languageSuccess ? null : implode(', ', array_slice($languageErrors[$language], 0, 3)),
				];
			}

			$successCount = count(array_filter($languageResults, fn ($r) => $r['success']));

			$result = new SyncResult(
				overallSuccess: $successCount === count($languageResults),
				languageResults: $languageResults,
				errors: $errors,
				keysProcessed: $keysProcessed,
				keysFailed: $keysFailed,
			);

			if ($emitEvent && $result->overallSuccess) {
				event(new PushCompleted($projectSlug, $result));
			}

			return $result;

		} catch (\Exception $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: [$e->getMessage()],
				keysProcessed: $keysProcessed,
				keysFailed: $keysFailed,
			);
		}
	}

	private function convertToLinguistVariableFormat(string $text): string
	{
		return preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '{{ $1 }}', $text) ?? $text;
	}

	/**
	 * @return array<string, bool>
	 */
	private function fetchExistingRemoteKeySet(): array
	{
		$keys = [];
		$page = 1;

		while (true) {
			$response = $this->apiClient->listTranslationKeys([
				'per_page' => self::API_PER_PAGE,
				'page' => $page,
			]);

			if (! $response->successful()) {
				break;
			}

			$data = $response->json('data', []);

			if (! is_array($data) || $data === []) {
				break;
			}

			foreach ($data as $item) {
				if (! is_array($item)) {
					continue;
				}

				$key = (string) ($item['key'] ?? '');

				if ($key !== '') {
					$keys[$key] = true;
				}
			}

			$lastPage = $response->json('meta.last_page');

			if (is_numeric($lastPage) && $page >= (int) $lastPage) {
				break;
			}

			if (count($data) < self::API_PER_PAGE) {
				break;
			}

			$page++;
		}

		return $keys;
	}
}
