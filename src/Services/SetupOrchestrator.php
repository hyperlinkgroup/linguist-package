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
		// #region agent log
		$this->debugLog('H2', 'SetupOrchestrator:validateApiToken', 'Starting token validation request', [
			'base_url_config' => (string) config('linguist.url', 'https://api.linguist.eu/'),
			'base_url_trimmed' => rtrim((string) config('linguist.url', 'https://api.linguist.eu/'), '/'),
			'token_length' => strlen($token),
			'token_trimmed_length' => strlen(trim($token)),
			'token_has_whitespace_edges' => trim($token) !== $token,
		]);
		// #endregion

		$client = new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/'),
			token: $token,
		);

		$response = $client->listProjects();

		// #region agent log
		$this->debugLog('H3', 'SetupOrchestrator:validateApiToken', 'Token validation response received', [
			'status' => $response->status(),
			'successful' => $response->successful(),
			'request_url' => $response->effectiveUri()?->getPath(),
			'request_url_full' => $response->effectiveUri() ? (string) $response->effectiveUri() : null,
			'body_preview' => mb_substr($response->body(), 0, 300),
		]);
		// #endregion

		return $response->successful();
	}

	private function debugLog(string $hypothesisId, string $location, string $message, array $data = []): void
	{
		$payload = json_encode([
			'sessionId' => '22592b',
			'runId' => 'run1',
			'hypothesisId' => $hypothesisId,
			'location' => $location,
			'message' => $message,
			'data' => $data,
			'timestamp' => (int) floor(microtime(true) * 1000),
		], JSON_UNESCAPED_SLASHES);

		if (! is_string($payload)) {
			return;
		}

		@file_put_contents('/Users/rubenmaurer/Offcloud/linguist-package/.cursor/debug-22592b.log', $payload . PHP_EOL, FILE_APPEND);
	}
}
