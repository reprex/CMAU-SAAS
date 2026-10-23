<?php

namespace App\Pricing;

/**
 * TOD's "qty:price" break strings, e.g. "25:4.1,50:3.8,100:3.55".
 * Used for cost and price strings and inside DecorationJSON.
 */
final class PriceString
{
    /** Returned for an unreadable string, as TOD's GetPrice does. */
    public const INVALID_PRICE = 99999.0;

    /** @param  list<array{qty: int, price: float}>  $breaks  sorted by qty */
    private function __construct(private readonly array $breaks) {}

    /** @param  iterable<array{qty: int|string, price: float|int|string}>  $breaks */
    public static function fromBreaks(iterable $breaks): self
    {
        $list = [];
        foreach ($breaks as $break) {
            $qty = (int) $break['qty'];
            if ($qty === 0) {
                continue; // TOD's concat query skips zero-quantity breaks
            }
            $list[] = ['qty' => $qty, 'price' => (float) $break['price']];
        }
        usort($list, fn ($a, $b) => $a['qty'] <=> $b['qty']);

        return new self($list);
    }

    public static function parse(?string $text): self
    {
        $list = [];
        foreach (explode(',', (string) $text) as $part) {
            if (! str_contains($part, ':')) {
                continue;
            }
            [$qty, $price] = explode(':', $part, 2);
            $list[] = ['qty' => (int) trim($qty), 'price' => (float) trim($price)];
        }

        return self::fromBreaks($list);
    }

    /** @return list<array{qty: int, price: float}> */
    public function breaks(): array
    {
        return $this->breaks;
    }

    public function isEmpty(): bool
    {
        return $this->breaks === [];
    }

    /** Smallest quantity in the string (TOD reads this as the MOQ). */
    public function minQty(): ?int
    {
        return $this->breaks[0]['qty'] ?? null;
    }

    /**
     * Unit price for a quantity, exactly as TOD's GetPrice():
     *  - the price of the largest break at or below the quantity;
     *  - below the smallest break, the minimum-quantity total spread over
     *    the units ordered: (minQty x minPrice) / qty;
     *  - 0 when the string or quantity is empty.
     */
    public function priceAt(int $qty): float
    {
        if ($qty === 0 || $this->breaks === []) {
            return 0.0;
        }

        $min = $this->breaks[0];
        if ($qty < $min['qty']) {
            return Money::currency(($min['qty'] * $min['price']) / $qty);
        }

        $found = $min['price'];
        foreach ($this->breaks as $break) {
            if ($break['qty'] <= $qty) {
                $found = $break['price'];
            }
        }

        return $found;
    }

    /** Apply a function to every price (e.g. markup + rounding). */
    public function map(callable $fn): self
    {
        return new self(array_map(fn ($b) => ['qty' => $b['qty'], 'price' => $fn($b['price'])], $this->breaks));
    }

    public function toString(): string
    {
        return implode(',', array_map(
            fn ($b) => $b['qty'].':'.Money::format($b['price']),
            $this->breaks,
        ));
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
