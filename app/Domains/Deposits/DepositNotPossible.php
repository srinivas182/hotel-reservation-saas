<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use DomainException;

/** A deposit action that cannot happen; the message is for people. */
final class DepositNotPossible extends DomainException {}
