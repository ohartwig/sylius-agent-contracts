<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AuthMethodDescriptor;

/**
 * Describes one authentication path for /auth.md and the agent_auth metadata.
 *
 * DI tag: koh_agent.auth_method.
 */
interface AuthMethodDescriptorInterface
{
    public function getAuthMethodDescriptor(AgentContext $context): ?AuthMethodDescriptor;
}
