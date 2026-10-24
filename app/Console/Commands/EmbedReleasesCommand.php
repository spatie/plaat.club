<?php

namespace App\Console\Commands;

use App\Models\Release;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

class EmbedReleasesCommand extends Command
{
    protected $signature = 'records:embed';

    protected $description = 'Index changed public release metadata for semantic suggestions using OpenAI';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Semantic search requires PostgreSQL with pgvector.');

            return self::FAILURE;
        }

        $count = 0;
        Release::query()->without('artists')->chunkById(128, function (Collection $releases) use (&$count): void {
            $hashes = DB::table('release_embeddings')->whereIn('release_id', $releases->modelKeys())->pluck('content_hash', 'release_id');
            $changed = $releases->filter(fn (Release $release): bool => $hashes->get($release->id) !== hash('sha256', $release->embeddingText()));
            if ($changed->isEmpty()) {
                return;
            }

            $this->line("Embedding {$changed->count()} changed releases...");
            $response = Embeddings::for($changed->map(fn (Release $release): string => $release->embeddingText())->values()->all())
                ->dimensions(512)->generate('openai', 'text-embedding-3-small');
            $rows = $changed->values()->map(fn (Release $release, int $index): array => [
                'release_id' => $release->id,
                'content_hash' => hash('sha256', $release->embeddingText()),
                'embedding' => json_encode($response->embeddings[$index], JSON_THROW_ON_ERROR),
            ])->all();
            DB::table('release_embeddings')->upsert($rows, ['release_id'], ['content_hash', 'embedding']);
            $count += $changed->count();
        });
        $this->comment("Indexed {$count} changed releases. Unchanged releases were skipped.");

        return self::SUCCESS;
    }
}
