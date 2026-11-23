<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * Recognises a guest asking to negotiate the price ("50% de réduction ?",
 * "a special price, 20000?"). Only staff may agree to that, so the
 * conversation goes to them whatever the AI decided.
 *
 * Not a negotiation: a discount code ("j'ai un code promo", "coupon"), or
 * the pay-now price the hotel already offers ("is it cheaper if I pay
 * online?"). Those are answered by get_quote.
 */
final class DiscountRequest
{
    private const ASKS = '/\b(discount|special (price|rate|deal)|better (price|rate|deal)|best (price|rate)|cheaper|lower (the )?(price|rate)|reduce (the )?(price|rate)|negotiat\w*|\d+\s?% off|réduction|remise|rabais|ristourne|geste commercial|prix (spécial|d\'ami|cassé)|meilleur prix|moins cher|baisser (le|votre) prix|négoci\w*|faire un (petit )?prix)\b|\b\d+\s?%/iu';

    private const NOT_NEGOTIATION = '/\b(code|coupon|promo|voucher|bon de réduction|pay(ing)? (now|online|in advance)|payer (maintenant|en ligne|d\'avance|à l\'avance)|paiement (en ligne|immédiat))\b/iu';

    public static function asks(string $message): bool
    {
        return preg_match(self::ASKS, $message) === 1 && preg_match(self::NOT_NEGOTIATION, $message) !== 1;
    }
}
