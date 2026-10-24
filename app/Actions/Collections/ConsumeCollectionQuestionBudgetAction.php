<?php

namespace App\Actions\Collections;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class ConsumeCollectionQuestionBudgetAction
{
    public function execute(User $viewer): ?string
    {
        try {
            return Cache::lock('collection-questions:budget-lock', 10)->block(2, function () use ($viewer): ?string {
                $now = CarbonImmutable::now(config('services.openai.daily_limit_timezone', 'Europe/Brussels'));
                $reset = $now->addDay()->startOfDay();
                $resetLabel = $reset->format('Y-m-d H:i:s P e');
                $userKey = "collection-questions:{$now->toDateString()}:user:{$viewer->getKey()}";
                $globalKey = "collection-questions:{$now->toDateString()}:global";
                $userCount = (int) Cache::get($userKey, 0);
                $globalCount = (int) Cache::get($globalKey, 0);

                if ($userCount >= ($viewer->ai_daily_question_limit ?? (int) config('services.openai.daily_user_limit', 25))) {
                    return "You have reached your personal daily AI question limit. It resets at {$resetLabel}.";
                }

                if ($globalCount >= (int) config('services.openai.daily_global_limit', 1000)) {
                    return "The shared daily AI question limit has been reached. It resets at {$resetLabel}.";
                }

                Cache::put($userKey, $userCount + 1, $reset);
                Cache::put($globalKey, $globalCount + 1, $reset);

                return null;
            });
        } catch (LockTimeoutException) {
            return 'AI questions are temporarily busy. Please try again in a few seconds.';
        }
    }
}
