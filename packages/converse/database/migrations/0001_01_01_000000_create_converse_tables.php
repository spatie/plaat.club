<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('converse_threads', function (Blueprint $table): void {
            $table->id();
            $table->string('bot');
            $table->string('connection');
            $table->string('external_id');
            $table->string('channel');
            $table->string('type')->nullable();
            $table->string('agent_conversation_id')->nullable();
            $table->json('pending_approvals')->nullable();
            $table->unsignedInteger('last_event_sequence')->default(0);
            $table->timestamp('subscribed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['bot', 'connection', 'external_id']);
            $table->index(['bot', 'connection', 'channel']);
        });

        Schema::create('converse_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('converse_threads')->cascadeOnDelete();
            $table->string('role');
            $table->string('status');
            $table->string('external_id')->nullable();
            $table->json('external_user')->nullable();
            $table->text('text');
            $table->string('type');
            $table->json('attachments')->nullable();
            $table->timestamp('external_sent_at', 6);
            $table->timestamp('external_edited_at', 6)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['thread_id', 'external_id']);
            $table->index(['thread_id', 'status', 'external_sent_at']);
        });

        Schema::create('converse_runs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('thread_id')->constrained('converse_threads')->cascadeOnDelete();
            $table->string('thread_lock')->unique();
            $table->string('status');
            $table->string('prompt_message_id')->nullable();
            $table->string('client_message_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'status']);
            $table->index(['thread_id', 'client_message_id']);
        });

        Schema::create('converse_thread_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('converse_threads')->cascadeOnDelete();
            $table->string('run_id');
            $table->foreign('run_id')->references('id')->on('converse_runs')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['thread_id', 'sequence']);
            $table->index(['run_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('converse_thread_events');
        Schema::dropIfExists('converse_runs');
        Schema::dropIfExists('converse_messages');
        Schema::dropIfExists('converse_threads');
    }

    /**
     * Get the database connection name to use for the migration.
     */
    public function getConnection(): ?string
    {
        return config('converse.storage.connection') ?? parent::getConnection();
    }
};
