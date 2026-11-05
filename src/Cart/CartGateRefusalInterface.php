<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Cart;

/**
 * Marks an exception as "a shop gate refused this item", as opposed to "the
 * shop broke".
 *
 * A listener on `sylius.cart_item_add` that refuses an item — an age gate, a
 * quantity rule, a region lock — throws; that is how the storefront's own
 * add-to-cart button learns about it too. Implement this interface on that
 * exception and {@see CartHandoff} reports the refusal to the buyer as a
 * notice, while every other exception keeps propagating as the error it is.
 *
 *     final class AgeNotConfirmedException extends \RuntimeException implements CartGateRefusalInterface {}
 *
 * Until an installation does this, any `\RuntimeException` still counts as a
 * refusal (see `koh_agent.cart_handoff.runtime_exception_is_refusal`).
 */
interface CartGateRefusalInterface extends \Throwable
{
}
