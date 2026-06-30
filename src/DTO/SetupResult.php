<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\DTO;

final class SetupResult
{
	/**
	 * @param  array<string>  $errors
	 */
	public function __construct(
		public readonly bool $configPersisted = false,
		public readonly bool $projectCreated = false,
		public readonly ?string $projectSlug = null,
		public readonly ?SyncResult $syncResult = null,
		public readonly bool $autoTranslate = false,
		public readonly array $errors = [],
	) {}

	public function hasErrors(): bool
	{
		return $this->errors !== [];
	}
}
