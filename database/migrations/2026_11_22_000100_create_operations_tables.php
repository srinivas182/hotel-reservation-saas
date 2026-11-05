<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('label', 50)->nullable();
            $table->string('file', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('off_site')->default(false);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['kind', 'status', 'created_at']);
        });

        Schema::create('ops_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60);
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->index(['key', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_incidents');
        Schema::dropIfExists('backup_runs');
    }
};
