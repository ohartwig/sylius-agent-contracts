<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactory;
use Koh\SyliusAgentContracts\EventListener\AgentResponseHeaderListener;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Koh\SyliusAgentContracts\Registry\AuthMethodRegistry;
use Koh\SyliusAgentContracts\Registry\LlmsTxtSectionRegistry;
use Koh\SyliusAgentContracts\Registry\PayableOperationRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Public: sibling bundles autowire these by type, and the dump commands
    // resolve them from the container in CLI context.
    $services->set(AgentContextFactory::class)
        ->args([
            service('sylius.context.channel'),
            service('sylius.repository.channel'),
            service('sylius.context.locale'),
            service('request_stack'),
            param('koh_agent.scheme'),
            param('koh_agent.trust_request_host'),
        ])
        ->public();

    $services->set(AgentJsonResponder::class)
        ->args([
            param('koh_agent.cache.max_age'),
            param('koh_agent.cache.stale_while_revalidate'),
        ])
        ->public();

    $registries = [
        AgentEndpointRegistry::class => 'koh_agent.endpoint',
        ApiCatalogRegistry::class => 'koh_agent.api_catalog_entry',
        AgentSkillRegistry::class => 'koh_agent.skill',
        LlmsTxtSectionRegistry::class => 'koh_agent.llms_txt_section',
        AuthMethodRegistry::class => 'koh_agent.auth_method',
        PayableOperationRegistry::class => 'koh_agent.payable_operation',
    ];

    foreach ($registries as $class => $tag) {
        $services->set($class)
            ->args([tagged_iterator($tag)])
            ->public();
    }

    $services->set(AgentResponseHeaderListener::class)
        ->args([
            tagged_iterator('koh_agent.response_header'),
            service(AgentContextFactory::class),
        ])
        ->tag('kernel.event_listener', [
            'event' => 'kernel.response',
            'method' => '__invoke',
            'priority' => -100,
        ]);
};
