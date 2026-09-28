<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * One way an agent can obtain credentials for this shop.
 *
 * Single source for /auth.md *and* the `agent_auth` block of the RFC 8414
 * authorization-server metadata. Rendering those from two places is how the
 * prose and the machine-readable metadata drift apart.
 */
final readonly class AuthMethodDescriptor
{
    /**
     * @param string       $id                    'oauth2' | 'x402' | 'passkey' | …
     * @param ?string      $registerUri           absolute URL of the registration endpoint
     * @param ?string      $skillUri              absolute URL of the machine-readable skill document
     * @param list<string> $registrationMethods   e.g. ['dynamic']
     * @param list<string> $identityTypes         e.g. ['identity_assertion'], ['anonymous']
     * @param list<string> $credentialTypes       e.g. ['oauth2_access_token', 'http_message_signature']
     * @param ?string      $revocationUri
     * @param ?string      $claimUri
     * @param string       $documentation         Markdown section rendered into /auth.md
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $documentation,
        public ?string $registerUri = null,
        public ?string $skillUri = null,
        public array $registrationMethods = [],
        public array $identityTypes = [],
        public array $credentialTypes = [],
        public ?string $revocationUri = null,
        public ?string $claimUri = null,
    ) {
    }

    /**
     * The `agent_auth` fragment this method contributes.
     *
     * @return array<string, mixed>
     */
    public function toAgentAuthFragment(): array
    {
        $out = [];

        foreach ([
            'skill' => $this->skillUri,
            'register_uri' => $this->registerUri,
            'revocation_uri' => $this->revocationUri,
            'claim_uri' => $this->claimUri,
        ] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $out[$key] = $value;
            }
        }

        foreach ([
            'registration_methods_supported' => $this->registrationMethods,
            'identity_types_supported' => $this->identityTypes,
            'credential_types_supported' => $this->credentialTypes,
        ] as $key => $value) {
            if ([] !== $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
