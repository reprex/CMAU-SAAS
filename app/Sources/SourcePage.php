<?php

namespace App\Sources;

final class SourcePage
{
    /**
     * @param  list<SourceProduct>  $products
     */
    public function __construct(
        public readonly int $page,
        public readonly int $totalPages,
        public readonly array $products,
    ) {}

    public function isLast(): bool
    {
        return $this->page >= $this->totalPages;
    }
}
