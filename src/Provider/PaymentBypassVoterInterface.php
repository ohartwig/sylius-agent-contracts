<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lets a caller past an x402 paywall without paying.
 *
 * Lives here rather than in the x402 bundle so that the *granting* package
 * implements it: an OAuth bundle knows what a valid token means, a bot-auth
 * bundle knows what a valid signature means, and neither has to be a dependency
 * of the payment bundle.
 *
 * DI tag: koh_agent.payment_bypass.
 */
interface PaymentBypassVoterInterface
{
    public function shouldBypass(Request $request): bool;
}
