<?php

namespace App\Actions\Lists;

use App\Actions\Collections\StoreReleaseAction;
use App\Models\Release;
use App\Models\User;
use App\Support\Discogs;
use App\Support\DiscogsRateLimitException;
use App\Support\DiscogsRecordMapper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

class ImportListReleaseAction
{
    public function execute(User $user, int $releaseId): Release
    {
        $release = Release::query()->find($releaseId);
        if ($release !== null) {
            return $release;
        }

        $discogs = new Discogs(filled($user->discogs_token) ? $user : null);
        try {
            $details = $discogs->get("releases/{$releaseId}");
            $master = empty($details['master_id']) ? [] : $discogs->get("masters/{$details['master_id']}");
        } catch (DiscogsRateLimitException|ConnectionException|RequestException) {
            throw ValidationException::withMessages(['entries' => 'Discogs is unavailable or busy. Please try again shortly.']);
        }

        if (empty($details['title']) || empty($details['artists'])) {
            throw ValidationException::withMessages(['entries' => 'Discogs did not return enough information for this release.']);
        }

        $payload = app(DiscogsRecordMapper::class)->record([
            'instance_id' => 0, 'date_added' => now()->toIso8601String(),
            'basic_information' => [...$details, 'id' => $releaseId, 'cover_image' => $details['cover_image'] ?? $details['thumb'] ?? ''],
        ], $details, $master);
        $payload['cover'] = $payload['coverSource'];
        unset($payload['artistBios']);

        return app(StoreReleaseAction::class)->execute($releaseId, $payload);
    }
}
