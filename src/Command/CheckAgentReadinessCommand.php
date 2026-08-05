<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Command;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Verifies that every announced agent endpoint actually answers.
 *
 * The failure this catches is specific and quiet: a bundle registers an
 * endpoint with the registry, llms.txt and the api-catalog dutifully advertise
 * it, and the route was never imported — so agents are pointed at a 404 while
 * every document looks correct. Nothing in a normal test suite notices, because
 * each half is right on its own.
 *
 * Runs in-process against the kernel, so it needs no listening web server and
 * works as a deployment gate.
 */
#[AsCommand(
    name: 'koh:agent:check',
    description: 'Check that every announced agent endpoint resolves and answers.',
)]
final class CheckAgentReadinessCommand extends Command
{
    /** @param ChannelRepositoryInterface<ChannelInterface> $channelRepository */
    public function __construct(
        private readonly AgentEndpointRegistry $endpoints,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly RouterInterface $router,
        private readonly HttpKernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Limit to one channel code')
            ->addOption('routes-only', null, InputOption::VALUE_NONE, 'Only check that routes resolve; do not send requests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $failures = 0;

        foreach ($this->channels($input, $io) as $channel) {
            $context = $this->contextFactory->create($channel);
            $endpoints = $this->endpoints->all($context);

            $io->section(\sprintf('%s (%s)', (string) $channel->getCode(), $context->baseUrl));

            if ([] === $endpoints) {
                $io->warning('No agent endpoints are registered. Is any koh/sylius-* bundle installed?');

                continue;
            }

            $rows = [];
            foreach ($endpoints as $endpoint) {
                [$status, $detail] = $this->probe($endpoint->path, (bool) $input->getOption('routes-only'));
                if ('ok' !== $status) {
                    ++$failures;
                }
                $rows[] = [$endpoint->path, $endpoint->title, $status, $detail];
            }

            $io->table(['Path', 'Endpoint', 'Result', 'Detail'], $rows);
        }

        if ($failures > 0) {
            $io->error(\sprintf('%d announced endpoint(s) did not answer.', $failures));

            return Command::FAILURE;
        }

        $io->success('Every announced endpoint answers.');

        return Command::SUCCESS;
    }

    /** @return array{string, string} */
    private function probe(string $path, bool $routesOnly): array
    {
        try {
            $this->router->match($path);
        } catch (\Throwable $exception) {
            return ['NO ROUTE', $exception->getMessage()];
        }

        if ($routesOnly) {
            return ['ok', 'route resolves'];
        }

        try {
            $response = $this->kernel->handle(
                Request::create($path),
                HttpKernelInterface::SUB_REQUEST,
                false,
            );
        } catch (\Throwable $exception) {
            return ['ERROR', $exception::class.': '.$exception->getMessage()];
        }

        $code = $response->getStatusCode();

        if ($code >= 400) {
            return ['HTTP '.$code, (string) $response->headers->get('Content-Type')];
        }

        // A discovery document that sets a session cookie cannot be served from
        // a shared cache, which is most of the point of publishing it.
        if ([] !== $response->headers->getCookies()) {
            return ['COOKIE', 'response sets a cookie — check the firewall covers this path'];
        }

        return ['ok', \sprintf('%d %s', $code, (string) $response->headers->get('Content-Type'))];
    }

    /**
     * @return list<ChannelInterface>
     */
    private function channels(InputInterface $input, SymfonyStyle $io): array
    {
        try {
            return $this->enumerate($input);
        } catch (\Throwable $exception) {
            // --routes-only is meant to run as a build gate, where there is often
            // no database yet. Routing is channel-independent, so a synthetic
            // channel is enough to walk the registry — and far more useful than
            // refusing to run.
            //
            // An unknown --channel is a caller error rather than a missing
            // database, and must never be rescued this way: the synthetic channel
            // would answer for it, and a typo in the channel code would come back
            // as a green gate that checked nothing the operator asked about.
            if (true !== $input->getOption('routes-only') || $exception instanceof \InvalidArgumentException) {
                throw $exception;
            }

            $io->note('No database reachable — checking routes against a synthetic channel.');

            $channel = new Channel();
            $channel->setCode('_agent_check');

            return [$channel];
        }
    }

    /** @return list<ChannelInterface> */
    private function enumerate(InputInterface $input): array
    {
        $code = $input->getOption('channel');
        if (\is_string($code) && '' !== $code) {
            $channel = $this->channelRepository->findOneByCode($code);
            if (!$channel instanceof ChannelInterface) {
                throw new \InvalidArgumentException(\sprintf('No channel with code "%s".', $code));
            }

            return [$channel];
        }

        $channels = [];
        foreach ($this->channelRepository->findEnabled() as $channel) {
            if ($channel instanceof ChannelInterface) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
