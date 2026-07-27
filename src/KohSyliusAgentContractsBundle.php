<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts;

use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use Koh\SyliusAgentContracts\Provider\AuthMethodDescriptorInterface;
use Koh\SyliusAgentContracts\Provider\LlmsTxtSectionProviderInterface;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusAgentContracts\Provider\PayableOperationProviderInterface;
use Koh\SyliusAgentContracts\Provider\PaymentBypassVoterInterface;
use Koh\SyliusAgentContracts\Provider\ResponseHeaderContributorInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Shared contracts for the koh Sylius agent-readiness bundles.
 *
 * Serves no endpoint of its own. It exists because the granular
 * one-package-per-protocol split needs a place where api-catalog, agent-skills,
 * a2a and the Link headers can learn what the *other*, independently installable
 * bundles offer — without any of them depending on each other.
 */
final class KohSyliusAgentContractsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_agent';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Autoconfiguration registered here applies to the *whole* container, not
     * just this bundle. That is what lets an application-owned service in App\
     * (say, a magazine section for llms.txt) be picked up with no wiring at all,
     * and it keeps the nine _instanceof blocks from being copy-pasted into every
     * sibling bundle.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $tags = [
            ApiCatalogEntryProviderInterface::class => 'koh_agent.api_catalog_entry',
            AgentSkillProviderInterface::class => 'koh_agent.skill',
            AgentEndpointProviderInterface::class => 'koh_agent.endpoint',
            LlmsTxtSectionProviderInterface::class => 'koh_agent.llms_txt_section',
            MarkdownRepresentationProviderInterface::class => 'koh_agent.markdown',
            AuthMethodDescriptorInterface::class => 'koh_agent.auth_method',
            PayableOperationProviderInterface::class => 'koh_agent.payable_operation',
            ResponseHeaderContributorInterface::class => 'koh_agent.response_header',
            PaymentBypassVoterInterface::class => 'koh_agent.payment_bypass',
        ];

        foreach ($tags as $interface => $tag) {
            $container->registerForAutoconfiguration($interface)->addTag($tag);
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('scheme')
                    ->defaultValue('https')
                    ->info('Scheme for absolute URLs when the request cannot be trusted (TLS terminates at the ingress).')
                ->end()
                ->booleanNode('trust_request_host')
                    ->defaultFalse()
                    ->info('Derive the base URL from the request instead of Channel::getHostname(). Requires framework.trusted_proxies.')
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_age')->defaultValue(3600)->end()
                        ->integerNode('stale_while_revalidate')->defaultValue(86400)->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $cache = \is_array($config['cache'] ?? null) ? $config['cache'] : [];

        $builder->setParameter('koh_agent.scheme', $config['scheme'] ?? 'https');
        $builder->setParameter('koh_agent.trust_request_host', (bool) ($config['trust_request_host'] ?? false));
        $builder->setParameter('koh_agent.cache.max_age', (int) ($cache['max_age'] ?? 3600));
        $builder->setParameter('koh_agent.cache.stale_while_revalidate', (int) ($cache['stale_while_revalidate'] ?? 86400));

        $container->import('../config/services.php');
    }
}
