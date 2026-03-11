<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Actions;

use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;

final class PersistLinguistConfig
{
	use AsAction;

	/**
	 * Persist configuration to .env file and runtime config.
	 *
	 * @return array<string, bool>
	 */
	public function handle(array $config): array
	{
		$results = [];

		foreach ($config as $key => $value) {
			$envKey = $this->getEnvKey($key);

			if ($envKey === null) {
				continue;
			}

			$success = $this->updateEnvValue($envKey, $value);
			$results[$key] = $success;

			if ($success) {
				config(["linguist.{$key}" => $value]);
			}
		}

		return $results;
	}

	/**
	 * Map config key to env key.
	 */
	private function getEnvKey(string $configKey): ?string
	{
		$mapping = [
			'url' => 'LINGUIST_URL',
			'project' => 'LINGUIST_PROJECT',
			'token' => 'LINGUIST_TOKEN',
		];

		return $mapping[$configKey] ?? null;
	}

	/**
	 * Update or add a value in the .env file.
	 */
	private function updateEnvValue(string $key, string $value): bool
	{
		$envPath = base_path('.env');

		if (! File::exists($envPath)) {
			$examplePath = base_path('.env.example');
			if (File::exists($examplePath)) {
				File::copy($examplePath, $envPath);
			} else {
				File::put($envPath, '');
			}
		}

		$content = File::get($envPath);
		$escapedValue = $this->escapeEnvValue($value);
		$pattern = '/^' . preg_quote($key, '/') . '=.*/m';

		if (preg_match($pattern, $content)) {
			$content = preg_replace($pattern, "{$key}={$escapedValue}", $content);
		} else {
			$content .= "\n{$key}={$escapedValue}\n";
		}

		return File::put($envPath, $content) !== false;
	}

	/**
	 * Escape special characters for .env file.
	 */
	private function escapeEnvValue(string $value): string
	{
		if (strpbrk($value, ' #"\'') !== false) {
			$value = str_replace('"', '\\"', $value);

			return '"' . $value . '"';
		}

		return $value;
	}

	/**
	 * Check if required config values are set.
	 */
	public static function hasValidConfig(): bool
	{
		return config('linguist.project') !== ''
			&& config('linguist.token') !== '';
	}

	/**
	 * Get current config summary for display.
	 */
	public static function getConfigSummary(): array
	{
		return [
			'url' => config('linguist.url'),
			'project' => config('linguist.project'),
			'token' => self::maskToken(config('linguist.token')),
		];
	}

	/**
	 * Mask the token for display.
	 */
	private static function maskToken(string $token): string
	{
		if ($token === '') {
			return '(not set)';
		}

		if (strlen($token) <= 8) {
			return '****';
		}

		return substr($token, 0, 4) . '...' . substr($token, -4);
	}
}
