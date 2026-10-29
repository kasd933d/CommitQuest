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
        Schema::create('guilds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_public')->default(true);
            // Private guilds need the code to join; public ones ignore it.
            $table->string('invite_code', 32)->unique();

            $table->timestamps();

            $table->index('is_public');
        });

        Schema::create('guild_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // owner | member
            $table->timestamp('joined_at');

            $table->timestamps();

            $table->unique(['guild_id', 'user_id']);
            // The "max 3 guilds per user" rule (PRD §4.7) is enforced here.
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guild_members');
        Schema::dropIfExists('guilds');
    }
};
