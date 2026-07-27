<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;

final class AgentSkillRegistry
{
    /** @param iterable<AgentSkillProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * Keyed by skill id — two bundles exposing the same capability must not
     * produce a duplicate entry in the A2A card or the skills index.
     *
     * @return list<AgentSkill>
     */
    public function all(AgentContext $context): array
    {
        $skills = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getAgentSkills($context) as $skill) {
                $skills[$skill->id] = $skill;
            }
        }

        ksort($skills);

        return array_values($skills);
    }
}
