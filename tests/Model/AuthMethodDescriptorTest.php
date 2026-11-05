<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Model;

use Koh\SyliusAgentContracts\Model\AuthMethodDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `agent_auth` fragment uses the field names of auth.md v0.6.0 (AUTH.md
 * Step 1b) and nothing else: an agent follows every endpoint it finds there.
 */
final class AuthMethodDescriptorTest extends TestCase
{
    public function testTheFragmentUsesTheAuthMdV06Names(): void
    {
        $fragment = (new AuthMethodDescriptor(
            id: 'x',
            title: 'X',
            documentation: '',
            skillUri: 'https://shop.example.com/auth.md',
            identityTypes: ['anonymous', 'identity_assertion', 'service_auth'],
            identityEndpoint: 'https://shop.example.com/agent/identity',
            claimEndpoint: 'https://shop.example.com/agent/identity/claim',
            eventsEndpoint: 'https://shop.example.com/agent/event/notify',
            assertionTypes: ['urn:ietf:params:oauth:token-type:id-jag'],
            eventsSupported: ['https://schemas.workos.com/events/agent/auth/identity/assertion/revoked'],
        ))->toAgentAuthFragment();

        self::assertSame([
            'skill' => 'https://shop.example.com/auth.md',
            'identity_endpoint' => 'https://shop.example.com/agent/identity',
            'claim_endpoint' => 'https://shop.example.com/agent/identity/claim',
            'events_endpoint' => 'https://shop.example.com/agent/event/notify',
            'identity_types_supported' => ['anonymous', 'identity_assertion', 'service_auth'],
            'identity_assertion' => ['assertion_types_supported' => ['urn:ietf:params:oauth:token-type:id-jag']],
            'events_supported' => ['https://schemas.workos.com/events/agent/auth/identity/assertion/revoked'],
        ], $fragment);
    }

    /**
     * The draft names stay constructible for the prose of /auth.md but never
     * reach the metadata; empty values are left out rather than sent empty.
     */
    public function testDeprecatedAndEmptyFieldsAreNotEmitted(): void
    {
        $descriptor = new AuthMethodDescriptor(
            id: 'x',
            title: 'X',
            documentation: '',
            registerUri: 'https://shop.example.com/register',
            skillUri: '',
            registrationMethods: ['dynamic'],
            credentialTypes: ['oauth2_access_token'],
            revocationUri: 'https://shop.example.com/revoke',
            claimUri: 'https://shop.example.com/claim',
            identityEndpoint: '',
        );

        self::assertSame([], $descriptor->toAgentAuthFragment());
        self::assertSame('https://shop.example.com/register', $descriptor->registerUri);
        self::assertSame('https://shop.example.com/revoke', $descriptor->revocationUri);
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'verified_email was removed in v0.6.0' => [['verified_email'], []];
        yield 'not an auth.md type' => [['anonymous', 'delegated_user'], []];
        yield 'assertion types without identity_assertion' => [['anonymous'], ['urn:ietf:params:oauth:token-type:id-jag']];
    }

    /**
     * @param list<string> $identityTypes
     * @param list<string> $assertionTypes
     */
    #[DataProvider('invalidDeclarations')]
    public function testItRefusesADeclarationOutsideTheProfile(array $identityTypes, array $assertionTypes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuthMethodDescriptor(
            id: 'x',
            title: 'X',
            documentation: '',
            identityTypes: $identityTypes,
            assertionTypes: $assertionTypes,
        );
    }

    public function testTheMessageNamesTheUnknownType(): void
    {
        $this->expectExceptionMessage('Unknown auth.md identity type(s) verified_email; identity_types_supported is a subset of anonymous, identity_assertion, service_auth.');

        new AuthMethodDescriptor(id: 'x', title: 'X', documentation: '', identityTypes: ['verified_email']);
    }
}
