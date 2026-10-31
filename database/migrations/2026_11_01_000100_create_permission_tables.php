<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission in "teams" mode, adapted to this platform:
 * the team is the hotel (ULID), users have ULID keys, and roles record
 * whether they are a system role (copied into every hotel, not editable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['hotel_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->ulid('model_id');
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();

            $table->index(['model_id', 'model_type']);
            $table->primary(['hotel_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->ulid('model_id');
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();

            $table->index(['model_id', 'model_type']);
            $table->primary(['hotel_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
