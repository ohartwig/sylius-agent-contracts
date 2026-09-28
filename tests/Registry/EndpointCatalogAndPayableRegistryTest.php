<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * The three registries that had no coverage. Each collects from independently
 * installable bundles that know nothing about each other, so the question in
 * every case is what happens when two of them collide — and the three answers
 * are deliberately different.
 */
final class EndpointCatalogAndPayableRegistryTest extends TestCase
{
    /** Announcing the same path twice would list the endpoint twice in llms.txt and the api-catalog. */
    public function testEndpointsAreDeduplicatedByPath(): void
    {
        $registry = new AgentEndpointRegistry([
            $this->endpointProvider(new AgentEndpoint('/llms.txt', 'llms.txt from bundle A')),
            $this->endpointProvider(new AgentEndpoint('/llms.txt', 'llms.txt from bundle B')),
        ]);

        $endpoints = $registry->all($this->context());

        self::assertCount(1, $endpoints);
        self::assertSame('llms.txt from bundle B', $endpoints[0]->title);
    }

    /**
     * Sorted by path so the published document is byte-identical between deploys
     * regardless of the order the container happened to wire the bundles in — an
     * llms.txt that reshuffles defeats every ETag and every diff.
     */
    public function testEndpointsAreSortedByPath(): void
    {
        $registry = new AgentEndpointRegistry([
            $this->endpointProvider(new AgentEndpoint('/llms.txt', 'llms.txt')),
            $this->endpointProvider(new AgentEndpoint('/.well-known/api-catalog', 'API catalog')),
            $this->endpointProvider(new AgentEndpoint('/.well-known/mcp/server-card.json', 'MCP server card')),
        ]);

        self::assertSame(
            ['/.well-known/api-catalog', '/.well-known/mcp/server-card.json', '/llms.txt'],
            array_map(static fn (AgentEndpoint $e): string => $e->path, $registry->all($this->context())),
        );
    }

    /** The result has to be a list; a gap in the keys breaks json_encode into an object. */
    public function testEndpointsComeBackAsAList(): void
    {
        $registry = new AgentEndpointRegistry([
            $this->endpointProvider(
                new AgentEndpoint('/z', 'Z'),
                new AgentEndpoint('/a', 'A'),
            ),
        ]);

        self::assertSame([0, 1], array_keys($registry->all($this->context())));
    }

    public function testEndpointsFromEveryProviderAreCollected(): void
    {
        $registry = new AgentEndpointRegistry([
            $this->endpointProvider(new AgentEndpoint('/llms.txt', 'llms.txt')),
            $this->endpointProvider(new AgentEndpoint('/.well-known/api-catalog', 'API catalog')),
        ]);

        self::assertCount(2, $registry->all($this->context()));
    }

    public function testNoEndpointProvidersYieldsAnEmptyList(): void
    {
        self::assertSame([], (new AgentEndpointRegistry([]))->all($this->context()));
    }

    /**
     * Catalog entries are *not* deduplicated, unlike endpoints: two bundles may
     * legitimately describe the same anchor with different service-desc targets,
     * and RFC 9727 allows repeated anchors.
     */
    public function testCatalogEntriesAreCollectedWithoutDeduplication(): void
    {
        $registry = new ApiCatalogRegistry([
            $this->catalogProvider(new ApiCatalogEntry('https://shop.example.com/api', title: 'From A')),
            $this->catalogProvider(new ApiCatalogEntry('https://shop.example.com/api', title: 'From B')),
        ]);

        $entries = $registry->all($this->context());

        self::assertCount(2, $entries);
        self::assertSame(['From A', 'From B'], array_map(
            static fn (ApiCatalogEntry $e): ?string => $e->title,
            $entries,
        ));
    }

    public function testNoCatalogProvidersYieldsAnEmptyList(): void
    {
        self::assertSame([], (new ApiCatalogRegistry([]))->all($this->context()));
    }

    /**
     * Payable operations must arrive in registration order and unfiltered. A
     * registry that silently dropped or reordered one would leave the x402 price
     * table and the advertised x-payment-info disagreeing about what a route costs.
     */
    public function testEveryPayableOperationSurvivesCollection(): void
    {
        $registry = new PayableOperationRegistry([
            $this->payableProvider(
                new PayableOperation('/api/agent/feed', amount: '2500', currency: 'EUR'),
                new PayableOperation('/api/agent/search', amount: '100', currency: 'EUR'),
            ),
            $this->payableProvider(new PayableOperation('/api/agent/quote')),
        ]);

        $operations = $registry->all($this->context());

        self::assertSame(
            ['/api/agent/feed', '/api/agent/search', '/api/agent/quote'],
            array_map(static fn (PayableOperation $o): string => $o->path, $operations),
        );
        self::assertSame(['2500', '100', null], array_map(
            static fn (PayableOperation $o): ?string => $o->amount,
            $operations,
        ));
    }

    /**
     * Two prices on the same path is a misconfiguration, but the registry is not
     * where it gets silently resolved: collapsing them here would pick a winner
     * by wiring order and charge a price nobody chose.
     */
    public function testCompetingPricesOnOnePathAreBothReported(): void
    {
        $registry = new PayableOperationRegistry([
            $this->payableProvider(new PayableOperation('/api/agent/feed', amount: '2500')),
            $this->payableProvider(new PayableOperation('/api/agent/feed', amount: '9900')),
        ]);

        self::assertSame(['2500', '9900'], array_map(
            static fn (PayableOperation $o): ?string => $o->amount,
            $registry->all($this->context()),
        ));
    }

    public function testNoPayableProvidersYieldsAnEmptyList(): void
    {
        self::assertSame([], (new PayableOperationRegistry([]))->all($this->context()));
    }

    /** The context has to reach the providers — prices and endpoints are channel-dependent. */
    public function testTheContextIsHandedToEveryProvider(): void
    {
        $context = $this->context();
        $seen = null;

        $provider = new class($seen) implements AgentEndpointProviderInterface {
            public function __construct(public ?AgentContext &$seen)
            {
            }

            public function getAgentEndpoints(AgentContext $context): iterable
            {
                $this->seen = $context;

                return [];
            }
        };

        (new AgentEndpointRegistry([$provider]))->all($context);

        self::assertSame($context, $seen);
    }

    private function endpointProvider(AgentEndpoint ...$endpoints): AgentEndpointProviderInterface
    {
        return new class($endpoints) implements AgentEndpointProviderInterface {
            /** @param list<AgentEndpoint> $endpoints */
            public function __construct(private array $endpoints)
            {
            }

            public function getAgentEndpoints(AgentContext $context): iterable
            {
                yield from $this->endpoints;
            }
        };
    }

    private function catalogProvider(ApiCatalogEntry ...$entries): ApiCatalogEntryProviderInterface
    {
        return new class($entries) implements ApiCatalogEntryProviderInterface {
            /** @param list<ApiCatalogEntry> $entries */
            public function __construct(private array $entries)
            {
            }

            public function getApiCatalogEntries(AgentContext $context): iterable
            {
                yield from $this->entries;
            }
        };
    }

    private function payableProvider(PayableOperation ...$operations): PayableOperationProviderInterface
    {
        return new class($operations) implements PayableOperationProviderInterface {
            /** @param list<PayableOperation> $operations */
            public function __construct(private array $operations)
            {
            }

            public function getPayableOperations(AgentContext $context): iterable
            {
                yield from $this->operations;
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
