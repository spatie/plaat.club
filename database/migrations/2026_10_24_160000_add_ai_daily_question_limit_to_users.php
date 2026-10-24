<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('ai_daily_question_limit')->nullable();
        });

        DB::table('users')->whereIn('handle', ['freek', 'jmoortga', 'thomasbartosik'])
            ->update(['ai_daily_question_limit' => 5000]);
    }
};
