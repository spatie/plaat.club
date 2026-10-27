<?php

namespace App\Actions\Discogs;

use App\Models\Release;
use App\Support\Discogs;
use App\Support\DiscogsRecordMapper;
use Closure;
use Illuminate\Support\Facades\File;

class FetchDiscogsRecordAction
{
    public function __construct(protected DiscogsRecordMapper $mapper) {}

    /**
     * @param  array<string, mixed>  $entry
     * @param  ?Closure(): bool  $shouldPause
     * @return array<string, mixed>|null
     */
    public function execute(array $entry, Discogs $discogs, ?Closure $shouldPause = null, bool $storeResponse = false): ?array
    {
        $basic = $entry['basic_information'];

        if ($shared = Release::query()->where('id', $basic['id'])->first()) {
            return [
                'id' => $shared->id, 'instanceId' => $entry['instance_id'],
                ...$shared->recordData(), 'addedAt' => $entry['date_added'],
            ];
        }

        $release = $discogs->get("releases/{$basic['id']}");
        if ($shouldPause && $shouldPause()) {
            return null;
        }
        $master = empty($basic['master_id']) ? [] : $discogs->get("masters/{$basic['master_id']}");

        if ($storeResponse) {
            File::ensureDirectoryExists(storage_path('app/discogs'));
            File::put(storage_path("app/discogs/releases-{$basic['id']}.json"), json_encode($release, JSON_THROW_ON_ERROR));
        }

        $record = $this->mapper->record($entry, $release, $master, $discogs->artistBios($release['artists'] ?? $basic['artists']));
        $record['discogsTextLinks'] = $discogs->textLinks($record);

        return $record;
    }
}
