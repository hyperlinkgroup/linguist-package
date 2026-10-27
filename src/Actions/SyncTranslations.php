<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Events\SyncCompleted;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Hyperlinkgroup\Linguist\Support\LanguageCode;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncTranslations
{
	use AsAction;

	private const FIRST_PAGE = 1;

	private const API_PER_PAGE = 100;

	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {}

	/**
	 * Two-way sync: upload local keys first, then download remote state.
	 */
	public function handle(
		string $projectSlug,
		bool $pruneRemoteKeys = false,
		bool $activateMissingLanguages = true,
		string $syncSource = 'remote',
		?callable $onPushProgress = null
	): SyncResult {
		try {
			$this->apiClient->setProjectSlug($projectSlug);
			$normalizedSyncSource = strtolower($syncSource) === 'local' ? 'local' : 'remote';
			[$uploadLanguages, $preLanguageResults, $preErrors] = $this->resolveUploadPlan($projectSlug, $activateMissingLanguages);

			$preKeysProcessed = 0;
			$preKeysFailed = 0;

			if ($pruneRemoteKeys) {
				$unreadableFiles = CollectLocalTranslations::findUnreadableFiles($projectSlug);

				// Keys from a broken local file look deleted locally, so pruning would remove them remotely.
				if ($unreadableFiles !== []) {
					$preErrors[] = 'Skipped pruning remote keys because local files are not valid JSON: '
						. implode(', ', array_merge(...array_values($unreadableFiles))) . '.';
				} else {
					$localKeys = $this->collectLocalKeySet($projectSlug);
					$pruneResult = $this->pruneRemoteKeysNotPresentLocally($localKeys);
					$preErrors = array_merge($preErrors, $pruneResult['errors']);
					$preKeysProcessed += $pruneResult['processed'];
					$preKeysFailed += $pruneResult['failed'];
				}
			}

			$pushResult = $uploadLanguages === null
				? new SyncResult(overallSuccess: true)
				: (new PushTranslations($this->apiClient))->handle(
					projectSlug: $projectSlug,
					overwrite: $normalizedSyncSource === 'local',
					specificLanguages: $uploadLanguages,
					onProgress: $onPushProgress,
					emitEvent: false,
				);
			// Pulling a language that was not pushed would overwrite local keys that never reached the server.
			// Languages with pre-results were not uploaded at all: a failed one is skipped as an error,
			// a deliberately skipped one (--no-activate-missing-languages) is left alone without failing.
			[$deliberatelySkipped, $failedBeforePush] = collect($preLanguageResults)
				->partition(static fn (array $result): bool => $result['success']);
			$pullResult = $pushResult->aborted
				? new SyncResult(overallSuccess: false, errors: ['Pull skipped because push failed.'])
				: (new PullTranslations($this->apiClient))->handle(
					projectSlug: $projectSlug,
					emitEvent: false,
					skipLanguages: [...$failedBeforePush->keys()->all(), ...$pushResult->getFailedLanguages()],
					keepLanguages: $deliberatelySkipped->keys()->all(),
				);

			$languageResults = $this->mergeLanguageResults($pushResult, $pullResult, $preLanguageResults);
			$errors = array_values(array_unique(array_merge(
				$preErrors,
				array_map(static fn (string $error): string => "Push phase: {$error}", $pushResult->errors),
				array_map(static fn (string $error): string => "Pull phase: {$error}", $pullResult->errors),
			)));

			$result = new SyncResult(
				overallSuccess: $errors === [] && $pushResult->overallSuccess && $pullResult->overallSuccess,
				languageResults: $languageResults,
				errors: $errors,
				keysProcessed: $preKeysProcessed + $pushResult->keysProcessed + $pullResult->keysProcessed,
				keysFailed: $preKeysFailed + $pushResult->keysFailed + $pullResult->keysFailed,
				aborted: $pushResult->aborted || $pullResult->aborted,
			);

			if ($result->overallSuccess) {
				event(new SyncCompleted($projectSlug, $result));
			}

			return $result;

		} catch (\Exception $e) {
			return new SyncResult(
				overallSuccess: false,
				errors: [$e->getMessage()],
				aborted: true,
			);
		}
	}

	/**
	 * Merge push + pull language outcomes into a single per-language status.
	 *
	 * @param  array<string, array{success: bool, message?: string}>  $preLanguageResults
	 * @return array<string, array{success: bool, message?: string}>
	 */
	private function mergeLanguageResults(SyncResult $pushResult, SyncResult $pullResult, array $preLanguageResults = []): array
	{
		// The pull may name a language differently (PT-BR vs. local PT_BR); report it under the local key.
		$localKeys = collect([...array_keys($preLanguageResults), ...array_keys($pushResult->languageResults)])
			->mapWithKeys(static fn (string $language): array => [LanguageCode::normalize($language) => $language]);
		$pullLanguageResults = collect($pullResult->languageResults)
			->mapWithKeys(static fn (array $result, string $language): array => [$localKeys->get(LanguageCode::normalize($language), $language) => $result])
			->all();

		$allLanguages = collect([
			...array_keys($preLanguageResults),
			...array_keys($pushResult->languageResults),
			...array_keys($pullLanguageResults),
		])->unique();

		$mergedResults = $allLanguages->mapWithKeys(function (string $language) use ($pushResult, $pullLanguageResults, $preLanguageResults): array {
			$preLanguageResult = $preLanguageResults[$language] ?? ['success' => true];
			$pushLanguageResult = $pushResult->languageResults[$language] ?? ['success' => true];
			$pullLanguageResult = $pullLanguageResults[$language] ?? ['success' => true];

			$result = [
				'success' => $preLanguageResult['success'] && $pushLanguageResult['success'] && $pullLanguageResult['success'],
			];

			$message = collect([
				'' => $preLanguageResult,
				'Push: ' => $pushLanguageResult,
				'Pull: ' => $pullLanguageResult,
			])
				->filter(static fn (array $phaseResult): bool => filled($phaseResult['message'] ?? null))
				->map(static fn (array $phaseResult, string $prefix): string => $prefix . $phaseResult['message'])
				->implode(' | ');

			if ($message !== '') {
				$result['message'] = $message;
			}

			return [$language => $result];
		});

		return $mergedResults->all();
	}

	/**
	 * Determine upload languages and apply/skip missing language activation.
	 *
	 * @return array{0: array<string>|null, 1: array<string, array{success: bool, message?: string}>, 2: array<string>}
	 */
	private function resolveUploadPlan(string $projectSlug, bool $activateMissingLanguages): array
	{
		$localLanguages = collect(CollectLocalTranslations::detectLanguages())
			->map(static fn (string $language): string => strtoupper($language))
			->unique()
			->values()
			->all();

		if ($localLanguages === []) {
			return [null, [], []];
		}

		$languageMap = $this->apiClient->getProjectLanguageIdMap();
		$mappedLanguageCodes = array_keys($languageMap);
		$missingLanguageCodes = array_values(array_diff($localLanguages, $mappedLanguageCodes));

		$languageResults = [];
		$errors = [];
		$reportedLanguageCodes = [];

		if ($missingLanguageCodes !== [] && $activateMissingLanguages) {
			$activationResult = $this->activateMissingLanguages($projectSlug, $missingLanguageCodes, $languageMap);
			$errors = array_merge($errors, $activationResult['errors']);
			$reportedLanguageCodes = $activationResult['reported_language_codes'];
			$languageResults = array_merge($languageResults, $activationResult['language_results']);
			$languageMap = $activationResult['language_map'];
			$mappedLanguageCodes = array_keys($languageMap);
			$missingLanguageCodes = array_values(array_diff($localLanguages, $mappedLanguageCodes));
		}

		if ($missingLanguageCodes !== []) {
			// E.g. the activation request succeeded, but the refreshed map still lacks the language.
			$unreportedLanguageCodes = array_diff($missingLanguageCodes, $reportedLanguageCodes);

			if ($activateMissingLanguages && $unreportedLanguageCodes !== []) {
				$errors[] = 'Failed to activate local languages remotely before upload: ' . implode(', ', $unreportedLanguageCodes) . '.';
			}

			foreach ($missingLanguageCodes as $languageCode) {
				$languageResults[$languageCode] = [
					'success' => ! $activateMissingLanguages,
					'message' => $activateMissingLanguages
						? 'Failed to activate this local language remotely before upload.'
						: 'Skipped upload for this local language because --no-activate-missing-languages was set.',
				];
			}
		}

		$uploadLanguages = array_values(array_intersect($localLanguages, $mappedLanguageCodes));

		return [$uploadLanguages === [] ? null : $uploadLanguages, $languageResults, $errors];
	}

	/**
	 * Best-effort language activation for local languages not currently active in the project.
	 *
	 * @param  array<string>  $missingLanguageCodes
	 * @param  array<string, int>  $currentLanguageMap
	 * @return array{
	 * 	language_map: array<string, int>,
	 * 	language_results: array<string, array{success: bool, message?: string}>,
	 * 	errors: array<string>,
	 * 	reported_language_codes: array<string>
	 * }
	 */
	private function activateMissingLanguages(string $projectSlug, array $missingLanguageCodes, array $currentLanguageMap): array
	{
		$languageResults = [];
		$errors = [];
		$response = $this->apiClient->listProjects(['per_page' => self::API_PER_PAGE]);

		if (! $response->successful()) {
			$errors[] = 'Failed to resolve project metadata for language activation.';

			return [
				'language_map' => $currentLanguageMap,
				'language_results' => $languageResults,
				'errors' => $errors,
				'reported_language_codes' => $missingLanguageCodes,
			];
		}

		$project = collect($response->json('data', []))
			->first(fn (array $candidate): bool => ($candidate['slug'] ?? null) === $projectSlug);

		if (! is_array($project)) {
			$errors[] = "Failed to resolve project '{$projectSlug}' for language activation.";

			return [
				'language_map' => $currentLanguageMap,
				'language_results' => $languageResults,
				'errors' => $errors,
				'reported_language_codes' => $missingLanguageCodes,
			];
		}

		$projectLanguageMap = $this->extractLanguageMapFromProjectPayload($project);
		$currentTargetIds = collect($project['languages'] ?? [])
			->filter(static fn (mixed $language): bool => is_array($language) && is_numeric($language['id'] ?? null))
			->map(static fn (array $language): int => (int) $language['id'])
			->values()
			->all();

		$languageIdsByCode = collect($missingLanguageCodes)
			->mapWithKeys(static fn (string $languageCode): array => [$languageCode => $projectLanguageMap[$languageCode] ?? null]);

		$idsToActivate = $languageIdsByCode
			->filter(static fn (mixed $languageId): bool => $languageId !== null)
			->values()
			->all();

		$unresolvableLanguageCodes = $languageIdsByCode
			->filter(static fn (mixed $languageId): bool => $languageId === null)
			->keys()
			->map(static fn (mixed $languageCode): string => (string) $languageCode)
			->values()
			->all();

		// Languages already named by an error here, so the caller does not report them a second time.
		$reportedLanguageCodes = $unresolvableLanguageCodes;

		if ($idsToActivate !== []) {
			$updatedTargetLanguageIds = array_values(array_unique(array_merge($currentTargetIds, $idsToActivate)));
			$updateResponse = $this->apiClient->updateProject([
				'translation_languages' => $updatedTargetLanguageIds,
			]);

			if (! $updateResponse->successful()) {
				$errors[] = 'Failed to activate missing local languages remotely: ' . $updateResponse->body();
				$reportedLanguageCodes = $missingLanguageCodes;
			} else {
				$freshMap = $this->apiClient->getProjectLanguageIdMap();

				if ($freshMap === []) {
					$activatedLanguageCodes = $languageIdsByCode->filter(static fn (mixed $languageId): bool => $languageId !== null)->keys()->all();
					$errors[] = 'Activated local languages remotely, but could not reload their IDs for upload: ' . implode(', ', $activatedLanguageCodes) . '.';
					$reportedLanguageCodes = $missingLanguageCodes;
				} else {
					$currentLanguageMap = $freshMap;
				}
			}
		}

		if ($unresolvableLanguageCodes !== []) {
			$list = implode(', ', $unresolvableLanguageCodes);
			$errors[] = "Could not resolve remote language IDs for local languages: {$list}.";

			foreach ($unresolvableLanguageCodes as $languageCode) {
				$languageCode = (string) $languageCode;
				$languageResults[$languageCode] = [
					'success' => false,
					'message' => 'Unable to resolve remote language ID for automatic activation.',
				];
			}
		}

		return [
			'language_map' => $currentLanguageMap,
			'language_results' => $languageResults,
			'errors' => $errors,
			'reported_language_codes' => $reportedLanguageCodes,
		];
	}

	/**
	 * Build a language code -> ID map from any language-like structures in project payloads.
	 *
	 * @param  array<string, mixed>  $project
	 * @return array<string, int>
	 */
	private function extractLanguageMapFromProjectPayload(array $project): array
	{
		return collect(['languages', 'available_languages', 'all_languages'])
			->map(static fn (string $key): mixed => $project[$key] ?? [])
			->filter(static fn (mixed $languages): bool => is_array($languages))
			->flatten(1)
			->when(
				is_array($project['language'] ?? null),
				static fn ($languages) => $languages->push($project['language']),
			)
			->filter(static fn (mixed $language): bool => is_array($language))
			->mapWithKeys(static function (array $language): array {
				$code = strtoupper((string) ($language['code'] ?? ''));
				$id = $language['id'] ?? null;

				if ($code === '' || ! is_numeric($id)) {
					return [];
				}

				return [$code => (int) $id];
			})
			->all();
	}

	/**
	 * @return array<string, bool> Set-like key map
	 */
	private function collectLocalKeySet(string $projectSlug): array
	{
		return collect(CollectLocalTranslations::run($projectSlug))
			->flatMap(static fn (array $translationsByKey): array => array_keys($translationsByKey))
			->mapWithKeys(static fn (mixed $key): array => [(string) $key => true])
			->all();
	}

	/**
	 * Remove remote keys that are not present in local translations.
	 *
	 * @param  array<string, bool>  $localKeySet
	 * @return array{processed: int, failed: int, errors: array<string>}
	 */
	private function pruneRemoteKeysNotPresentLocally(array $localKeySet): array
	{
		$remoteKeys = $this->fetchRemoteKeysByName();
		$processed = 0;
		$failed = 0;
		$errors = [];

		foreach ($remoteKeys as $key => $remoteId) {
			if (isset($localKeySet[$key])) {
				continue;
			}

			$response = $this->apiClient->deleteTranslationKey($remoteId);

			if ($response->successful()) {
				$processed++;
			} else {
				$failed++;
				$errors[] = "Failed pruning remote key '{$key}'.";
			}
		}

		return [
			'processed' => $processed,
			'failed' => $failed,
			'errors' => $errors,
		];
	}

	/**
	 * @return array<string, int> Key name => remote key ID
	 */
	private function fetchRemoteKeysByName(): array
	{
		$keys = [];
		$page = self::FIRST_PAGE;
		$perPage = self::API_PER_PAGE;

		while (true) {
			$response = $this->apiClient->listTranslationKeys([
				'per_page' => $perPage,
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
				$id = $item['id'] ?? null;

				if ($key !== '' && is_numeric($id)) {
					$keys[$key] = (int) $id;
				}
			}

			$lastPage = $response->json('meta.last_page');

			if (is_numeric($lastPage) && $page >= (int) $lastPage) {
				break;
			}

			if (count($data) < $perPage) {
				break;
			}

			$page++;
		}

		return $keys;
	}
}
