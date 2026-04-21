<?php

namespace Hyperlinkgroup\Linguist\Commands;

use Hyperlinkgroup\Linguist\Actions\CollectLocalTranslations;
use Hyperlinkgroup\Linguist\Actions\PersistLinguistConfig;
use Hyperlinkgroup\Linguist\Actions\PullTranslations;
use Hyperlinkgroup\Linguist\Actions\PushTranslations;
use Hyperlinkgroup\Linguist\Actions\SyncTranslations;
use Hyperlinkgroup\Linguist\DTO\SyncResult;
use Hyperlinkgroup\Linguist\Services\LinguistApiClient;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class LinguistCommand extends Command
{
	protected $signature = 'linguist:sync
		{--mode= : Sync mode: sync, pull, push}
		{--sync : Force sync mode}
		{--pull : Force pull mode}
		{--push : Force push mode}
		{--prune-remote-keys : Remove remote keys not present locally (sync mode)}
		{--no-activate-missing-languages : Do not activate missing local languages remotely (sync mode)}
		{--overwrite : Overwrite existing remote translations (push mode)}
		{--languages= : Comma-separated language codes for push mode (e.g. EN,DE)}';

	protected $description = 'Run translation synchronization with Linguist';

	public function handle(
		SyncTranslations $syncTranslations,
		PullTranslations $pullTranslations,
		PushTranslations $pushTranslations,
		LinguistApiClient $apiClient
	): int {
		$projectSlug = (string) config('linguist.project');

		if (! PersistLinguistConfig::hasValidConfig()) {
			$this->components->error('Linguist is not configured. Run `php artisan linguist:setup` first.');

			return SymfonyCommand::FAILURE;
		}

		if ($this->shouldShowModeChoiceSummary()) {
			$this->renderKeyDiscoverySummary($projectSlug, $apiClient);
		}

		$selectedMode = $this->resolveMode();

		if ($selectedMode === null) {
			return SymfonyCommand::FAILURE;
		}

		$this->components->info('Starting linguist sync...');

		try {
			$syncResult = match ($selectedMode) {
				'pull' => $pullTranslations->handle($projectSlug),
				'push' => $this->runPushWithProgress($pushTranslations, $projectSlug),
				'sync' => $this->runSyncWithProgress($syncTranslations, $projectSlug),
			};

			$this->newLine();
			$this->components->twoColumnDetail('Project', $projectSlug);
			$this->components->twoColumnDetail('Mode', strtoupper($selectedMode));
			$this->components->twoColumnDetail('Summary', $syncResult->getSummaryMessage());

			$this->newLine();
			$this->renderLanguageTable($syncResult);

			if (! empty($syncResult->errors)) {
				$this->newLine();
				$this->components->error('Errors encountered');
				foreach ($syncResult->errors as $error) {
					$this->line("  - {$error}");
				}

				return SymfonyCommand::FAILURE;
			}

			if ($syncResult->hasPartialSuccess()) {
				$this->newLine();
				$this->warn('Some languages failed while others succeeded.');
			}

			$this->newLine();
			$this->components->info('Sync completed');

			return $syncResult->overallSuccess ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
		} catch (\Throwable $e) {
			$this->components->error($e->getMessage());

			return SymfonyCommand::FAILURE;
		}
	}

	private function runPushWithProgress(PushTranslations $pushTranslations, string $projectSlug): SyncResult
	{
		$uploadProgress = null;

		return $pushTranslations->handle(
			projectSlug: $projectSlug,
			overwrite: (bool) $this->option('overwrite'),
			specificLanguages: $this->parseLanguagesOption(),
			onProgress: function (int $current, int $total, string $key) use (&$uploadProgress): void {
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
			}
		);
	}

	private function runSyncWithProgress(SyncTranslations $syncTranslations, string $projectSlug): SyncResult
	{
		$uploadProgress = null;

		$pruneRemoteKeys = $this->option('prune-remote-keys')
			? true
			: confirm('Remove keys from Linguist that are not present in local files?', false);

		return $syncTranslations->handle(
			projectSlug: $projectSlug,
			pruneRemoteKeys: $pruneRemoteKeys,
			activateMissingLanguages: ! (bool) $this->option('no-activate-missing-languages'),
			onPushProgress: function (int $current, int $total, string $key) use (&$uploadProgress): void {
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
			}
		);
	}

	private function shouldShowModeChoiceSummary(): bool
	{
		return $this->input->isInteractive()
			&& ! $this->option('sync')
			&& ! $this->option('pull')
			&& ! $this->option('push')
			&& ($this->option('mode') === null || $this->option('mode') === '');
	}

	private function renderKeyDiscoverySummary(string $projectSlug, LinguistApiClient $apiClient): void
	{
		$localTranslations = CollectLocalTranslations::run($projectSlug);
		$localKeyCount = collect($localTranslations)
			->flatMap(fn (array $translations): array => array_keys($translations))
			->unique()
			->count();

		$remoteKeyCount = null;

		try {
			$apiClient->setProjectSlug($projectSlug);
			$remoteKeyCount = $apiClient->countTranslationKeysFromExports();
		} catch (\Throwable) {
			$remoteKeyCount = null;
		}

		$this->components->info('Current translation status:');
		$this->components->twoColumnDetail('Local Translation Keys', (string) $localKeyCount);
		$this->components->twoColumnDetail('Linguist Project Translation Keys', $remoteKeyCount === null ? 'N/A' : (string) $remoteKeyCount);
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

	private function resolveMode(): ?string
	{
		$flagModes = array_filter([
			$this->option('sync') ? 'sync' : null,
			$this->option('pull') ? 'pull' : null,
			$this->option('push') ? 'push' : null,
		]);

		if (count($flagModes) > 1) {
			$this->components->error('Use only one of --sync, --pull, or --push.');

			return null;
		}

		$modeOption = $this->option('mode');
		if ($modeOption !== null && $modeOption !== '') {
			$mode = strtolower((string) $modeOption);
			if (! in_array($mode, ['sync', 'pull', 'push'], true)) {
				$this->components->error("Invalid mode '{$mode}'. Allowed: sync, pull, push.");

				return null;
			}

			if (count($flagModes) === 1 && $mode !== $flagModes[array_key_first($flagModes)]) {
				$this->components->error('Conflicting mode options: --mode does not match explicit mode flag.');

				return null;
			}

			return $mode;
		}

		if (count($flagModes) === 1) {
			return $flagModes[array_key_first($flagModes)];
		}

		if (! $this->input->isInteractive()) {
			return 'sync';
		}

		return (string) select(
			label: 'Select sync mode',
			options: [
				'pull' => 'Pull - Download translations from Linguist (overwrite local)',
				'sync' => 'Sync - Merge local and remote translations',
				'push' => 'Push - Upload local translations to Linguist',
			],
			default: 'sync'
		);
	}

	/**
	 * @return array<string>|null
	 */
	private function parseLanguagesOption(): ?array
	{
		$languagesOption = $this->option('languages');
		if (! is_string($languagesOption) || trim($languagesOption) === '') {
			return null;
		}

		$languages = array_values(array_filter(array_map(
			static fn (string $language): string => strtoupper(trim($language)),
			explode(',', $languagesOption)
		), static fn (string $language): bool => $language !== ''));

		return $languages === [] ? null : $languages;
	}

	private function renderLanguageTable(SyncResult $syncResult): void
	{
		if (empty($syncResult->languageResults)) {
			return;
		}

		$items = [];

		foreach ($syncResult->languageResults as $language => $result) {
			$items[] = [
				'language' => (string) $language,
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
}
