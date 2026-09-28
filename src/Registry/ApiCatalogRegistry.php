<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

final class ApiCatalogRegistry
{
    /** @param iterable<ApiCatalogEntryProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return list<ApiCatalogEntry> */
    public function all(AgentContext $context): array
    {
        $entries = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getApiCatalogEntries($context) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
