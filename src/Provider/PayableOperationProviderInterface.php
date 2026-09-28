<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;

/**
 * Declares operations an agent has to pay for (MPP declaration, x402 enforcement).
 *
 * DI tag: koh_agent.payable_operation.
 */
interface PayableOperationProviderInterface
{
    /** @return iterable<PayableOperation> */
    public function getPayableOperations(AgentContext $context): iterable;
}
