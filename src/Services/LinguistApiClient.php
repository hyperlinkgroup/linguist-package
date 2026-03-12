<?php

declare(strict_types=1);

namespace Hyperlinkgroup\Linguist\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class LinguistApiClient
{
	private string $baseUrl;

	private string $token;

	private string $projectSlug;

	public function __construct(string $baseUrl, string $token, string $projectSlug = '')
	{
		$this->baseUrl = rtrim($baseUrl, '/');
		$this->token = $token;
		$this->projectSlug = $projectSlug;
	}

	public function setProjectSlug(string $projectSlug): void
	{
		$this->projectSlug = $projectSlug;
	}

	private function getHttp(): PendingRequest
	{
		return Http::baseUrl($this->baseUrl)
			->acceptJson()
			->withToken($this->token);
	}

	private function projectUrl(string $path = ''): string
	{
		if ($this->projectSlug === '') {
			throw new \RuntimeException('Project slug not set');
		}

		$url = "{$this->baseUrl}/projects/{$this->projectSlug}";

		if ($path !== '') {
			$url .= '/' . ltrim($path, '/');
		}

		return $url;
	}

	/**
	 * List all projects accessible to the authenticated user.
	 */
	public function listProjects(array $params = []): Response
	{
		$query = http_build_query($params);

		return $this->getHttp()
			->get("{$this->baseUrl}/projects" . ($query ? '?' . $query : ''));
	}

	/**
	 * Create a new project.
	 */
	public function createProject(array $data): Response
	{
		return $this->getHttp()
			->post("{$this->baseUrl}/projects", $data);
	}

	/**
	 * Update an existing project.
	 */
	public function updateProject(array $data): Response
	{
		return $this->getHttp()
			->patch($this->projectUrl(), $data);
	}

	/**
	 * Get available languages for the project.
	 */
	public function getLanguages(): Response
	{
		return $this->getHttp()
			->get($this->projectUrl('languages'));
	}

	/**
	 * Get export URL for a specific language.
	 */
	public function getExportUrl(string $language): Response
	{
		return $this->getHttp()
			->get($this->projectUrl('export/json/' . strtoupper($language)) . '?prefix=:');
	}

	/**
	 * Download the actual export file.
	 */
	public function downloadExport(string $url): Response
	{
		return Http::withToken($this->token)
			->get($url);
	}

	/**
	 * List translation keys for the project.
	 */
	public function listTranslationKeys(array $params = []): Response
	{
		$query = http_build_query($params);

		return $this->getHttp()
			->get($this->projectUrl('translation-keys') . ($query ? '?' . $query : ''));
	}

	/**
	 * Create or update a translation key.
	 */
	public function storeTranslationKey(string $key, array $translations = []): Response
	{
		return $this->getHttp()
			->post($this->projectUrl('translation-keys'), [
				'key' => $key,
				'translations' => $translations,
			]);
	}

	/**
	 * Create or update multiple translation keys.
	 *
	 * @param  array<int, array{key: string, translations?: array<int|string, string>}>  $keys
	 */
	public function storeTranslationKeys(array $keys): Response
	{
		return $this->getHttp()
			->post($this->projectUrl('translation-keys'), [
				'keys' => $keys,
			]);
	}

	/**
	 * Update an existing translation key.
	 */
	public function updateTranslationKey(int $keyId, string $key, array $translations = []): Response
	{
		return $this->getHttp()
			->patch($this->projectUrl("translation-keys/{$keyId}"), [
				'key' => $key,
				'translations' => $translations,
			]);
	}

	/**
	 * Delete a translation key.
	 */
	public function deleteTranslationKey(int $keyId): Response
	{
		return $this->getHttp()
			->delete($this->projectUrl("translation-keys/{$keyId}"));
	}

	/**
	 * Trigger auto-translation.
	 */
	public function triggerAutoTranslate(array $options = []): Response
	{
		return $this->getHttp()
			->post($this->projectUrl('translate'), $options);
	}

	/**
	 * Count all remote translation keys for the current project.
	 *
	 * @throws \RuntimeException
	 */
	public function countTranslationKeys(): int
	{
		$response = $this->listTranslationKeys(['per_page' => 1, 'page' => 1]);

		if ($response->successful()) {
			foreach ([
				'meta.total',
				'pagination.total',
				'meta.pagination.total',
				'meta.total_count',
				'total',
			] as $path) {
				$total = $response->json($path);
				if (is_numeric($total)) {
					return (int) $total;
				}
			}

			foreach (['x-total-count', 'x-pagination-total', 'x-pagination-total-count'] as $header) {
				$total = $response->header($header);
				if (is_numeric($total)) {
					return (int) $total;
				}
			}

			foreach (['data', 'translation_keys', 'items'] as $path) {
				$list = $response->json($path, null);
				if (is_array($list)) {
					return count($list);
				}
			}
		}

		$fallbackCount = $this->countTranslationKeysFromExports();

		if ($fallbackCount !== null) {
			return $fallbackCount;
		}

		throw new \RuntimeException('Failed to fetch translation keys count.');
	}

	private function countTranslationKeysFromExports(): ?int
	{
		$languagesResponse = $this->getLanguages();

		if (! $languagesResponse->successful()) {
			return null;
		}

		$languages = $languagesResponse->json('data', []);

		if (! is_array($languages) || $languages === []) {
			return 0;
		}

		foreach ($languages as $language) {
			$languageCode = strtoupper((string) $language);
			if ($languageCode === '') {
				continue;
			}

			$exportRouteResponse = $this->getExportUrl($languageCode);
			if (! $exportRouteResponse->successful()) {
				continue;
			}

			$exportUrl = (string) $exportRouteResponse->json('url', '');
			if ($exportUrl === '') {
				continue;
			}

			$exportResponse = $this->downloadExport($exportUrl);
			if (! $exportResponse->successful()) {
				continue;
			}

			$decoded = json_decode($exportResponse->body(), true);
			if (! is_array($decoded)) {
				continue;
			}

			return $this->countTranslationLeafs($decoded);
		}

		return null;
	}

	private function countTranslationLeafs(array $translations): int
	{
		$count = 0;

		array_walk_recursive($translations, static function () use (&$count): void {
			$count++;
		});

		return $count;
	}

	/**
	 * Resolve project language codes to language IDs.
	 *
	 * @return array<string, int>
	 */
	public function getProjectLanguageIdMap(): array
	{
		$map = [];
		$projectsResponse = $this->listProjects(['per_page' => 100]);

		if ($projectsResponse->successful()) {
			$project = collect($projectsResponse->json('data', []))
				->first(fn (array $candidate): bool => ($candidate['slug'] ?? null) === $this->projectSlug);

			if (is_array($project)) {
				$languages = $project['languages'] ?? [];

				if (is_array($languages)) {
					foreach ($languages as $language) {
						$code = strtoupper((string) ($language['code'] ?? ''));
						$id = $language['id'] ?? null;

						if ($code !== '' && is_numeric($id)) {
							$map[$code] = (int) $id;
						}
					}
				}

				$sourceLanguage = $project['language'] ?? null;

				if (is_array($sourceLanguage)) {
					$sourceCode = strtoupper((string) ($sourceLanguage['code'] ?? ''));
					$sourceId = $sourceLanguage['id'] ?? ($project['language_id'] ?? null);

					if ($sourceCode !== '' && is_numeric($sourceId)) {
						$map[$sourceCode] = (int) $sourceId;
					}
				}
			}
		}

		if ($map !== []) {
			return $map;
		}

		$keysResponse = $this->listTranslationKeys(['per_page' => 100, 'page' => 1]);

		if (! $keysResponse->successful()) {
			return [];
		}

		$keyData = $keysResponse->json('data', []);

		if (! is_array($keyData)) {
			return [];
		}

		foreach ($keyData as $translationKey) {
			$translations = $translationKey['translations'] ?? [];

			if (! is_array($translations)) {
				continue;
			}

			foreach ($translations as $translation) {
				$code = strtoupper((string) ($translation['language']['code'] ?? ''));
				$id = $translation['language_id'] ?? null;

				if ($code !== '' && is_numeric($id)) {
					$map[$code] = (int) $id;
				}
			}
		}

		return $map;
	}
}
