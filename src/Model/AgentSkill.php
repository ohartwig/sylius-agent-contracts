<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * One capability the shop offers an agent.
 *
 * Described once, projected three ways: A2A `skills[]`, the agent-skills
 * discovery index, and MCP `capabilities.tools`. Without this shared shape each
 * of those three documents would hand-maintain its own copy and they would drift.
 */
final readonly class AgentSkill
{
    /**
     * @param string       $id          stable identifier, e.g. 'product-search'
     * @param string       $type        agent-skills artifact type: 'skill-md' | 'archive'
     * @param ?string      $url         absolute URL of the skill artifact
     * @param ?string      $digest      'sha256:…'; leave null to have it computed from the served body
     * @param list<string> $tags
     * @param list<string> $inputModes
     * @param list<string> $outputModes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $type = 'skill-md',
        public ?string $url = null,
        public ?string $digest = null,
        public array $tags = [],
        public array $inputModes = ['text/plain'],
        public array $outputModes = ['application/json'],
    ) {
    }
}
