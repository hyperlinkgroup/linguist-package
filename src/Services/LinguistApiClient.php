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
	public function getExportUrl(string $language, array $query = ['prefix' => ':']): Response
	{
		return $this->getHttp()
			->get($this->projectUrl('export/json/' . strtoupper($language)), $query);
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

		foreach ($this->extractLanguageCodes($languages) as $languageCode) {
			$count = $this->countLanguageExportLeafsWithPrefixFallback($languageCode);

			if ($count !== null) {
				return $count;
			}
		}

		return null;
	}

	private function countLanguageExportLeafsWithPrefixFallback(string $languageCode): ?int
	{
		$preferredExport = $this->downloadLanguageExport($languageCode, ['prefix' => ':']);
		$preferredCount = $preferredExport['success'] ? $preferredExport['key_count'] : 0;

		if ($preferredCount > 0) {
			return $preferredCount;
		}

		$fallbackExport = $this->downloadLanguageExport($languageCode, []);
		if (! $fallbackExport['success']) {
			if (! $preferredExport['success']) {
				return null;
			}

			return $preferredCount;
		}

		$fallbackCount = $fallbackExport['key_count'];

		return max($preferredCount, $fallbackCount);
	}

	/**
	 * @return array<string>
	 */
	public function extractLanguageCodes(mixed $languagesPayload): array
	{
		if (! is_array($languagesPayload)) {
			return [];
		}

		return collect($languagesPayload)
			->map(static function (mixed $language): string {
				if (is_string($language)) {
					return strtoupper($language);
				}

				if (is_array($language)) {
					$code = $language['code'] ?? $language['locale'] ?? '';

					return strtoupper((string) $code);
				}

				return '';
			})
			->filter(static fn (string $code): bool => $code !== '')
			->unique()
			->values()
			->all();
	}

	/**
	 * Download translation payload for a language.
	 *
	 * Prefer exports with `prefix=:` (key formatting hint used by Linguist), then retry
	 * without a prefix when the prefixed variant returns no keys.
	 *
	 * @return array{success: bool, key_count: int, body?: string, message?: string}
	 */
	public function downloadLanguageExportWithPrefixFallback(string $languageCode): array
	{
		$attemptQueries = [
			['prefix' => ':'],
			[],
		];

		$hasSuccessfulDownload = false;
		$failureMessages = [];

		foreach ($attemptQueries as $query) {
			$exportDownload = $this->downloadLanguageExport($languageCode, $query);

			if ($exportDownload['success']) {
				$hasSuccessfulDownload = true;

				if ($exportDownload['key_count'] > 0) {
					return $exportDownload;
				}

				continue;
			}

			$failureMessage = $exportDownload['message'] ?? null;

			if (is_string($failureMessage) && $failureMessage !== '') {
				$failureMessages[] = $failureMessage;
			}
		}

		if ($hasSuccessfulDownload) {
			return [
				'success' => false,
				'key_count' => 0,
				'message' => 'Downloaded export payload is empty.',
			];
		}

		$uniqueFailureMessages = array_values(array_unique($failureMessages));

		return [
			'success' => false,
			'key_count' => 0,
			'message' => $uniqueFailureMessages !== [] ? implode('; ', $uniqueFailureMessages) : 'Failed to download export file.',
		];
	}

	/**
	 * @param  array<string, string>  $query
	 * @return array{success: bool, key_count: int, body?: string, message?: string}
	 */
	private function downloadLanguageExport(string $languageCode, array $query): array
	{
		$exportRouteResponse = $this->getExportUrl($languageCode, $query);

		if (! $exportRouteResponse->successful()) {
			return [
				'success' => false,
				'key_count' => 0,
				'message' => 'Failed to fetch export URL for this language.',
			];
		}

		$exportUrl = (string) $exportRouteResponse->json('url', '');
		if ($exportUrl === '') {
			return [
				'success' => false,
				'key_count' => 0,
				'message' => 'Export URL is missing from response.',
			];
		}

		$exportResponse = $this->downloadExport($exportUrl);
		if (! $exportResponse->successful()) {
			return [
				'success' => false,
				'key_count' => 0,
				'message' => 'Failed to download export file.',
			];
		}

		$body = $exportResponse->body();
		$decoded = json_decode($body, true);
		$keyCount = is_array($decoded) ? $this->countTranslationLeafs($decoded) : 0;

		return [
			'success' => true,
			'key_count' => $keyCount,
			'body' => $body,
		];
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
	 * Fetch a single project (full detail; list responses may omit nested languages).
	 */
	public function getProject(): Response
	{
		return $this->getHttp()
			->get($this->projectUrl());
	}

	/**
	 * Build code => id map from project JSON (list or show payload).
	 *
	 * @param  array<string, mixed>  $project
	 * @return array<string, int>
	 */
	private function languageIdMapFromProjectPayload(array $project): array
	{
		$map = [];

		foreach (['languages', 'available_languages', 'all_languages'] as $key) {
			$languages = $project[$key] ?? null;
			if (! is_array($languages)) {
				continue;
			}

			foreach ($languages as $language) {
				if (! is_array($language)) {
					continue;
				}

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

		return $map;
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
				$map = $this->languageIdMapFromProjectPayload($project);
			}
		}

		if ($map !== []) {
			return $map;
		}

		$projectResponse = $this->getProject();

		if ($projectResponse->successful()) {
			$project = $projectResponse->json('data');

			if (! is_array($project)) {
				$project = $projectResponse->json();
			}

			if (is_array($project)) {
				$map = $this->languageIdMapFromProjectPayload($project);
			}
		}

		if ($map !== []) {
			return $map;
		}

		$languagesResponse = $this->getLanguages();

		if ($languagesResponse->successful()) {
			foreach ($languagesResponse->json('data', []) as $item) {
				if (! is_array($item)) {
					continue;
				}

				$code = strtoupper((string) ($item['code'] ?? ''));
				$id = $item['id'] ?? null;

				if ($code !== '' && is_numeric($id)) {
					$map[$code] = (int) $id;
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
