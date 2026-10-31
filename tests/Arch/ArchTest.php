<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| These rules protect the codebase conventions agreed in the ADRs.
| Tenancy-specific rules (BelongsToHotel on hotel-owned models) are
| added in S0.2.
|
*/

arch('no debugging statements are left in the code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('application code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('controllers stay thin and are suffixed')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->ignoring('App\Http\Controllers\Concerns');

arch('models extend Eloquent')
    ->expect('App\Models')
    ->toExtend(Illuminate\Database\Eloquent\Model::class);

arch('env() is only called from config files')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('config');
