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
        foreach (['collection_records', 'users', 'friendships', 'comments', 'activity_log'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('public_id')->nullable()->unique();
            });

            DB::table($tableName)->whereNull('public_id')->select('id')->chunkById(500, function ($rows) use ($tableName): void {
                $cases = [];
                $bindings = [];
                foreach ($rows as $row) {
                    $cases[] = DB::getDriverName() === 'pgsql' ? 'WHEN ? THEN CAST(? AS UUID)' : 'WHEN ? THEN ?';
                    $bindings[] = $row->id;
                    $bindings[] = (string) Str::uuid();
                }
                $placeholders = implode(', ', array_fill(0, count($cases), '?'));
                $cases = implode(' ', $cases);
                DB::update("UPDATE {$tableName} SET public_id = CASE id {$cases} END WHERE public_id IS NULL AND id IN ({$placeholders})", [
                    ...$bindings, ...$rows->pluck('id')->all(),
                ]);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('public_id')->nullable(false)->change();
            });
        }
    }
};
