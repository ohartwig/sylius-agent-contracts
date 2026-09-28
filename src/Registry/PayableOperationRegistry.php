<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;

final class PayableOperationRegistry
{
    /** @param iterable<PayableOperationProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return list<PayableOperation> */
    public function all(AgentContext $context): array
    {
        $operations = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getPayableOperations($context) as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }
}
