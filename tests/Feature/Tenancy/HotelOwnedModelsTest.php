<?php

declare(strict_types=1);

use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Messaging\Email\Models\EmailMessage;
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
    // Scoped by the permission package's team ID (kept equal to the current
    // hotel by TenantContext) and always queried by hotel in HotelRoles.
    Role::class,
    // The email log includes platform emails (no hotel); hotel pages filter it explicitly.
    EmailMessage::class,
    // Invoices are issued by the platform (dunning and revenue read across
    // hotels); hotel pages always filter by the current hotel explicitly.
    App\Domains\Billing\Models\Invoice::class,
    // Sign-up records belong to a user (the hotel is created after the email
    // check) and are read only by the platform.
    App\Domains\Onboarding\Models\PendingSignup::class,
    App\Domains\Onboarding\Models\LegalAcceptance::class,
    // Platform-only notes about a hotel; never read in a hotel's context.
    App\Domains\Platform\Models\HotelNote::class,
    // A hotel's closure record outlives the hotel's data; platform-read only.
    App\Domains\Platform\Lifecycle\HotelClosure::class,
    // Support sessions decide which hotel a platform user enters, so they
    // are read before any hotel is selected; hotel pages filter explicitly.
    App\Domains\Platform\Support\SupportSession::class,
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
