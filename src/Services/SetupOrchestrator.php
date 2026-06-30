<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Services;

use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Actions\SyncTranslations;
use Hyperlinkgroup\Linguist\DTO\SetupInput;
use Hyperlinkgroup\Linguist\DTO\SetupResult;
use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Illuminate\Support\Collection;

use function Laravel\Prompts\progress;

final class SetupOrchestrator
{
	public function __construct(
		private readonly LinguistApiClient $apiClient,
	) {}

	public function execute(SetupInput $input): SetupResult
	{
		$configPersisted = false;
		$projectCreated = false;
		$projectSlug = null;
		$syncResult = null;
		$autoTranslate = false;
		$errors = [];

		try {
			$linguistApiClient = $this->clientForToken($input->apiToken);

			// Step 1: Persist API token config
			$configResults = PersistLinguistConfig::run([
				'token' => $input->apiToken,
				'url' => config('linguist.url', 'https://api.linguist.eu/v2'),
			]);

			$configPersisted = $configResults['token'] ?? false;

			if (! $configPersisted) {
				$errors[] = 'Failed to persist API token configuration.';

				return new SetupResult(
					configPersisted: false,
					errors: $errors,
				);
			}

			// Step 2: Determine project
			$projectSlug = $this->resolveProject(
				runtimeClient: $linguistApiClient,
				input: $input,
				projectCreated: $projectCreated,
				errors: $errors
			);

			if ($projectSlug === null) {
				return new SetupResult(
					configPersisted: $configPersisted,
					errors: $errors,
				);
			}

			// Step 3: Persist project slug to config
			PersistLinguistConfig::run(['project' => $projectSlug]);

			// Step 4: Update API client with project
			$linguistApiClient->setProjectSlug($projectSlug);
			$this->apiClient->setProjectSlug($projectSlug);

			// Step 5: Execute sync based on mode
			$syncResult = $this->executeSync(
				linguistApiClient: $linguistApiClient,
				input: $input,
				projectSlug: $projectSlug
			);

			// Step 6: Trigger auto-translation if requested
			if ($input->triggerAutoTranslate && ($syncResult?->overallSuccess ?? false)) {
				$autoTranslate = $this->triggerAutoTranslate(
					runtimeClient: $linguistApiClient,
				);
			}

		} catch (\Exception $e) {
			$errors[] = $e->getMessage();
		}

		return new SetupResult(
			configPersisted: $configPersisted,
			projectCreated: $projectCreated,
			projectSlug: $projectSlug,
			syncResult: $syncResult,
			autoTranslate: $autoTranslate,
			errors: $errors,
		);
	}

	private function resolveProject(
		LinguistApiClient $runtimeClient,
		SetupInput $input,
		bool &$projectCreated,
		array &$errors,
	): ?string {
		if ($input->isNewProject()) {
			if ($input->newProjectTeamId === null || $input->newProjectTeamId < 1) {
				$errors[] = 'A team must be selected to create a project.';

				return null;
			}

			$createData = [
				'name' => $input->newProjectName,
				'description' => 'Created via linguist:setup',
				'team_id' => $input->newProjectTeamId,
				'language_id' => $input->sourceLanguageId ?? $this->detectSourceLanguageId(),
				'translation_languages' => $input->targetLanguageIds,
				'translation_is_active' => true,
			];

			$response = $runtimeClient->createProject($createData);

			if (! $response->successful()) {
				$errors[] = 'Failed to create project: ' . $response->body();

				return null;
			}

			$projectData = $response->json('data');
			$projectCreated = true;

			return $projectData['slug'] ?? null;
		}

		if ($input->isExistingProject()) {
			return $input->projectSlug;
		}

		$errors[] = 'No project specified (neither new nor existing).';

		return null;
	}

	/**
	 * Execute the appropriate sync mode.
	 */
	private function executeSync(LinguistApiClient $linguistApiClient, SetupInput $input, string $projectSlug): ?SyncResult
	{
		$onPushProgress = $this->pushProgressCallback();

		return match ($input->syncMode) {
			'pull' => (new PullTranslations($linguistApiClient))->handle($projectSlug),
			'push' => (new PushTranslations($linguistApiClient))->handle(
				projectSlug: $projectSlug,
				onProgress: $onPushProgress,
			),
			'sync' => (new SyncTranslations($linguistApiClient))->handle(
				projectSlug: $projectSlug,
				pruneRemoteKeys: $input->pruneRemoteKeys,
				activateMissingLanguages: $input->activateMissingLanguages,
				syncSource: $input->syncSource,
				onPushProgress: $onPushProgress,
			),
			default => null,
		};
	}

	/**
	 * @return callable(int $current, int $total, string $key): void
	 */
	private function pushProgressCallback(): callable
	{
		$uploadProgress = null;

		return function (int $current, int $total, string $key) use (&$uploadProgress): void {
			if ($total <= 0) {
				return;
			}

			if ($uploadProgress === null) {
				$uploadProgress = progress(
					label: 'Uploading translation keys',
					steps: $total,
				);
				$uploadProgress->start();
			}

			$uploadProgress->hint("Key: {$key}");
			$uploadProgress->advance();

			if ($current >= $total) {
				$uploadProgress->finish();
			}
		};
	}

	/**
	 * Trigger auto-translation for the project.
	 */
	private function triggerAutoTranslate(LinguistApiClient $runtimeClient): bool
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
			baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
			token: $token,
		);
	}

	/**
	 * Validate API token by making a test request.
	 */
	public function validateApiToken(string $token): bool
	{
		$client = new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
			token: $token,
		);

		$response = $client->listProjects();

		return $response->successful();
	}
}
