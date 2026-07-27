<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * One machine-readable endpoint a bundle serves.
 *
 * Registering it lets llms.txt list the endpoint and the api-catalog link it
 * without either package having to know which bundles are installed.
 */
final readonly class AgentEndpoint
{
    /**
     * @param string  $path        absolute path, e.g. '/.well-known/mcp/server-card.json'
     * @param ?string $rel         RFC 8288 relation for the Link header, if any
     * @param ?string $protocol    'mcp' | 'a2a' | 'ucp' | 'acp' | 'oauth2' | …
     */
    public function __construct(
        public string $path,
        public string $title,
        public string $contentType = 'application/json',
        public ?string $rel = null,
        public ?string $protocol = null,
        public ?string $description = null,
    ) {
    }
}
