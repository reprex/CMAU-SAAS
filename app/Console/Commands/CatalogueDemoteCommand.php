<?php

namespace App\Console\Commands;

use App\Catalogue\TierManager;
use Illuminate\Console\Command;

class CatalogueDemoteCommand extends Command
{
    protected $signature = 'catalogue:demote';

    protected $description = 'Move active products unused for the configured window back to the summary tier';

    public function handle(TierManager $tiers): int
    {
        $this->info($tiers->demoteStale().' products moved to the summary tier.');

        return self::SUCCESS;
    }
}
