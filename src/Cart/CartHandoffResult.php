<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Cart;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * What {@see CartHandoff::handOver()} did.
 *
 * `$refusal` is the exception the gate threw. Its message is written by the
 * installation and is not necessarily meant for buyers: show your own notice
 * and use the message for logs.
 */
final readonly class CartHandoffResult
{
    private function __construct(
        public CartHandoffOutcome $outcome,
        public ?OrderInterface $cart,
        public int $linesAdded,
        public ?\Throwable $refusal,
    ) {
    }

    public static function added(OrderInterface $cart, int $lines): self
    {
        return new self(CartHandoffOutcome::Added, $cart, $lines, null);
    }

    public static function refused(OrderInterface $cart, \Throwable $refusal): self
    {
        return new self(CartHandoffOutcome::Refused, $cart, 0, $refusal);
    }

    public static function nothingAdded(): self
    {
        return new self(CartHandoffOutcome::NothingAdded, null, 0, null);
    }

    public function isAdded(): bool
    {
        return CartHandoffOutcome::Added === $this->outcome;
    }

    public function isRefused(): bool
    {
        return CartHandoffOutcome::Refused === $this->outcome;
    }

    public function refusalMessage(): ?string
    {
        return $this->refusal?->getMessage();
    }
}
