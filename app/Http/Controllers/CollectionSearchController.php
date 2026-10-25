<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Converse\Facades\Converse;

class CollectionSearchController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(config('services.openai.search_enabled'), 404);
        $handle = $request->query('owner');
        $owner = is_string($handle) && $handle !== '' ? User::query()->where('handle', $handle)->firstOrFail() : $request->user();
        abort_unless($owner->canViewCollection($request->user()), 403);
        $question = is_string($request->query('q')) ? mb_substr($request->query('q'), 0, 260) : '';
        if (! $owner->is($request->user())) {
            $question = trim($question.' in the collection of @'.$owner->handle);
        }

        return Inertia::render('social/CollectionSearchPage', [
            'chat' => fn () => Converse::chat('collection', new: $request->boolean('new'))->perPage(30),
            'initialQuestion' => $question,
            'reportedQuestionIds' => Activity::query()->where('log_name', 'collection-ai')->where('causer_type', $request->user()->getMorphClass())
                ->where('causer_id', $request->user()->id)->where('properties->wrong', true)->get()
                ->map(fn (Activity $activity): string => $activity->properties['question_message_id'])->values()->all(),
        ]);
    }

    public function update(Request $request, string $question): HttpResponse
    {
        abort_unless(config('services.openai.search_enabled'), 404);
        $data = $request->validate(['wrong' => ['required', 'boolean']]);
        $activity = Activity::query()->where('log_name', 'collection-ai')->where('event', 'answered')
            ->where('causer_type', $request->user()->getMorphClass())->where('causer_id', $request->user()->id)
            ->where('properties->question_message_id', $question)->firstOrFail();
        $activity->properties = $activity->properties->put('wrong', $data['wrong']);
        $activity->save();

        return response()->noContent();
    }
}
