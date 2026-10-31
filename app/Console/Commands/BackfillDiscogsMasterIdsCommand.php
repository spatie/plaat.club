<?php

namespace App\Console\Commands;

use App\Jobs\BackfillDiscogsMasterId;
use App\Models\Release;
use Illuminate\Console\Command;

class BackfillDiscogsMasterIdsCommand extends Command
{
    protected $signature = 'records:backfill-discogs-master-ids';

    protected $description = 'Queue exact Discogs release lookups for missing shared release master IDs';

    public function handle(): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            $this->error('Configure an asynchronous queue connection before running this backfill.');

            return self::FAILURE;
        }

        $count = 0;
        Release::query()->without('artists')->whereNull('discogs_master_id')->select('id')
            ->eachById(function (Release $release) use (&$count): void {
                $this->line("Queueing master ID lookup for release {$release->id}...");
                BackfillDiscogsMasterId::dispatch($release->id)->afterCommit();
                $count++;
            });

        $this->comment("Dispatched {$count} master ID lookups to release-enrichment (pending duplicates are suppressed). No-master releases remain missing; inspect backfill logs for outcomes.");

        return self::SUCCESS;
    }
}
