<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('minimax_generation_log', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->string('tool_name', 64);
            $table->string('provider', 32);
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_minimax_log_user_time');
            $table->index(['agent_id', 'created_at'], 'idx_minimax_log_agent_time');
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('minimax_generation_log');
    }
};
