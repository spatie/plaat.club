<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_list_entries', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('release_id')->nullable()->constrained('releases')->restrictOnDelete();
            $table->text('private_note')->nullable();
        });

        DB::table('record_list_entries')->orderBy('id')->chunkById(500, function ($entries): void {
            foreach ($entries as $entry) {
                DB::table('record_list_entries')->where('id', $entry->id)->update([
                    'public_id' => (string) Str::uuid(),
                    'release_id' => $entry->collection_record_id === null ? null : DB::table('collection_records')->where('id', $entry->collection_record_id)->value('discogs_release_id'),
                ]);
            }
        });
    }
};
