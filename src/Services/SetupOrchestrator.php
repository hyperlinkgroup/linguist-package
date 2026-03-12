<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Services;

use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Actions\SyncTranslations;
use Hyperlinkgroup\Linguist\DTO\SetupInput;
use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Illuminate\Support\Collection;

final class SetupOrchestrator
{
	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {
	}

	/**
	 * Execute the full setup flow.
	 */
	public function execute(SetupInput $input): array
	{
		$results = [
			'config_persisted' => false,
			'project_created' => false,
			'project_slug' => null,
			'sync_result' => null,
			'auto_translate' => false,
			'errors' => [],
		];

		try {
			$runtimeClient = $this->clientForToken($input->apiToken);

			// Step 1: Persist API token config
			$configResults = PersistLinguistConfig::run([
				'token' => $input->apiToken,
				'url' => config('linguist.url', 'https://api.linguist.eu/'),
			]);

			$results['config_persisted'] = $configResults['token'] ?? false;

			if (! $results['config_persisted']) {
				$results['errors'][] = 'Failed to persist API token configuration.';

				return $results;
			}

			// Step 2: Determine project
			$projectSlug = $this->resolveProject($runtimeClient, $input, $results);

			if ($projectSlug === null) {
				return $results;
			}

			$results['project_slug'] = $projectSlug;

			// Step 3: Persist project slug to config
			PersistLinguistConfig::run(['project' => $projectSlug]);

			// Step 4: Update API client with project
			$runtimeClient->setProjectSlug($projectSlug);
			$this->apiClient->setProjectSlug($projectSlug);

			// Step 5: Execute sync based on mode
			$syncResult = $this->executeSync($runtimeClient, $input, $projectSlug);
			$results['sync_result'] = $syncResult;

			// Step 6: Trigger auto-translation if requested
			if ($input->triggerAutoTranslate && ($syncResult?->overallSuccess ?? false)) {
				$results['auto_translate'] = $this->triggerAutoTranslate($runtimeClient, $input);
			}

		} catch (\Exception $e) {
			$results['errors'][] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Resolve project (create new or use existing).
	 */
	private function resolveProject(LinguistApiClient $runtimeClient, SetupInput $input, array &$results): ?string
	{
		if ($input->isNewProject()) {
			$createData = [
				'name' => $input->newProjectName,
				'description' => 'Created via linguist:setup',
				'language_id' => $input->sourceLanguageId ?? $this->detectSourceLanguageId(),
				'translation_languages' => $input->targetLanguageIds,
				'translation_is_active' => true,
			];

			$response = $runtimeClient->createProject($createData);

			if (! $response->successful()) {
				$results['errors'][] = 'Failed to create project: ' . $response->body();

				return null;
			}

			$projectData = $response->json('data');
			$results['project_created'] = true;

			return $projectData['slug'] ?? null;
		}

		if ($input->isExistingProject()) {
			return $input->projectSlug;
		}

		$results['errors'][] = 'No project specified (neither new nor existing).';

		return null;
	}

	/**
	 * Execute the appropriate sync mode.
	 */
	private function executeSync(LinguistApiClient $runtimeClient, SetupInput $input, string $projectSlug): ?SyncResult
	{
		return match ($input->syncMode) {
			'pull' => (new PullTranslations($runtimeClient))->handle($projectSlug),
			'push' => (new PushTranslations($runtimeClient))->handle($projectSlug),
			'sync' => (new SyncTranslations($runtimeClient))->handle(
				projectSlug: $projectSlug,
				pruneRemoteKeys: $input->pruneRemoteKeys,
				activateMissingLanguages: $input->activateMissingLanguages,
			),
			default => null,
		};
	}

	/**
	 * Trigger auto-translation for the project.
	 */
	private function triggerAutoTranslate(LinguistApiClient $runtimeClient, SetupInput $input): bool
	{
		$options = [
			'overwrite_automatic_translations' => false,
			'overwrite_manual_translations' => false,
		];

		$response = $runtimeClient->triggerAutoTranslate($options);

		return $response->successful();
	}

	/**
	 * Detect source language from local files or default to first available.
	 */
	private function detectSourceLanguageId(): ?int
	{
		$configuredSourceLanguageId = config('linguist.source_language_id');

		if (! is_numeric($configuredSourceLanguageId)) {
			return null;
		}

		$sourceLanguageId = (int) $configuredSourceLanguageId;

		return $sourceLanguageId > 0 ? $sourceLanguageId : null;
	}

	/**
	 * List available projects for selection.
	 */
	public function listAvailableProjects(string $token): Collection
	{
		$response = $this->clientForToken($token)->listProjects();

		if (! $response->successful()) {
			return collect();
		}

		return collect($response->json('data', []));
	}

	private function clientForToken(string $token): LinguistApiClient
	{
		return new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/'),
			token: $token,
		);
	}

	/**
	 * Validate API token by making a test request.
	 */
	public function validateApiToken(string $token): bool
	{
		$client = new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/'),
			token: $token,
		);

		$response = $client->listProjects();

		return $response->successful();
	}
}
