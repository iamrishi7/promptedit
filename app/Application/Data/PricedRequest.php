<?php

declare(strict_types=1);

namespace App\Application\Data;

use App\Domain\Billing\Money;

/**
 * The price of a request for some number of images (each image is its own generation with the
 * same estimate), and the prompt lengths for which the price shown, rounded outward to cents,
 * stays exactly the same. A form can reuse it while the user types within that range.
 */
final readonly class PricedRequest
{
    public function __construct(
        public int $imageCount,
        public Money $minimumCharge,
        public Money $maximumCharge,
        public int $samePriceFromCharacters,
        public int $samePriceToCharacters,
    ) {}
}
