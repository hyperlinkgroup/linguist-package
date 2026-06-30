<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\DTO;

final class SetupInput
{
	public function __construct(
		public readonly string $apiToken,
		public readonly ?string $projectSlug = null,
		public readonly ?string $newProjectName = null,
		public readonly ?int $newProjectTeamId = null,
		public readonly string $syncMode = 'sync',
		public readonly string $syncSource = 'remote',
		public readonly bool $pruneRemoteKeys = false,
		public readonly bool $activateMissingLanguages = true,
		public readonly bool $triggerAutoTranslate = false,
		public readonly ?int $sourceLanguageId = null,
		public readonly array $targetLanguageIds = [],
	) {}

	public function isNewProject(): bool
	{
		return $this->newProjectName !== null && $this->newProjectName !== '';
	}

	public function isExistingProject(): bool
	{
		return $this->projectSlug !== null && $this->projectSlug !== '';
	}

	public function getProjectIdentifier(): string
	{
		return $this->isNewProject()
			? $this->newProjectName
			: ($this->projectSlug ?? '');
	}

	public static function fromArray(array $data): self
	{
		return new self(
			apiToken: $data['api_token'] ?? '',
			projectSlug: $data['project_slug'] ?? null,
			newProjectName: $data['new_project_name'] ?? null,
			newProjectTeamId: isset($data['new_project_team_id']) ? (int) $data['new_project_team_id'] : null,
			syncMode: $data['sync_mode'] ?? 'sync',
			syncSource: $data['sync_source'] ?? 'remote',
			pruneRemoteKeys: $data['prune_remote_keys'] ?? false,
			activateMissingLanguages: $data['activate_missing_languages'] ?? true,
			triggerAutoTranslate: $data['trigger_auto_translate'] ?? false,
			sourceLanguageId: $data['source_language_id'] ?? null,
			targetLanguageIds: $data['target_language_ids'] ?? [],
		);
	}

	public function toArray(): array
	{
		return [
			'api_token' => $this->apiToken,
			'project_slug' => $this->projectSlug,
			'new_project_name' => $this->newProjectName,
			'new_project_team_id' => $this->newProjectTeamId,
			'sync_mode' => $this->syncMode,
			'sync_source' => $this->syncSource,
			'prune_remote_keys' => $this->pruneRemoteKeys,
			'activate_missing_languages' => $this->activateMissingLanguages,
			'trigger_auto_translate' => $this->triggerAutoTranslate,
			'source_language_id' => $this->sourceLanguageId,
			'target_language_ids' => $this->targetLanguageIds,
		];
	}
}
