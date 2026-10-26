<?php

use App\Support\DiscogsRecordMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapper = app(DiscogsRecordMapper::class);
        DB::table('releases')->orderBy('id')->chunkById(200, function ($releases) use ($mapper): void {
            foreach ($releases as $release) {
                $formats = $mapper->mediaFormats($release->format ?? '');
                if ($formats === []) {
                    continue;
                }

                $data = json_decode($release->data, true, flags: JSON_THROW_ON_ERROR);
                if (($data['mediaFormats'] ?? null) === $formats) {
                    continue;
                }

                $data['mediaFormats'] = $formats;
                DB::table('releases')->where('id', $release->id)->update(['data' => json_encode($data, JSON_THROW_ON_ERROR)]);
            }
        });
    }
};
