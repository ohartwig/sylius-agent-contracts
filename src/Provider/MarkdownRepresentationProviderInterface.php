<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders a route as Markdown for `Accept: text/markdown` negotiation.
 *
 * Implementations should build Markdown from entity data via a template rather
 * than converting rendered HTML — otherwise navigation, footer and cookie banner
 * end up in the agent-facing representation.
 *
 * DI tag: koh_agent.markdown.
 */
interface MarkdownRepresentationProviderInterface
{
    public function supports(Request $request): bool;

    /** Return null to fall through to the next provider (and ultimately to HTML). */
    public function render(Request $request, AgentContext $context): ?string;
}
