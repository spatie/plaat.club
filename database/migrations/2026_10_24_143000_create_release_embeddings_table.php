<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        Schema::create('release_embeddings', function (Blueprint $table): void {
            $table->foreignId('release_id')->primary()->constrained('releases')->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->vector('embedding', 512);
        });
    }
};
