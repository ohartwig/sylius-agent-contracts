<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Cart;

use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * One line an agent hands over: a variant the protocol bundle has already
 * resolved and checked, and how many of it.
 */
final readonly class CartHandoffLine
{
    public function __construct(
        public ProductVariantInterface $variant,
        public int $quantity,
    ) {
    }
}
