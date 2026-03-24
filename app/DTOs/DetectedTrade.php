<?php

namespace App\DTOs;

class DetectedTrade
{
    public function __construct(
        public readonly string $tradeId,
        public readonly string $wallet,
        public readonly string $assetId,
        public readonly string $side,
        public readonly float $price,
        public readonly float $size,
        public readonly int $timestamp = 0,
        public readonly array $raw = [],
    ) {}
}
