<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;

/**
 * Contributes one section to llms.txt.
 *
 * Application-owned entities (magazine articles, custom content) implement this
 * in the application itself — autoconfiguration is registered container-wide, so
 * a service in App\ is picked up without any bundle wiring.
 *
 * DI tag: koh_agent.llms_txt_section.
 */
interface LlmsTxtSectionProviderInterface
{
    /** Return null to contribute nothing for this channel/locale. */
    public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection;
}
