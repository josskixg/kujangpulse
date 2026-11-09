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
        Schema::create('snitches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('schedule_type')->default('interval');
            $table->integer('interval_seconds')->default(300);
            $table->integer('grace_seconds')->default(60);
            $table->string('auth_secret', 64)->nullable();
            $table->boolean('require_auth')->default(false);
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamp('next_expected_at')->nullable();
            $table->timestamp('grace_deadline_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->decimal('last_duration_ms', 10, 2)->nullable();
            $table->decimal('last_memory_mb', 8, 2)->nullable();
            $table->integer('last_exit_code')->default(0);
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_expected_at']);
        });

        Schema::create('ping_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('snitch_id');
            $table->string('status', 32);
            $table->decimal('duration_ms', 10, 2)->nullable();
            $table->decimal('memory_mb', 8, 2)->nullable();
            $table->integer('exit_code')->default(0);
            $table->string('client_ip', 45)->nullable();
            $table->timestamp('pinged_at');

            $table->foreign('snitch_id')->references('id')->on('snitches')->cascadeOnDelete();
            $table->index(['snitch_id', 'pinged_at']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('snitch_id');
            $table->string('severity', 32);
            $table->string('status', 32)->default('OPEN');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('downtime_seconds')->default(0);
            $table->json('notifications_dispatched')->nullable();
            $table->timestamps();

            $table->foreign('snitch_id')->references('id')->on('snitches')->cascadeOnDelete();
            $table->index(['snitch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('ping_logs');
        Schema::dropIfExists('snitches');
    }
};
