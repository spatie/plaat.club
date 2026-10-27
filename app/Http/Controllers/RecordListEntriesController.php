<?php

namespace App\Http\Controllers;

use App\Models\RecordList;
use App\Models\RecordListEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class RecordListEntriesController extends Controller
{
    public function show(Request $request, RecordList $recordList, RecordListEntry $entry): JsonResponse
    {
        $this->authorizeEntry($request, $recordList, $entry);
        $data = $entry->release->recordData();
        $payload = Arr::only($data, [
            'artist', 'artists', 'title', 'displayTitle', 'edition', 'year', 'originalYear',
            'originalReleaseDate', 'pressingReleaseDate', 'label', 'catalogNumber', 'format', 'mediaFormats',
            'genres', 'styles', 'discogsUrl', 'appleMusicUrl', 'appleMusicSearch', 'spotifyUrl', 'spotifySearch',
            'bandcampUrl', 'bandcampSearch', 'trackSource', 'artistBios', 'releaseNotes', 'credits',
        ]);
        $payload['id'] = $entry->release_id;
        $payload['instanceId'] = $entry->id;
        $payload['cover'] = route('lists.entries.cover', [$recordList, $entry], false);
        $payload['tracks'] = array_map(fn (array $track) => Arr::only($track, [
            'position', 'title', 'artist', 'duration', 'appleMusicUrl', 'appleMusicSearch', 'spotifyUrl', 'spotifySearch',
        ]), $data['tracks'] ?? []);

        return response()->json($payload);
    }

    public function update(Request $request, RecordList $recordList, RecordListEntry $entry): RedirectResponse
    {
        abort_unless($recordList->user_id === $request->user()->id, 404);
        $this->authorizeEntry($request, $recordList, $entry);
        $data = $request->validate(['privateNote' => ['present', 'nullable', 'string', 'max:2000']]);
        $entry->update(['private_note' => $data['privateNote']]);

        return back()->with('status', 'Private note saved.');
    }

    protected function authorizeEntry(Request $request, RecordList $list, RecordListEntry $entry): void
    {
        abort_unless($list->canView($request->user()), 404);
        abort_unless($entry->record_list_id === $list->id && $entry->release_id !== null, 404);
    }
}
