<?php

namespace App\Actions\Accounts;

use App\Models\Activity;
use App\Models\User;
use App\Support\EdgeCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Converse\Models\Thread;

class DeleteAccountAction
{
    public function execute(User $user): void
    {
        $lock = Cache::lock('discogs-sync-'.$user->id, 7500);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['confirmation' => 'Your collection is syncing. Please wait for it to finish, then try again.']);
        }

        try {
            DB::transaction(function () use ($user): void {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                Thread::query()->where('connection', 'web')->where('channel', $user->getMorphClass().':'.$user->getKey())->delete();
                Activity::query()->where('log_name', 'collection-ai')->where('causer_type', $user->getMorphClass())->where('causer_id', $user->id)->delete();
                $user->delete();
            });

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
            }

            if ($user->legacy_owner) {
                Storage::disk('public')->deleteDirectory('share');
                app(EdgeCache::class)->purge();
            }
        } finally {
            $lock->release();
        }
    }
}
