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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // GitHub identity (OAuth is the only sign-in method)
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('username')->unique();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('github_token')->nullable(); // encrypted via model cast, never returned by the API
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_public')->default(true);

            // Game state (total_xp is a cached sum of the xp_ledger rows)
            $table->unsignedBigInteger('total_xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_active_date')->nullable();
            $table->unsignedTinyInteger('streak_freezes')->default(0);

            // GitHub sync bookkeeping
            $table->string('events_etag')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 16)->default('ok'); // ok | error | needs_reauth
            $table->text('sync_error')->nullable();

            $table->timestamps();

            $table->index('last_synced_at');
            $table->index('total_xp');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
