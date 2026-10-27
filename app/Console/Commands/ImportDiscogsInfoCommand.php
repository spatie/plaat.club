<?php

namespace App\Console\Commands;

use App\Actions\Discogs\ImportReleaseInfoAction;
use App\Models\Release;
use App\Support\DiscogsRateLimitException;
use App\Support\EdgeCache;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class ImportDiscogsInfoCommand extends Command
{
    protected $signature = 'records:import-discogs-info';

    protected $description = 'Import missing artist biographies, release notes and credits without changing collection data';

    public function handle(ImportReleaseInfoAction $importReleaseInfo): int
    {
        $releaseIds = Release::query()->whereHas('records')
            ->where(fn ($query) => $query->whereNull('info_imported_at')->orWhereNull('data->discogsTextLinks'))
            ->orderBy('id')->pluck('id');
        $legacyIds = array_fill_keys(Release::query()->whereHas('records.user', fn ($query) => $query->where('legacy_owner', true))->pluck('id')->all(), true);
        $imported = 0;
        $legacyChanged = false;

        try {
            foreach ($releaseIds as $releaseId) {
                $this->info("Importing Discogs information for release {$releaseId}...");

                try {
                    $importReleaseInfo->execute($releaseId);
                } catch (ConnectionException|RequestException|DiscogsRateLimitException $exception) {
                    $reason = $exception instanceof RequestException ? 'HTTP '.$exception->response->status() : class_basename($exception);
                    $this->error("Import stopped: {$reason}. Saved information was kept; rerun this command to resume.");

                    return self::FAILURE;
                }

                $legacyChanged = $legacyChanged || isset($legacyIds[$releaseId]);
                $imported++;
            }
        } finally {
            if ($legacyChanged) {
                app(EdgeCache::class)->purge();
            }
        }

        $this->comment("Imported information for {$imported} releases.");

        return self::SUCCESS;
    }
}
