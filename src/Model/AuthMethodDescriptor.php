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
 *
 * The `agent_auth` block follows auth.md v0.6.0 (github.com/workos/auth.md,
 * AUTH.md Step 1b): `skill`, `identity_endpoint`, `claim_endpoint`,
 * `events_endpoint`, `identity_types_supported`,
 * `identity_assertion.assertion_types_supported` and `events_supported`.
 * Revocation is not part of the block: it is the RFC 8414/7009
 * `revocation_endpoint` at the top level of the metadata.
 *
 * `registerUri`, `registrationMethods`, `credentialTypes`, `revocationUri` and
 * `claimUri` are the names of an earlier draft. They are still accepted and
 * readable (the auth.md prose may use them) but no longer emitted into
 * `agent_auth`: no auth.md version defines them, and an agent reading them
 * would look for endpoints the profile does not have.
 */
final readonly class AuthMethodDescriptor
{
    /** The registration types auth.md v0.6.0 defines for `identity_types_supported`. */
    public const IDENTITY_TYPES = ['anonymous', 'identity_assertion', 'service_auth'];

    /**
     * @param string       $id                  'oauth2' | 'x402' | 'passkey' | …
     * @param string       $documentation       Markdown section rendered into /auth.md
     * @param ?string      $registerUri         deprecated, not emitted; use $identityEndpoint
     * @param ?string      $skillUri            absolute URL of the auth.md skill document
     * @param list<string> $registrationMethods deprecated, not emitted
     * @param list<string> $identityTypes       subset of {@see self::IDENTITY_TYPES}; only
     *                                          list a type the identity endpoint accepts
     * @param list<string> $credentialTypes     deprecated, not emitted
     * @param ?string      $revocationUri       deprecated, not emitted; RFC 8414 `revocation_endpoint`
     *                                          belongs at the top level of the metadata
     * @param ?string      $claimUri            deprecated, not emitted; use $claimEndpoint
     * @param ?string      $identityEndpoint    `agent_auth.identity_endpoint`: where an agent POSTs to register
     * @param ?string      $claimEndpoint       `agent_auth.claim_endpoint`: where a claim ceremony starts
     * @param ?string      $eventsEndpoint      `agent_auth.events_endpoint`: RFC 8935 SET push receiver
     * @param list<string> $assertionTypes      `agent_auth.identity_assertion.assertion_types_supported`
     * @param list<string> $eventsSupported     `agent_auth.events_supported`: event schema URIs
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
        public ?string $identityEndpoint = null,
        public ?string $claimEndpoint = null,
        public ?string $eventsEndpoint = null,
        public array $assertionTypes = [],
        public array $eventsSupported = [],
    ) {
        $unknown = array_diff($identityTypes, self::IDENTITY_TYPES);
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown auth.md identity type(s) %s; identity_types_supported is a subset of %s.',
                implode(', ', $unknown),
                implode(', ', self::IDENTITY_TYPES),
            ));
        }
        if ([] !== $assertionTypes && !\in_array('identity_assertion', $identityTypes, true)) {
            throw new \InvalidArgumentException('assertionTypes needs identity_assertion among the identity types.');
        }
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
            'identity_endpoint' => $this->identityEndpoint,
            'claim_endpoint' => $this->claimEndpoint,
            'events_endpoint' => $this->eventsEndpoint,
        ] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $out[$key] = $value;
            }
        }

        if ([] !== $this->identityTypes) {
            $out['identity_types_supported'] = $this->identityTypes;
        }
        if ([] !== $this->assertionTypes) {
            $out['identity_assertion'] = ['assertion_types_supported' => $this->assertionTypes];
        }
        if ([] !== $this->eventsSupported) {
            $out['events_supported'] = $this->eventsSupported;
        }

        return $out;
    }
}
