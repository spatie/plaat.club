<?php

namespace App\Support;

use App\Models\CollectionRecord;
use App\Models\RecordList;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialPreview
{
    public const HOME_IMAGE_VERSION = 2;

    public const RECORD_IMAGE_VERSION = 2;

    /** @return array<string, string> */
    public function metadata(Request $request): array
    {
        $preview = [
            'title' => 'Plaat Club',
            'description' => 'Good taste is contagious. Share your record collection, discover your friends’ finds, and keep the conversation spinning.',
            'url' => route('home'),
            'image' => route('socialPreview.home', ['v' => self::HOME_IMAGE_VERSION]),
            'imageAlt' => 'Plaat Club. Good taste is contagious.',
            'type' => 'website',
        ];

        $list = $request->route('recordList');
        if ($list instanceof RecordList && $list->canView(null)) {
            return array_replace($preview, [
                'title' => "{$list->name} · {$list->user->name}",
                'description' => $list->description ?: "A selection of records by {$list->user->name} on Plaat Club.",
                'url' => route('lists.show', $list),
                'image' => route('socialPreview.list', $list),
                'imageAlt' => "Record sleeves from {$list->name}, a list by {$list->user->name} on Plaat Club",
            ]);
        }

        $record = $request->route('record');
        $id = $request->query('record');
        if (! $record instanceof CollectionRecord && is_string($id) && Str::isUuid($id)) {
            $record = CollectionRecord::query()->where('public_id', $id)->first();
        }
        if ($record instanceof CollectionRecord && $record->user->canViewCollection(null)) {
            $title = "{$record->payload['displayTitle']} by {$record->payload['artist']}";

            return array_replace($preview, [
                'title' => $title,
                'description' => "{$title}, from {$record->user->name}’s collection on Plaat Club.",
                'url' => $record->url(),
                'image' => route('socialPreview.record', ['record' => $record, 'v' => self::RECORD_IMAGE_VERSION]),
                'imageAlt' => $title,
                'type' => 'music.album',
            ]);
        }

        $user = $request->route('user');
        if ($user instanceof User && $user->canViewCollection(null)) {
            return array_replace($preview, [
                'title' => "{$user->name}’s collection",
                'description' => "Explore @{$user->handle}’s record collection on Plaat Club.",
                'url' => route('profile', $user->handle),
                'image' => route('socialPreview.collection', $user->handle),
                'imageAlt' => "Record sleeves from {$user->name}’s collection on Plaat Club",
            ]);
        }

        return $preview;
    }
}
