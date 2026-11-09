<?php

declare(strict_types=1);

use App\Domains\Launch\LaunchChecklist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_items', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('area', 20);
            $table->json('title');
            $table->boolean('required')->default(true);
            $table->string('owner_role', 20); // platform role in charge
            $table->string('status', 10)->default('open'); // open, done, not_needed
            $table->text('proof')->nullable(); // link or note
            $table->foreignUlid('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        foreach (LaunchChecklist::ITEMS as $i => [$key, $area, $fr, $en, $required, $role]) {
            DB::table('launch_items')->insert(['key' => $key, 'area' => $area, 'title' => json_encode(['fr' => $fr, 'en' => $en]), 'required' => $required, 'owner_role' => $role, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_items');
    }
};
