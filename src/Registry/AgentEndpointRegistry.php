<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;

final class AgentEndpointRegistry
{
    /** @param iterable<AgentEndpointProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return list<AgentEndpoint> */
    public function all(AgentContext $context): array
    {
        $endpoints = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getAgentEndpoints($context) as $endpoint) {
                $endpoints[$endpoint->path] = $endpoint;
            }
        }

        ksort($endpoints);

        return array_values($endpoints);
    }
}
