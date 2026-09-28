<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;

/**
 * Contributes entries to the RFC 9727 linkset and the Link response headers.
 *
 * DI tag: koh_agent.api_catalog_entry.
 */
interface ApiCatalogEntryProviderInterface
{
    /** @return iterable<ApiCatalogEntry> */
    public function getApiCatalogEntries(AgentContext $context): iterable;
}
