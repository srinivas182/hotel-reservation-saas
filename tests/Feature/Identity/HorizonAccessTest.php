<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('opens Horizon only to platform super admins', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $staff = User::factory()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('viewHorizon'))->toBeFalse();
});
