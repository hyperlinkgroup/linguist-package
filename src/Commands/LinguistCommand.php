<?php

namespace Hyperlinkgroup\Linguist\Commands;

use Hyperlinkgroup\Linguist\Linguist;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class LinguistCommand extends Command
{
	protected $signature = 'linguist:sync';

	protected $description = 'Download translation files from Linguist';

	public function handle(Linguist $linguist): int
	{
		$this->info('Syncing translations from Linguist...');

		try {
			$linguist->handle();

			$this->info('Translations synced successfully.');

			return SymfonyCommand::SUCCESS;
		} catch (\Hyperlinkgroup\Linguist\Exceptions\ConfigBrokenException $e) {
			$this->error($e->getMessage());

			return SymfonyCommand::FAILURE;
		} catch (\Hyperlinkgroup\Linguist\Exceptions\NoLanguageActivatedException $e) {
			$this->error('No languages are activated in your Linguist project.');

			return SymfonyCommand::FAILURE;
		}
	}
}
