<?php

declare(strict_types=1);

namespace App\Domains\Rates\Coupons;

use RuntimeException;

/** The code cannot be used for this stay; the message says why, for the guest. */
final class CouponNotValid extends RuntimeException {}
