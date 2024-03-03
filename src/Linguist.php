<?php

namespace Hyperlinkgroup\Linguist;

use Hyperlinkgroup\Linguist\Exceptions\ConfigBrokenException;
use Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
		if (! $this->isProjectSet()) {
			throw new ConfigBrokenException('The linguist project is not available');
		}

		if (! $this->isTokenSet()) {
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

	private function getTemporaryDirectory(): string
	{
		if ($this->temporaryDirectory) {
			return $this->temporaryDirectory;
		}

		return config('linguist.temporary_directory') ?? 'tmp/translations';
	}

	private function getProject(): string
	{
		if ($this->project) {
			return $this->project;
		}

		return config('linguist.project');
	}

	private function getToken(): string
	{
		if ($this->token) {
			return $this->token;
		}

		return config('linguist.token');
	}

	protected function getHttp(): PendingRequest
	{
		$url = Str::of(config('linguist.url') ?? 'https://api.linguist.eu/');
		if ($url->endsWith('/')) {
			$url = $url->substr(0, -1);
		}

		$project = $this->getProject();

		return Http::baseUrl($url . "/projects/$project")
			->acceptJson()
			->withToken($this->getToken());
	}

	protected function isProjectSet(): bool
	{
		return $this->getProject() !== '';
	}

	protected function isTokenSet(): bool
	{
		return $this->getToken() !== '';
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
			throw new NoLanguageActivatedException();
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
		$paths = collect();

		$this->languages->each(function ($language) use (&$paths) {
			$paths->push(base_path("lang/$language"));
		});

		$paths->push(storage_path($this->getTemporaryDirectory()));

		$paths->each(function ($path) {
			File::ensureDirectoryExists($path);
		});
	}

	public function start(): self
	{
		return $this;
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
		$routes = [];

		// get download routes for each language
		$this->languages->each(function ($language) use (&$routes) {
			$upperCaseLanguage = strtoupper($language);

			/** @var Response $response */
			$response = $this->getHttp()
				->get("export/json/$upperCaseLanguage?prefix=:");

			if ($response->failed()) {
				return;
			}

			$routes[$language] = $response->json('url');
		});

		// download files
		foreach ($routes as $language => $route) {
			if (! $route) {
				continue;
			}

			/** @var Response $response */
			$response = $this->getHttp()
				->get($route);

			File::put(storage_path($this->getTemporaryDirectory() . "/$language.json"), $response->body());
		}

		return $this;
	}

	/**
	 * Moves the files from the temporary directory to the lang directory
	 */
	public function moveFiles(): self
	{
		$files = File::files(storage_path($this->getTemporaryDirectory()));

		foreach ($files as $file) {
			$language = $file->getFilenameWithoutExtension();
			$destination = base_path("lang/$language/$this->project.json");

			File::move($file, $destination);
		}

		File::deleteDirectory(storage_path($this->getTemporaryDirectory()));

		return $this;
	}
}
