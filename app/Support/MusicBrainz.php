<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class MusicBrainz
{
    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query): ?array
    {
        return Cache::lock('musicbrainz-request', 35)->block(40, function () use ($path, $query): ?array {
            $delay = Cache::get('musicbrainz-next-request', 0) - now()->getTimestampMs();
            if ($delay > 5000) {
                throw new MusicBrainzRateLimitException((int) ceil($delay / 1000));
            }
            if ($delay > 0) {
                Sleep::for($delay)->milliseconds();
            }

            try {
                $response = Http::withUserAgent(config('services.musicbrainz.user_agent'))
                    ->connectTimeout(5)->timeout(20)
                    ->get("https://musicbrainz.org/ws/2/{$path}", [...$query, 'fmt' => 'json']);
            } finally {
                Cache::put('musicbrainz-next-request', now()->getTimestampMs() + 1100, 120);
            }
            if (in_array($response->status(), [429, 503], true)) {
                $retry = $response->header('Retry-After');
                $seconds = is_numeric($retry) ? (int) $retry : max(0, (strtotime($retry) ?: 0) - now()->getTimestamp());
                $seconds = max(60, $seconds);
                Cache::put('musicbrainz-next-request', now()->addSeconds($seconds)->getTimestampMs(), $seconds + 120);

                throw new MusicBrainzRateLimitException($seconds);
            }
            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->json();
        });
    }
}
