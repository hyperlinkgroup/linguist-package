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
use function Laravel\Prompts\multiselect;
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

		if (! $isTokenValid) {
			$this->components->error('Invalid API token. Please check your token and try again.');

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
		$syncSource = $syncMode === 'sync' ? $this->getSyncSource() : 'remote';

		// Step 5: Mode-specific options
		$pruneRemoteKeys = in_array($syncMode, ['sync', 'push'], true);
		$activateMissingLanguages = true;
		$triggerAutoTranslate = false;

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
			newProjectTeamId: $projectChoice['type'] === 'new' ? ($projectChoice['team_id'] ?? null) : null,
			syncMode: $syncMode,
			syncSource: $syncSource,
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
		$apiTokenSettingsUrl = (string) config('linguist.api_tokens_url');
		$apiTokenSettingsLink = "Get your API token at {$apiTokenSettingsUrl}";

		$token = password(
			label: 'Enter your Linguist API token',
			hint: $apiTokenSettingsLink,
			required: true
		);

		if (trim($token) === '') {
			$this->error('API token is required.');

			return null;
		}

		return $token;
	}

	private function getProjectChoice(string $apiToken): ?array
	{
		$projects = $this->orchestrator->listAvailableProjects($apiToken);

		if ($projects->isNotEmpty()) {
			$this->components->info('Your existing projects (all teams you can access):');

			$choices = $projects->mapWithKeys(function (array $project): array {
				$slug = (string) ($project['slug'] ?? '');
				$name = (string) ($project['name'] ?? $slug);
				$label = $name;
				$teamName = $project['team_name'] ?? null;

				if (is_string($teamName) && $teamName !== '') {
					$label .= " — {$teamName}";
				}

				return [$slug => $label];
			})->toArray();

			$choices['__new__'] = 'Create a new project';

			$selectedSlug = select(
				label: 'Select a project or create a new one',
				options: $choices
			);

			if ($selectedSlug === '__new__') {
				$teamId = $this->selectTeamForNewProject($apiToken);
				if ($teamId === null) {
					return null;
				}

				return $this->createNewProjectChoice($apiToken, $teamId);
			}

			if ($selectedSlug === '') {
				$this->error('Unable to resolve selected project.');

				return null;
			}

			return ['type' => 'existing', 'slug' => $selectedSlug];
		}

		$this->components->info('No existing projects found. Creating a new project.');
		$this->newLine();

		$teamId = $this->selectTeamForNewProject($apiToken);
		if ($teamId === null) {
			return null;
		}

		return $this->createNewProjectChoice($apiToken, $teamId);
	}

	/**
	 * Choose which team should own a newly created project.
	 */
	private function selectTeamForNewProject(string $apiToken): ?int
	{
		$client = new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
			token: $apiToken,
			projectSlug: '',
		);

		$response = $client->listTeams();

		if (! $response->successful()) {
			$this->error('Failed to load teams from the Linguist API. Check your token and API URL.');

			return null;
		}

		$rawTeams = collect($response->json('data', []));

		if ($rawTeams->isEmpty()) {
			$this->error('No teams are available for this account. You must belong to at least one team.');

			return null;
		}

		$teams = $rawTeams->filter(function (mixed $team): bool {
			if (! is_array($team)) {
				return false;
			}

			if (! array_key_exists('is_owner', $team)) {
				return true;
			}

			return (bool) $team['is_owner'];
		});

		if ($teams->isEmpty()) {
			$this->error('Only team owners can create projects. Ask your team owner to create one, or sign in with the team owner account.');

			return null;
		}

		if ($teams->count() === 1) {
			$only = $teams->first();
			$name = is_array($only) ? (string) ($only['name'] ?? 'Team') : 'Team';
			$this->components->info("New project will be created in team: {$name}");
			$this->newLine();

			$id = is_array($only) ? (int) ($only['id'] ?? 0) : 0;

			return $id > 0 ? $id : null;
		}

		$options = $teams
			->filter(fn (mixed $team): bool => is_array($team) && isset($team['id']))
			->mapWithKeys(function (array $team): array {
				$id = (string) $team['id'];
				$name = (string) ($team['name'] ?? $id);

				return [$id => $name];
			})
			->all();

		if ($options === []) {
			$this->error('The teams response could not be read. Try again or update the connector package.');

			return null;
		}

		$selected = select(
			label: 'Which team should own the new project?',
			options: $options,
		);

		$teamId = (int) $selected;

		return $teamId > 0 ? $teamId : null;
	}

	private function createNewProjectChoice(string $apiToken, int $teamId): ?array
	{
		$name = text(
			label: 'Enter a name for the new project',
			required: true
		);

		if (trim($name) === '') {
			$this->error('Project name is required.');

			return null;
		}

		$localLanguages = CollectLocalTranslations::detectLanguages();
		sort($localLanguages);

		if ($localLanguages === []) {
			$this->error('No JSON translation languages were found under your lang path. Add language files before creating a project.');

			return null;
		}

		$catalogClient = new LinguistApiClient(
			baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
			token: $apiToken,
			projectSlug: '',
		);
		$languageIdMap = $catalogClient->fetchSupportedLanguageIdMap();

		if ($languageIdMap === []) {
			$this->error('Could not load languages from the Linguist API.');

			return null;
		}

		$translations = CollectLocalTranslations::run();
		$sourceOptions = [];

		foreach ($localLanguages as $code) {
			if ($this->resolveLanguageIdFromCatalog($languageIdMap, $code) === null) {
				continue;
			}

			$keyCount = count($translations[$code] ?? []);
			$sourceOptions[$code] = "{$code} ({$keyCount} keys)";
		}

		if ($sourceOptions === []) {
			$this->error('No local languages match available Linguist languages.');

			return null;
		}

		$sourceLocaleKey = select(
			label: 'Select the source language (from your local translation files)',
			options: $sourceOptions,
			default: array_key_first($sourceOptions),
		);

		$sourceLanguageId = $this->resolveLanguageIdFromCatalog($languageIdMap, $sourceLocaleKey);

		if ($sourceLanguageId === null) {
			$this->error('Could not resolve the selected source language.');

			return null;
		}

		$targetOptions = [];

		foreach ($localLanguages as $code) {
			if (strtoupper($code) === strtoupper($sourceLocaleKey)) {
				continue;
			}

			if ($this->resolveLanguageIdFromCatalog($languageIdMap, $code) === null) {
				continue;
			}

			$keyCount = count($translations[$code] ?? []);
			$targetOptions[$code] = "{$code} ({$keyCount} keys)";
		}

		if ($targetOptions === []) {
			$this->error('No target languages are available.');

			return null;
		}

		$targetCodes = multiselect(
			label: 'Select target languages (from your local translation files)',
			options: $targetOptions,
			default: array_keys($targetOptions),
			required: 'Select at least one target language.',
			hint: 'Space to toggle. The source language is not shown.'
		);

		$targetLanguages = [];

		foreach ($targetCodes as $code) {
			$id = $this->resolveLanguageIdFromCatalog($languageIdMap, (string) $code);

			if ($id !== null && $id !== $sourceLanguageId) {
				$targetLanguages[] = $id;
			}
		}

		$targetLanguages = array_values(array_unique($targetLanguages));

		if ($targetLanguages === []) {
			$this->error('At least one target language is required.');

			return null;
		}

		return [
			'type' => 'new',
			'name' => $name,
			'team_id' => $teamId,
			'source_language_id' => $sourceLanguageId,
			'target_language_ids' => $targetLanguages,
		];
	}

	/**
	 * @param  array<string, int>  $map
	 */
	private function resolveLanguageIdFromCatalog(array $map, string $code): ?int
	{
		$upper = strtoupper($code);

		if (isset($map[$upper])) {
			return $map[$upper];
		}

		$parts = preg_split('/[-_]/', $upper);
		$base = is_array($parts) && $parts !== [] ? $parts[0] : '';

		if ($base !== '' && $base !== $upper && isset($map[$base])) {
			return $map[$base];
		}

		return null;
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

	private function getSyncSource(): string
	{
		return select(
			label: 'Please choose merge strategy for existing keys',
			options: [
				'remote' => 'Remote - Prefer Linguist values',
				'local' => 'Local - Prefer local values',
			],
			default: 'remote'
		);
	}

	private function renderKeyDiscoverySummary(string $apiToken, array $projectChoice): void
	{
		$localTranslations = CollectLocalTranslations::run();
		$localKeyCount = collect($localTranslations)
			->flatMap(fn (array $translations): array => array_keys($translations))
			->unique()
			->count();

		$remoteKeyCount = null;

		if (($projectChoice['type'] ?? '') === 'existing' && isset($projectChoice['slug'])) {
			try {
				$client = new LinguistApiClient(
					baseUrl: config('linguist.url', 'https://api.linguist.eu/v2'),
					token: $apiToken,
					projectSlug: (string) $projectChoice['slug'],
				);
				$remoteKeyCount = $client->countTranslationKeys();
			} catch (\Throwable) {
				$remoteKeyCount = null;
			}
		}

		$this->components->info('Current translation status:');
		$this->components->twoColumnDetail('Local keys discovered', (string) $localKeyCount);
		$this->components->twoColumnDetail('Remote keys', $remoteKeyCount === null ? 'N/A' : (string) $remoteKeyCount);
		$this->components->twoColumnDetail(
			'Local JSON search roots',
			$this->formatTranslationSearchRoots()
		);
		$this->newLine();
	}

	private function formatTranslationSearchRoots(): string
	{
		$roots = CollectLocalTranslations::translationFileSearchRoots();

		if ($roots !== []) {
			return implode(', ', $roots);
		}

		return 'none (' . implode('; ', CollectLocalTranslations::translationFileSearchRootIssues()) . ')';
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
}
