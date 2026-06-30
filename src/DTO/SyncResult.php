<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\DTO;

final class SyncResult
{
	/**
	 * @param  array<string, array{success: bool, message?: string}>  $languageResults
	 * @param  array<string>  $errors
	 */
	public function __construct(
		public readonly bool $overallSuccess,
		public readonly array $languageResults = [],
		public readonly array $errors = [],
		public readonly int $keysProcessed = 0,
		public readonly int $keysFailed = 0,
	) {}

	public function hasPartialSuccess(): bool
	{
		if (empty($this->languageResults)) {
			return false;
		}

		$successCount = count(array_filter($this->languageResults, fn (array $languageResult) => $languageResult['success']));

		return $successCount > 0 && $successCount < count($this->languageResults);
	}

	public function getFailedLanguages(): array
	{
		return array_keys(array_filter($this->languageResults, fn (array $languageResult) => ! $languageResult['success']));
	}

	public function getSuccessfulLanguages(): array
	{
		return array_keys(array_filter($this->languageResults, fn (array $languageResult) => $languageResult['success']));
	}

	public function getSummaryMessage(): string
	{
		if ($this->overallSuccess && ! $this->hasPartialSuccess()) {
			return "All languages synced successfully. ({$this->keysProcessed} keys processed)";
		}

		if ($this->hasPartialSuccess()) {
			$successful = count($this->getSuccessfulLanguages());
			$failed = count($this->getFailedLanguages());

			return "Partial sync completed. {$successful} languages successful, {$failed} languages failed. ({$this->keysProcessed} keys processed, {$this->keysFailed} failed)";
		}

		$firstError = $this->errors[0] ?? 'Unknown error';

		return "Sync failed: {$firstError}";
	}
}
