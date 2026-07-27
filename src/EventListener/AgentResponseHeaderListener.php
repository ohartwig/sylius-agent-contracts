<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Provider\ResponseHeaderContributorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The single kernel.response listener for the whole package family.
 *
 * Link (api-catalog), Content-Signal (robots-txt), Vary (markdown) and CORS all
 * land on the same response. With one listener per bundle they overwrite each
 * other depending on registration order; here the merge rules are enforced in
 * one place:
 *
 *   - Link is appended, never replaced — Sylius and API Platform set their own
 *     (rel="preload", Hydra apiDocumentation) and clobbering them breaks the app.
 *   - Vary is merged, never replaced.
 *
 * Runs late (priority -100) so contributors see the final content type.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -100)]
final class AgentResponseHeaderListener
{
    /** @param iterable<ResponseHeaderContributorInterface> $contributors */
    public function __construct(
        private readonly iterable $contributors,
        private readonly AgentContextFactoryInterface $contextFactory,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $applicable = [];
        foreach ($this->contributors as $contributor) {
            if ($contributor->supports($request, $response)) {
                $applicable[] = $contributor;
            }
        }

        if ([] === $applicable) {
            return;
        }

        // Building the context can hit the channel repository — only pay for it
        // once at least one contributor wants to run.
        $context = $this->contextFactory->create();

        foreach ($applicable as $contributor) {
            $contributor->contribute($request, $response, $context);
        }
    }
}
