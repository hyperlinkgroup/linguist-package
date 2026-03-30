<?php

namespace Hyperlinkgroup\Linguist;

use Hyperlinkgroup\Linguist\Exceptions\ConfigBrokenException;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class Linguist
{
	private string $temporaryDirectory;

	private string $project;

	private string $token;

	protected Collection $languages;

	public function __construct()
	{
		$this->languages = collect();
		$this->temporaryDirectory = config('linguist.temporary_directory') ?? 'tmp/translations';
		$this->project = config('linguist.project');
		$this->token = config('linguist.token');
	}

	/**
	 * @throws ConfigBrokenException
	 * @throws NoLanguageActivatedException
	 */
	public function handle(): void
	{
		// check if the project and the token are set
		if ($this->project === '') {
			throw new ConfigBrokenException('The linguist project is not available');
		}

		if ($this->token === '') {
			throw new ConfigBrokenException('The linguist token is not available');
		}

		// get all languages activated in the project
		$this->getAllLanguages();

		// ensure directories exist
		$this->ensureDirectoriesExist();

		// download files
		$this->downloadFiles();

		// move files
		$this->moveFiles();
	}

	protected function getBaseUrl(): string
	{
		$configuredUrl = (string) (config('linguist.url') ?? 'https://api.linguist.eu/v2');
		$baseUrl = $this->normalizeBaseUrl($configuredUrl);

		return $baseUrl . "/projects/$this->project";
	}

	private function normalizeBaseUrl(string $baseUrl): string
	{
		$normalizedBaseUrl = rtrim($baseUrl, '/');

		if (preg_match('#/v\d+$#', $normalizedBaseUrl) === 1) {
			return $normalizedBaseUrl;
		}

		return $normalizedBaseUrl . '/v2';
	}

	protected function getHttp(): PendingRequest
	{
		return Http::baseUrl($this->getBaseUrl())
			->acceptJson()
			->withToken($this->token);
	}

	/**
	 * @throws NoLanguageActivatedException
	 */
	protected function getAllLanguages(): void
	{
		$response = $this->getHttp()
			->get('languages');

		$this->languages = collect($response->json('data'));

		if ($this->languages->isEmpty()) {
			throw new NoLanguageActivatedException;
		}
	}

	/**
	 * @throws NoLanguageActivatedException
	 */
	public function downloadLanguages(): self
	{
		$this->getAllLanguages();

		return $this;
	}

	/**
	 * @throws NoLanguageActivatedException
	 */
	public function getLanguages(): Collection
	{
		if ($this->languages->isNotEmpty()) {
			return $this->languages;
		}

		$this->getAllLanguages();

		return $this->languages ?? collect();
	}

	public function setLanguages(Collection $languages): self
	{
		$this->languages = $languages;

		return $this;
	}

	protected function ensureDirectoriesExist(): void
	{
		File::ensureDirectoryExists(lang_path());
		File::ensureDirectoryExists(storage_path($this->temporaryDirectory));
	}

	public function createDirectories(): self
	{
		$this->ensureDirectoriesExist();

		return $this;
	}

	/**
	 * Downloads the files from the linguist server
	 */
	public function downloadFiles(): self
	{
		$routes = $this->fetchExportRoutes();

		$this->downloadTranslationFiles($routes);

		return $this;
	}

	/**
	 * Fetch export URLs for all languages concurrently.
	 *
	 * @return array<string, string>
	 */
	protected function fetchExportRoutes(): array
	{
		$baseUrl = $this->getBaseUrl();

		$responses = Http::pool(function ($pool) use ($baseUrl) {
			$this->languages->each(function ($language) use ($pool, $baseUrl) {
				$upperCaseLanguage = strtoupper($language);
				$pool->as($language)
					->acceptJson()
					->withToken($this->token)
					->get("$baseUrl/export/json/$upperCaseLanguage?prefix=:");
			});
		});

		$routes = [];

		foreach ($responses as $language => $response) {
			if ($response->successful()) {
				$routes[$language] = $response->json('url');
			}
		}

		return $routes;
	}

	/**
	 * Download translation files from the provided routes concurrently.
	 *
	 * @param  array<string, string>  $routes
	 */
	protected function downloadTranslationFiles(array $routes): void
	{
		if ($routes === []) {
			return;
		}

		$responses = Http::pool(function ($pool) use ($routes) {
			foreach ($routes as $language => $url) {
				$pool->as($language)
					->withToken($this->token)
					->get($url);
			}
		});

		foreach ($responses as $language => $response) {
			if ($response->successful()) {
				File::put(storage_path($this->temporaryDirectory . "/$language.json"), $response->body());
			}
		}
	}

	/**
	 * Moves the files from the temporary directory to the lang directory
	 */
	public function moveFiles(): self
	{
		$files = File::files(storage_path($this->temporaryDirectory));

		foreach ($files as $file) {
			$language = $file->getFilenameWithoutExtension();
			$destination = lang_path(strtolower($language) . '.json');

			File::move($file, $destination);

			$legacyPath = lang_path(strtoupper($language) . "/{$this->project}.json");
			if (File::exists($legacyPath)) {
				File::delete($legacyPath);
			}
		}

		File::deleteDirectory(storage_path($this->temporaryDirectory));

		return $this;
	}
}
