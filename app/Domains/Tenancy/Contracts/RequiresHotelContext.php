<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Marker for queued jobs that work on hotel-owned data. Dispatching one
 * without a current hotel throws; when it runs, the hotel it was dispatched
 * for is restored automatically (see TenancyServiceProvider).
 */
interface RequiresHotelContext {}
