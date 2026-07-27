<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;

/**
 * Describes capabilities once so A2A, agent-skills and MCP can each project them.
 *
 * DI tag: koh_agent.skill.
 */
interface AgentSkillProviderInterface
{
    /** @return iterable<AgentSkill> */
    public function getAgentSkills(AgentContext $context): iterable;
}
