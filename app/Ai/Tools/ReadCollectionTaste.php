<?php

namespace App\Ai\Tools;

use App\Ai\ToolResult;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ReadCollectionTaste implements Tool
{
    public function __construct(
        protected User $viewer,
    ) {}

    public function description(): string
    {
        return 'Read a compact overview of your own collection’s artists, genres, detailed styles and recent additions to personalise records to buy next. Ownership is evidence of interest, not proof of favourite music or listening frequency. Contains no notes or other users’ data.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $copies = $this->viewer->records()->with('release')->orderByDesc('added_at')->orderByDesc('id')->get();
        $releases = $copies->pluck('release')->unique('id');
        $artists = $releases->groupBy('artist')->map(fn ($records, string $artist): array => [
            'artist' => mb_substr($artist, 0, 200),
            'releases' => $records->count(),
            'examples' => $records->take(4)->map(fn ($release): array => [
                'title' => mb_substr($release->title ?? '', 0, 200), 'year' => $release->original_year,
            ])->values()->all(),
        ])->sortByDesc('releases')->take(25)->values();
        $tags = [];
        foreach (['genres', 'styles'] as $field) {
            $tags[$field] = $releases->flatMap(fn ($release): array => array_unique($release->data[$field] ?? []))
                ->countBy()->sortDesc()->take(15)->all();
        }

        return ToolResult::encode([
            'copies' => $copies->count(), 'distinctReleases' => $releases->count(),
            'artists' => $artists->all(), ...$tags,
            'recentAdditions' => $releases->take(12)->map(fn ($release): array => [
                'artist' => mb_substr($release->artist ?? '', 0, 200),
                'title' => mb_substr($release->title ?? '', 0, 200), 'year' => $release->original_year,
            ])->values()->all(),
            'limitations' => 'An overview, not the full collection. Counts are distinct releases, not listening frequency. Use CheckRecordPurchases before recommending a purchase. No collection data is sent to MusicBrainz.',
        ]);
    }
}
