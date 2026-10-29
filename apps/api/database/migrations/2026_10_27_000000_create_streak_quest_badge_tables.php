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
        Schema::create('quest_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('title', 120);
            $table->text('description');
            $table->string('period', 8); // daily | weekly
            $table->string('metric', 32); // commits | pr_opened | pr_merged | pr_review | issue_closed | distinct_repos | active_days
            $table->unsignedSmallInteger('target');
            $table->unsignedInteger('xp_reward');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['period', 'is_active']);
        });

        Schema::create('user_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quest_template_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('progress')->default(0);
            // Copied from the template so later template edits cannot rewrite history.
            $table->unsignedSmallInteger('target');
            $table->string('status', 16)->default('active'); // active | completed | expired
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // A template is assigned at most once per period per user.
            $table->unique(['user_id', 'quest_template_id', 'period_start']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'period_end']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 100);
            $table->text('description');
            $table->string('icon', 32); // emoji, keeps the UI pixel-friendly
            $table->string('rarity', 16)->default('common'); // common | rare | epic | legendary

            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');

            $table->timestamps();

            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // level_up | quest_complete | badge_unlocked | streak_at_risk | guild_invite
            $table->string('title', 160);
            $table->string('body', 500);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('user_quests');
        Schema::dropIfExists('quest_templates');
    }
};
