<?php

namespace App\Http\Controllers;

use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\RecordListEntry;
use App\Models\User;
use App\Support\RecordShareImage;
use App\Support\SocialPreview;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class SocialPreviewController extends Controller
{
    public function home(RecordShareImage $image): Response
    {
        return $this->image(['home', SocialPreview::HOME_IMAGE_VERSION], fn () => $image->renderHome());
    }

    public function collection(User $user, RecordShareImage $image): Response
    {
        abort_unless($user->canViewCollection(null), 404);

        $records = array_values($user->records()->orderByDesc('added_at')->orderByDesc('id')->limit(5)->get()
            ->map(fn (CollectionRecord $record) => Arr::only($record->payload, ['cover']))->all());
        $title = "{$user->name}’s collection";
        $subtitle = "@{$user->handle} / Plaat Club";

        return $this->image([$title, $subtitle, $records], fn () => $image->renderClub($title, $subtitle, $records));
    }

    public function record(CollectionRecord $record, RecordShareImage $image): Response
    {
        abort_unless($record->user->canViewCollection(null), 404);

        $fields = Arr::only($record->payload, ['cover', 'artist', 'displayTitle', 'originalYear']);

        return $this->image(['record', SocialPreview::RECORD_IMAGE_VERSION, $fields], fn () => $image->render($fields));
    }

    public function list(RecordList $recordList, RecordShareImage $image): Response
    {
        abort_unless($recordList->canView(null), 404);

        $records = array_values($recordList->entries()->whereNotNull('release_id')->with('release')->limit(5)->get()
            ->map(fn (RecordListEntry $entry) => ['cover' => $entry->release->cover ?? ''])->all());
        $title = $recordList->name;

        return $this->image(['list', $title, $records], fn () => $image->renderClub($title, '', $records));
    }

    /**
     * @param  array<int|string, mixed>  $fields
     * @param  Closure(): string  $render
     */
    protected function image(array $fields, Closure $render): Response
    {
        $key = 'social-preview:v3:'.hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
        $bytes = Cache::store('images')->remember($key, now()->addDay(), $render);

        return response($bytes)->header('Content-Type', 'image/jpeg');
    }
}
