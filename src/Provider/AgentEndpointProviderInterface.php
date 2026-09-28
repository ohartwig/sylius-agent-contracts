<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;

/**
 * Announces the machine-readable endpoints a bundle serves.
 *
 * DI tag: koh_agent.endpoint (applied automatically via autoconfiguration).
 */
interface AgentEndpointProviderInterface
{
    /** @return iterable<AgentEndpoint> */
    public function getAgentEndpoints(AgentContext $context): iterable;
}
