<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Model\AuthMethodDescriptor;
use Koh\SyliusAgentContracts\Model\LlmsTxtLink;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Provider\AuthMethodDescriptorInterface;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentContracts\Registry\AuthMethodRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

final class RegistryTest extends TestCase
{
    public function testSectionsSortByDescendingPriority(): void
    {
        $registry = new LlmsTxtSectionRegistry([
            $this->sectionProvider('Optional', -100),
            $this->sectionProvider('Kategorien', 100),
            $this->sectionProvider('Magazin', 80),
        ]);

        $titles = array_map(
            static fn (LlmsTxtSection $s): string => $s->title,
            $registry->all($this->context()),
        );

        self::assertSame(['Kategorien', 'Magazin', 'Optional'], $titles);
    }

    /** Equal priorities must keep registration order, or the document reshuffles between deploys. */
    public function testEqualPrioritiesAreStable(): void
    {
        $registry = new LlmsTxtSectionRegistry([
            $this->sectionProvider('Erste', 50),
            $this->sectionProvider('Zweite', 50),
            $this->sectionProvider('Dritte', 50),
        ]);

        $titles = array_map(
            static fn (LlmsTxtSection $s): string => $s->title,
            $registry->all($this->context()),
        );

        self::assertSame(['Erste', 'Zweite', 'Dritte'], $titles);
    }

    /** Two bundles exposing the same capability must not double up in the A2A card. */
    public function testSkillsAreDeduplicatedById(): void
    {
        $registry = new AgentSkillRegistry([
            $this->skillProvider(new AgentSkill('product-search', 'Product Search', 'a')),
            $this->skillProvider(new AgentSkill('product-search', 'Product Search', 'b')),
        ]);

        self::assertCount(1, $registry->all($this->context()));
    }

    /**
     * Two methods that each accept a different registration type must
     * advertise both, without either bundle knowing the other exists.
     */
    public function testAgentAuthUnionsListsAcrossMethods(): void
    {
        $registry = new AuthMethodRegistry([
            $this->authProvider(new AuthMethodDescriptor(
                id: 'oauth2',
                title: 'OAuth 2.1',
                documentation: '',
                skillUri: 'https://shop.example.com/auth.md',
                identityTypes: ['anonymous'],
                identityEndpoint: 'https://shop.example.com/mcp/oauth/agent/identity',
            )),
            $this->authProvider(new AuthMethodDescriptor(
                id: 'id-jag',
                title: 'ID-JAG',
                documentation: '',
                skillUri: 'https://other.example.com/auth.md',
                identityTypes: ['identity_assertion'],
                identityEndpoint: 'https://other.example.com/identity',
                assertionTypes: ['urn:ietf:params:oauth:token-type:id-jag'],
            )),
            $this->authProvider(new AuthMethodDescriptor(
                id: 'id-jag-2',
                title: 'ID-JAG again',
                documentation: '',
                identityTypes: ['identity_assertion'],
                assertionTypes: ['urn:ietf:params:oauth:token-type:id-jag', 'urn:example:other'],
            )),
        ]);

        self::assertSame([
            'skill' => 'https://shop.example.com/auth.md',
            'identity_endpoint' => 'https://shop.example.com/mcp/oauth/agent/identity',
            'identity_types_supported' => ['anonymous', 'identity_assertion'],
            'identity_assertion' => [
                'assertion_types_supported' => ['urn:ietf:params:oauth:token-type:id-jag', 'urn:example:other'],
            ],
        ], $registry->toAgentAuth($this->context()));
    }

    public function testNoMethodsMeansNoAgentAuth(): void
    {
        self::assertSame([], (new AuthMethodRegistry([]))->toAgentAuth($this->context()));
    }

    private function sectionProvider(string $title, int $priority): LlmsTxtSectionProviderInterface
    {
        return new class($title, $priority) implements LlmsTxtSectionProviderInterface {
            public function __construct(private string $title, private int $priority)
            {
            }

            public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
            {
                return new LlmsTxtSection($this->title, [new LlmsTxtLink('x', '/x')], $this->priority);
            }
        };
    }

    private function skillProvider(AgentSkill $skill): AgentSkillProviderInterface
    {
        return new class($skill) implements AgentSkillProviderInterface {
            public function __construct(private AgentSkill $skill)
            {
            }

            public function getAgentSkills(AgentContext $context): iterable
            {
                yield $this->skill;
            }
        };
    }

    private function authProvider(AuthMethodDescriptor $descriptor): AuthMethodDescriptorInterface
    {
        return new class($descriptor) implements AuthMethodDescriptorInterface {
            public function __construct(private AuthMethodDescriptor $descriptor)
            {
            }

            public function getAuthMethodDescriptor(AgentContext $context): ?AuthMethodDescriptor
            {
                return $this->descriptor;
            }
        };
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
