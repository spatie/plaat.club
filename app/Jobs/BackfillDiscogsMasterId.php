<?php

namespace App\Jobs;

use App\Models\Release;
use App\Support\Discogs;
use App\Support\DiscogsRateLimitException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BackfillDiscogsMasterId implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 60;

    public function __construct(public int $releaseId)
    {
        $this->onQueue('release-enrichment');
    }

    public function uniqueId(): string
    {
        return (string) $this->releaseId;
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addWeek();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(Discogs $discogs): void
    {
        if (! Release::query()->whereKey($this->releaseId)->whereNull('discogs_master_id')->exists()) {
            return;
        }

        try {
            $response = $discogs->get("releases/{$this->releaseId}");
        } catch (DiscogsRateLimitException $exception) {
            $this->release($exception->retryAfter);

            return;
        } catch (ConnectionException|RequestException $exception) {
            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                Log::info('Discogs master ID backfill', ['releaseId' => $this->releaseId, 'outcome' => 'not_found']);

                return;
            }

            // HTTP exceptions may contain authorization headers; never serialize them into failed_jobs.
            $reason = $exception instanceof RequestException ? 'HTTP '.$exception->response->status() : class_basename($exception);

            throw new RuntimeException("Discogs master ID backfill failed for {$this->releaseId}: {$reason}.");
        }

        $masterId = $response['master_id'] ?? 0;
        if (! is_int($masterId) || $masterId <= 0) {
            Log::info('Discogs master ID backfill', ['releaseId' => $this->releaseId, 'outcome' => 'no_master']);

            return;
        }

        $updated = DB::table('releases')->where('id', $this->releaseId)->whereNull('discogs_master_id')
            ->update(['discogs_master_id' => $masterId]);

        Log::info('Discogs master ID backfill', [
            'releaseId' => $this->releaseId,
            'outcome' => $updated ? 'filled' : 'unchanged',
            'masterId' => $masterId,
        ]);
    }
}
