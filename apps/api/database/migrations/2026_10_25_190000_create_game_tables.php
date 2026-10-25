<?php

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
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // GitHub's event id, suffixed (`{id}:{commit sha}`) when one GitHub
            // event produces several rows (PushEvent with N commits). The unique
            // index is what makes syncing twice impossible to double count.
            $table->string('github_event_id', 64);
            $table->string('type', 32); // commit | pr_opened | pr_merged | pr_review | issue_closed | new_repo
            $table->string('repo_full_name', 160);
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('xp_awarded')->default(0);
            // Award state: pending (both null), awarded (awarded_at set),
            // skipped by an anti-farming cap (skipped_reason set).
            $table->timestamp('awarded_at')->nullable();
            $table->string('skipped_reason', 32)->nullable();

            $table->timestamps();

            $table->unique('github_event_id');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'repo_full_name', 'occurred_at']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('xp_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 16); // activity | quest | badge
            $table->unsignedBigInteger('source_id')->nullable();
            $table->integer('amount'); // positive in v1 (PRD §4.3)
            $table->string('reason', 191);

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            // One ledger row per source: the database, not just the code,
            // guarantees a reward can never be paid twice.
            $table->unique(['user_id', 'source_type', 'source_id']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('events_fetched')->default(0);
            $table->unsignedInteger('events_new')->default(0);
            $table->string('status', 32); // running | ok | not_modified | rate_limited | needs_reauth | error
            $table->text('error')->nullable();
            $table->unsignedInteger('rate_limit_remaining')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('xp_ledger');
        Schema::dropIfExists('sync_runs');
    }
};
