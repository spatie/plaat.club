<?php

namespace App\Listeners;

use App\Ai\Agents\CollectionQuestion;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolResult;

class LogCollectionQuestionListener
{
    public function log(AgentPrompted|AgentFailed $event): void
    {
        $agent = $event->prompt->agent;
        if (! $agent instanceof CollectionQuestion || $agent->activity === null) {
            return;
        }

        $complete = $event instanceof AgentPrompted;
        $response = $complete ? $event->response : $event->prompt->runContext()?->recordedResponse();
        $usage = $response?->usage;
        $model = $response?->meta->model ?? config('services.openai.search_model');
        $rates = config('services.openai.search_pricing', [])[$model] ?? null;
        $knownUsage = $complete || $response?->steps->isNotEmpty();
        $cost = $knownUsage && $usage !== null && is_array($rates)
            ? (($usage->inputTokens - ($usage->cacheReadInputTokens ?? 0)) * $rates['input']
                + ($usage->cacheReadInputTokens ?? 0) * $rates['cached_input']
                + $usage->outputTokens * $rates['output']) / 1_000_000
            : null;
        $tools = $response?->steps->flatMap(fn (Step $step): array => $step->toolResults) ?? collect();

        $agent->activity->event = $complete ? 'answered' : 'failed';
        $agent->activity->description = $complete ? 'Answered collection question' : 'Collection question failed';
        $agent->activity->properties = $agent->activity->properties->merge([
            'invocation_id' => $event->invocationId,
            'provider' => $response?->meta->provider ?? 'openai',
            'model' => $model,
            'duration_ms' => (int) round((hrtime(true) - $agent->startedAt) / 1_000_000),
            'usage_complete' => $complete,
            'input_tokens' => $knownUsage ? $usage?->inputTokens : null,
            'cached_input_tokens' => $knownUsage ? $usage?->cacheReadInputTokens : null,
            'output_tokens' => $knownUsage ? $usage?->outputTokens : null,
            'reasoning_tokens' => $knownUsage ? $usage?->reasoningTokens : null,
            'estimated_text_cost_usd' => $cost === null ? null : round($cost, 8),
            'steps' => $response?->steps->count() ?? 0,
            'tool_calls' => $tools->count(),
            'tool_errors' => $tools->filter(function (ToolResult $tool): bool {
                $result = json_decode($tool->text(), true);

                return ! $tool->successful() || ! is_array($result) || isset($result['error']);
            })->count(),
            'tools' => $tools->map(fn (ToolResult $tool): string => $tool->name)->unique()->values()->all(),
            'error_type' => $event instanceof AgentFailed ? $event->exception::class : null,
        ]);
        $agent->activity->save();
    }
}
