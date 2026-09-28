<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;

final class LlmsTxtSectionRegistry
{
    /** @param iterable<LlmsTxtSectionProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * Sorted by descending priority, stable within equal priorities so the
     * document does not reshuffle between deploys.
     *
     * @return list<LlmsTxtSection>
     */
    public function all(AgentContext $context): array
    {
        $sections = [];
        $index = 0;
        foreach ($this->providers as $provider) {
            $section = $provider->getLlmsTxtSection($context);
            if (null !== $section) {
                $sections[] = [$section->priority, $index++, $section];
            }
        }

        usort($sections, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $row): LlmsTxtSection => $row[2], $sections);
    }
}
