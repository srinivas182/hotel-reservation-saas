<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments;

use RuntimeException;

final class PaymentFailed extends RuntimeException {}
