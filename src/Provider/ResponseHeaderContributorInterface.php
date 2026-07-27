<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contributes response headers through the one shared kernel.response listener.
 *
 * Deliberately not "every bundle registers its own listener": Link,
 * Content-Signal, Vary and CORS all end up on the same response, and independent
 * listeners overwrite each other's work non-deterministically.
 *
 * Implementations must add, never replace — see AgentResponseHeaderListener for
 * the merge rules it enforces.
 *
 * DI tag: koh_agent.response_header.
 */
interface ResponseHeaderContributorInterface
{
    public function supports(Request $request, Response $response): bool;

    public function contribute(Request $request, Response $response, AgentContext $context): void;
}
