<?php

namespace App\Actions\Collections;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ValidateCollectionQuestionAction
{
    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function execute(array $plan, string $question, string $source, bool $hasRecentList = false): array
    {
        foreach (['genre', 'style'] as $field) {
            $filter = $plan[$field];
            if ($filter === null) {
                continue;
            }
            $value = $this->normalize($filter['value']);
            $quote = $this->normalize($filter['quote']);
            $aliases = [
                'electronic' => ['electronic', 'elektronisch', 'elektronische', 'elektronica'],
                'experimental' => ['experimental', 'experimenteel', 'experimentele'],
                'classical' => ['classical', 'klassiek', 'klassieke'],
                'folk, world, & country' => ['folk', 'world', 'country'],
                'trip hop' => ['trip hop', 'triphop', 'trip-hop'],
            ];
            if ($quote === '' || ! str_contains($this->normalize($source), $quote)
                || ! collect($aliases[$value] ?? [$value])->contains(fn (string $alias): bool => str_contains($quote, $alias))) {
                $plan['unsupported'][] = "Please name the {$field} explicitly; I could not verify that filter.";
            }
        }

        if ($plan['target_handle'] !== null && ! str_contains($this->normalize($source), $this->normalize(ltrim($plan['target_handle'], '@')))) {
            $plan['unsupported'][] = 'Please include the exact @handle, username or full name of the collector.';
        }
        if (filled($plan['person_quote'] ?? null) && ! str_contains($this->normalize($source), $this->normalize($plan['person_quote']))) {
            $plan['unsupported'][] = 'Please clarify the musician you mean; I could not verify the name in your question.';
        }
        if (($plan['semantic_query'] ?? null) !== null) {
            $quote = $this->normalize($plan['semantic_quote'] ?? '');
            if ($quote === '' || ! str_contains($this->normalize($source), $quote)
                || in_array($quote, array_filter([$this->normalize($plan['genre']['quote'] ?? ''), $this->normalize($plan['style']['quote'] ?? '')]), true)) {
                $plan['unsupported'][] = 'Please describe the mood or similarity you want; I could not verify that recommendation request.';
            }
            if (! config('services.openai.semantic_enabled')) {
                $plan['unsupported'][] = 'Metadata-based recommendations are not enabled yet.';
            }
            if ($plan['intent'] !== 'search') {
                $plan['unsupported'][] = 'Recommendations cannot select list contents or compare collections. Use factual filters instead.';
            }
        }
        if (($plan['year_extreme'] ?? null) !== null && ($plan['intent'] !== 'search' || ($plan['semantic_query'] ?? null) !== null)) {
            $plan['unsupported'][] = 'Ask for the oldest or newest record in a factual search, separately from recommendations, comparisons or list changes.';
        }
        if (($plan['order'] ?? null) !== null && ($plan['intent'] !== 'search' || ($plan['semantic_query'] ?? null) !== null || ($plan['year_extreme'] ?? null) !== null)) {
            $plan['unsupported'][] = 'Ask for recent collection additions separately from release-year ranking, recommendations, comparisons or list changes.';
        }
        if ($plan['intent'] === 'lists') {
            if (($plan['list']['use_recent'] ?? false) && ! $hasRecentList) {
                $plan['unsupported'][] = 'Which list do you mean? Include its name.';
            }
            if ($plan['list'] === null) {
                $plan['unsupported'][] = 'Describe one list operation and include the exact list name.';
            }
            if ($plan['scope'] !== 'collection' || $plan['target_handle'] !== null) {
                $plan['unsupported'][] = 'List tools only manage your own lists and collection records.';
            }
            if (($plan['list']['records'] ?? null) === null) {
                foreach (['person', 'exclude_artist', 'title', 'label', 'track', 'genre', 'style', 'role', 'format', 'release_type', 'year_from', 'year_to'] as $field) {
                    if (($plan[$field] ?? null) !== null) {
                        $plan['unsupported'][] = 'Say explicitly whether matching records should be added to or removed from the list.';
                        break;
                    }
                }
            }
            foreach (['target', 'name', 'description'] as $field) {
                $value = $plan['list'][$field] ?? null;
                if (filled($value) && ! str_contains($this->normalize($question), $this->normalize($value))) {
                    $plan['unsupported'][] = 'Use the exact list name and description in your request.';
                }
            }
            $visibility = $plan['list']['visibility'] ?? null;
            $audiences = [
                'only_me' => '/\b(prive|private|alleen voor mij|alleen mij|only me|only_me)\b/',
                'friends' => '/\b(vrienden|friends)\b/',
                'everyone' => '/\b(publiek|public|openbaar|iedereen|everyone)\b/',
            ];
            $privateByDefault = ($plan['list']['action'] ?? null) === 'create' && $visibility === 'only_me';
            if ($visibility !== null && ! $privateByDefault && ! preg_match($audiences[$visibility], $this->normalize($question))) {
                $plan['unsupported'][] = 'Name the audience explicitly to change list visibility.';
            }
        }
        if ($plan['intent'] === 'compare') {
            foreach (['person', 'exclude_artist', 'title', 'label', 'track', 'genre', 'style', 'role', 'format', 'release_type', 'year_from', 'year_to'] as $field) {
                if (($plan[$field] ?? null) !== null) {
                    $plan['unsupported'][] = 'This prototype compares whole collections. Ask a separate question to filter records.';
                    break;
                }
            }
        }

        if ($plan['unsupported'] !== []) {
            throw ValidationException::withMessages(['question' => $plan['unsupported']]);
        }

        return $plan;
    }

    protected function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
