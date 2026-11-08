<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('shows checks and failed jobs, and retries or discards them', function (): void {
    foreach (['a', 'b'] as $id) {
        DB::table('failed_jobs')->insert(['uuid' => "uuid-{$id}", 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Example', 'job' => 'x', 'data' => []]), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);
    }
    $ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();

    $this->actingAs($ops)->get(route('platform.operations'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('failed', 2)->where('failed.0.error', 'RuntimeException: boom')->has('problems')->has('heartbeats', 2));

    $this->actingAs($ops)->delete(route('platform.operations.forget', 'uuid-a'))->assertRedirect();
    expect(DB::table('failed_jobs')->where('uuid', 'uuid-a')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'ops.job_discarded')->exists())->toBeTrue();

    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create())->get(route('platform.operations'))->assertForbidden();
});
