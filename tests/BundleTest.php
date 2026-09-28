<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\KohSyliusAgentContractsBundle;
use Koh\SyliusAgentContracts\Model\LlmsTxtLink;
use Koh\SyliusAgentContracts\Model\LlmsTxtSection;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Compiles a container with the bundle in it.
 *
 * The wiring is where this package can actually break — service ids that moved
 * in a Sylius release, a tagged_iterator that resolves to nothing, or
 * autoconfiguration that never fires. Unit tests on the value objects would not
 * notice any of it.
 */
final class BundleTest extends TestCase
{
    public function testContainerCompilesAndRegistriesAreWired(): void
    {
        $container = $this->compile();

        self::assertTrue($container->has(LlmsTxtSectionRegistry::class));
        self::assertTrue($container->has(AgentJsonResponder::class));
    }

    /**
     * The whole point of registering autoconfiguration in build(): a provider
     * declared outside this bundle — in App\, in a sibling bundle — is tagged
     * without anyone wiring it.
     */
    public function testProvidersOutsideThisBundleAreAutoconfigured(): void
    {
        $container = $this->compile(static function (ContainerBuilder $c): void {
            $c->setDefinition('app.magazine_section', (new Definition(AppOwnedSectionProvider::class))
                ->setAutoconfigured(true)
                ->setPublic(true));
        });

        self::assertTrue(
            $container->getDefinition('app.magazine_section')->hasTag('koh_agent.llms_txt_section'),
            'A provider defined outside the bundle must be tagged by container-wide autoconfiguration.',
        );

        $sections = $container->get(LlmsTxtSectionRegistry::class)->all($this->context());

        self::assertCount(1, $sections);
        self::assertSame('Magazin', $sections[0]->title);
    }

    /**
     * AbstractBundle resolves config/ relative to getPath(), and this package
     * keeps config/ at the package root rather than under src/. If the override
     * ever went away the default would point at src/, loadExtension()'s
     * '../config/services.php' would resolve outside the package, and the
     * failure would surface as an unrelated "service not found" in a consumer.
     */
    public function testTheBundlePathIsThePackageRootWhereConfigActuallyLives(): void
    {
        $path = (new KohSyliusAgentContractsBundle())->getPath();

        self::assertFileExists($path.'/config/services.php');
        self::assertSame(\dirname(__DIR__), $path);
    }

    public function testConfiguredSchemeReachesTheParameters(): void
    {
        $container = $this->compile(config: ['scheme' => 'http', 'cache' => ['max_age' => 60]]);

        self::assertSame('http', $container->getParameter('koh_agent.scheme'));
        self::assertSame(60, $container->getParameter('koh_agent.cache.max_age'));
        self::assertFalse($container->getParameter('koh_agent.trust_request_host'));
    }

    /** @param array<string, mixed> $config */
    private function compile(?callable $decorate = null, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', \dirname(__DIR__));

        $bundle = new KohSyliusAgentContractsBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('koh_agent', $config);

        // The Sylius services the factory depends on — stubbed, so this test
        // does not need the whole Sylius bundle stack booted.
        foreach ([
            'sylius.context.channel' => ChannelContextInterface::class,
            'sylius.repository.channel' => ChannelRepositoryInterface::class,
            'sylius.context.locale' => LocaleContextInterface::class,
        ] as $id => $class) {
            $container->setDefinition($id, (new Definition($class))->setSynthetic(true));
        }
        $container->setDefinition('request_stack', new Definition(RequestStack::class));

        if (null !== $decorate) {
            $decorate($container);
        }

        $container->compile();

        return $container;
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}

final class AppOwnedSectionProvider implements LlmsTxtSectionProviderInterface
{
    public function getLlmsTxtSection(AgentContext $context): ?LlmsTxtSection
    {
        return new LlmsTxtSection('Magazin', [new LlmsTxtLink('Beitrag', $context->url('/magazin/beitrag'))], 80);
    }
}
