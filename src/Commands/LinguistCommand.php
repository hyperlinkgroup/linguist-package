<?php

namespace Hyperlinkgroup\Linguist\Commands;

use Illuminate\Console\Command;

class LinguistCommand extends Command
{
    public $signature = 'linguist';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
