<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Domains\Tenancy\Models\HotelMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Every model whose table has a hotel_id column must use BelongsToHotel,
| unless it is on this explicit, reviewed allowlist.
*/
const HOTEL_SCOPING_ALLOWLIST = [
    // Membership rows are read across hotels to list a user's hotels.
    HotelMembership::class,
    // Audit rows include platform-level events (null hotel) and are queried explicitly.
    AuditLog::class,
];

it('scopes every model that has a hotel_id column', function (): void {
    $unscoped = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => 'App\\'.Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', ''])->toString())
        ->filter(fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! (new ReflectionClass($class))->isAbstract())
        ->reject(fn (string $class): bool => in_array($class, HOTEL_SCOPING_ALLOWLIST, true))
        ->filter(fn (string $class): bool => Schema::hasColumn((new $class)->getTable(), 'hotel_id'))
        ->reject(fn (string $class): bool => in_array(BelongsToHotel::class, class_uses_recursive($class), true))
        ->values()
        ->all();

    expect($unscoped)->toBe([]);
});
