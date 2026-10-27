<?php

namespace App\Http\Controllers;

use App\Models\RecordList;
use App\Models\RecordListEntry;
use App\Support\RecordCoverImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class ListEntryCoversController extends Controller
{
    public function __invoke(Request $request, RecordList $recordList, RecordListEntry $entry, RecordCoverImages $images): Response|RedirectResponse
    {
        abort_unless($recordList->canView($request->user()), 404);
        abort_unless($entry->record_list_id === $recordList->id && $entry->release_id !== null, 404);

        $source = $entry->release->cover;
        if (blank($source) || $source === '/images/record-placeholder.svg') {
            return redirect('/images/record-placeholder.svg');
        }

        $bytes = Cache::store('images')->remember('record-cover:'.hash('sha256', $source), now()->addDay(), fn () => $images->read($source));

        return response($bytes)->header('Content-Type', 'image/jpeg');
    }
}
