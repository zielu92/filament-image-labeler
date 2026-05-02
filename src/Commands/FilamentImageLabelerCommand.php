<?php

namespace Zielu92\FilamentImageLabeler\Commands;

use Illuminate\Console\Command;

class FilamentImageLabelerCommand extends Command
{
    public $signature = 'filament-image-labeler';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
