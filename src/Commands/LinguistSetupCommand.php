<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Commands;

use Hyperlinkgroup\Linguist\Actions\CollectLocalTranslations;
use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Hyperlinkgroup\Linguist\DTO\SetupInput;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Hyperlinkgroup\Linguist\Services\SetupOrchestrator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

final class LinguistSetupCommand extends Command
{
	protected $signature = 'linguist:setup';

	protected $description = 'Interactive wizard to set up Linguist integration';

	private SetupOrchestrator $orchestrator;

	public function handle(): int
	{
		$this->components->info('Linguist Setup Wizard');
		$this->newLine();

		// Initialize services
		$this->initializeServices();

		// Check if already configured
		if (PersistLinguistConfig::hasValidConfig()) {
			$this->components->info('Current configuration:');
			$summary = PersistLinguistConfig::getConfigSummary();
			foreach ($summary as $key => $value) {
				$this->line("  {$key}: {$value}");
			}
			$this->newLine();

			if (! confirm('Do you want to reconfigure Linguist?', false)) {
				$this->components->info('Setup cancelled.');

				return SymfonyCommand::SUCCESS;
			}
		}

		// Step 1: API Token
		$apiToken = $this->getApiToken();
		if ($apiToken === null) {
			return SymfonyCommand::FAILURE;
		}

		// Step 2: Validate token
		$isTokenValid = $this->orchestrator->validateApiToken($apiToken);

		// #region agent log
		$this->debugLog('H4', 'LinguistSetupCommand:handle', 'Token validation result returned by orchestrator', [
			'is_token_valid' => $isTokenValid,
		]);
		// #endregion

		if (! $isTokenValid) {
			$this->error('Invalid API token. Please check your token and try again.');

			return SymfonyCommand::FAILURE;
		}

		$this->components->info('API token validated successfully.');
		$this->newLine();

		// Step 3: Project selection
		$projectChoice = $this->getProjectChoice($apiToken);
		if ($projectChoice === null) {
			return SymfonyCommand::FAILURE;
		}

		$this->renderKeyDiscoverySummary($apiToken, $projectChoice);

		// Step 4: Sync mode
		$syncMode = $this->getSyncMode();

		// Step 5: Mode-specific options
		$pruneRemoteKeys = false;
		$activateMissingLanguages = true;
		$triggerAutoTranslate = false;

		if (in_array($syncMode, ['sync', 'push'], true)) {
			$pruneRemoteKeys = confirm(
				'Remove keys from Linguist that are not present in local files?',
				false
			);
		}

		if (in_array($syncMode, ['sync', 'pull'], true)) {
			$activateMissingLanguages = confirm(
				'Activate languages that exist locally but not in the Linguist project?',
				true
			);
		}

		// Check if auto-translation is available
		$triggerAutoTranslate = confirm(
			'Trigger automatic translation of all keys? (requires DeepL API key)',
			false
		);

		// Build input DTO
		$input = new SetupInput(
			apiToken: $apiToken,
			projectSlug: $projectChoice['type'] === 'existing' ? $projectChoice['slug'] : null,
			newProjectName: $projectChoice['type'] === 'new' ? $projectChoice['name'] : null,
			syncMode: $syncMode,
			pruneRemoteKeys: $pruneRemoteKeys,
			activateMissingLanguages: $activateMissingLanguages,
			triggerAutoTranslate: $triggerAutoTranslate,
			sourceLanguageId: $projectChoice['source_language_id'] ?? null,
			targetLanguageIds: $projectChoice['target_language_ids'] ?? [],
		);

		// Execute setup
		$this->newLine();
		$this->components->info('Executing setup...');
		$this->newLine();

		$results = $this->orchestrator->execute($input);

		// Report results
		return $this->reportResults($results);
	}

	private function initializeServices(): void
	{
		$this->orchestrator = app(SetupOrchestrator::class);
	}

	private function getApiToken(): ?string
	{
		$token = password(
			label: 'Enter your Linguist API token',
			required: true
		);

		// #region agent log
		$this->debugLog('H1', 'LinguistSetupCommand:getApiToken', 'Token captured from wizard prompt', [
			'is_null' => $token === null,
			'length' => is_string($token) ? strlen($token) : 0,
			'trimmed_length' => is_string($token) ? strlen(trim($token)) : 0,
			'has_leading_or_trailing_whitespace' => is_string($token) ? trim($token) !== $token : false,
		]);
		// #endregion

		if ($token === null || trim($token) === '') {
			$this->error('API token is required.');

			return null;
		}

		return $token;
	}

	private function getProjectChoice(string $apiToken): ?array
	{
		$projects = $this->orchestrator->listAvailableProjects($apiToken);

		if ($projects->isNotEmpty()) {
			$this->components->info('Your existing projects:');

			$choices = $projects->mapWithKeys(function ($project) {
				return [
					$project['slug'] => "{$project['name']} ({$project['slug']})",
				];
			})->toArray();

			$choices['__new__'] = 'Create a new project';

			$selectedSlug = select(
				label: 'Select a project or create a new one',
				options: $choices
			);

			if ($selectedSlug === '__new__') {
				return $this->createNewProjectChoice();
			}

			if ($selectedSlug === '') {
				$this->error('Unable to resolve selected project.');

				return null;
			}

			return ['type' => 'existing', 'slug' => $selectedSlug];
		}

		$this->components->info('No existing projects found. Creating a new project.');
		$this->newLine();

		return $this->createNewProjectChoice();
	}

	private function createNewProjectChoice(): ?array
	{
		$name = text(
			label: 'Enter a name for the new project',
			required: true
		);

		if ($name === null || trim($name) === '') {
			$this->error('Project name is required.');

			return null;
		}

		$sourceLanguage = text(
			label: 'Source language ID (press Enter to skip)',
			default: '',
			validate: fn (string $value) => $value === '' || ctype_digit($value) ? null : 'Source language ID must be a positive integer.'
		);

		$targetLanguagesInput = text(
			label: 'Target language IDs (comma-separated, e.g., 2,3,4)',
			default: ''
		);
		$targetLanguages = [];

		if ($targetLanguagesInput !== null && trim($targetLanguagesInput) !== '') {
			$targetLanguages = array_filter(
				array_map('intval', explode(',', $targetLanguagesInput)),
				fn ($id) => $id > 0
			);
		}

		return [
			'type' => 'new',
			'name' => $name,
			'source_language_id' => $sourceLanguage ? (int) $sourceLanguage : null,
			'target_language_ids' => $targetLanguages,
		];
	}

	private function getSyncMode(): string
	{
		return select(
			label: 'Select sync mode',
			options: [
				'sync' => 'Sync - Merge local and remote translations',
				'pull' => 'Pull - Download translations from Linguist (overwrite local)',
				'push' => 'Push - Upload local translations to Linguist',
			],
			default: 'sync'
		);
	}

	private function renderKeyDiscoverySummary(string $apiToken, array $projectChoice): void
	{
		$localTranslations = CollectLocalTranslations::run();
		$localKeyCount = collect($localTranslations)
			->flatMap(fn (array $translations): array => array_keys($translations))
			->unique()
			->count();

		$remoteKeyCount = 0;

		if (($projectChoice['type'] ?? '') === 'existing' && isset($projectChoice['slug'])) {
			try {
				$client = new LinguistApiClient(
					baseUrl: config('linguist.url', 'https://api.linguist.eu/'),
					token: $apiToken,
					projectSlug: (string) $projectChoice['slug'],
				);
				$remoteKeyCount = $client->countTranslationKeys();
			} catch (\Throwable) {
				$remoteKeyCount = 0;
			}
		}

		$this->components->info('Key discovery summary');
		$this->components->twoColumnDetail('Local keys discovered', (string) $localKeyCount);
		$this->components->twoColumnDetail('Remote keys', (string) $remoteKeyCount);
		$this->newLine();
	}

	private function reportResults(array $results): int
	{
		$this->components->info('Setup summary');

		// Config persistence
		if ($results['config_persisted']) {
			$this->components->twoColumnDetail('Configuration', 'Saved');
		} else {
			$this->components->twoColumnDetail('Configuration', 'Incomplete');
			$this->warn('Configuration may not have been saved completely.');
		}

		// Project info
		if ($results['project_created']) {
			$this->components->twoColumnDetail('Project', "{$results['project_slug']} (created)");
		} elseif ($results['project_slug'] !== null) {
			$this->components->twoColumnDetail('Project', "{$results['project_slug']} (existing)");
		}

		$this->newLine();

		// Sync results
		if ($results['sync_result'] !== null) {
			$syncResult = $results['sync_result'];

			$this->components->info('Sync results');
			$this->components->twoColumnDetail('Summary', $syncResult->getSummaryMessage());

			if (! empty($syncResult->languageResults)) {
				$this->newLine();
				$items = [];

				foreach ($syncResult->languageResults as $language => $result) {
					$items[] = [
						'language' => $language,
						'status' => $result['success'] ? 'Success' : 'Failed',
						'message' => $result['message'] ?? '',
					];
				}

				$hasMessages = collect($items)->contains(fn (array $item): bool => $item['message'] !== '');

				$headers = $hasMessages
					? ['Language', 'Status', 'Message']
					: ['Language', 'Status'];

				$rows = array_map(
					fn (array $item): array => $hasMessages
						? [$item['language'], $item['status'], $item['message'] !== '' ? $item['message'] : '-']
						: [$item['language'], $item['status']],
					$items
				);

				table(headers: $headers, rows: $rows);
			}

			if ($syncResult->hasPartialSuccess()) {
				$this->newLine();
				$this->warn('Note: Some languages had issues but others succeeded.');
			}
		}

		// Auto-translate
		if ($results['auto_translate']) {
			$this->components->twoColumnDetail('Auto-translation', 'Dispatched');
		}

		// Errors
		if (! empty($results['errors'])) {
			$this->newLine();
			$this->components->error('Errors encountered');

			foreach ($results['errors'] as $error) {
				$this->line("  - {$error}");
			}

			return SymfonyCommand::FAILURE;
		}

		$this->newLine();
		$this->components->info('Setup completed');

		return SymfonyCommand::SUCCESS;
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
