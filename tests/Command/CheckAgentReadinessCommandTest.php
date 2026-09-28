<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Command;

use Koh\SyliusAgentContracts\Command\CheckAgentReadinessCommand;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentEndpointRegistry;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * This command is a deployment gate, so the only thing that matters about it is
 * whether it fails when it should. A gate that returns SUCCESS on a broken shop
 * is worse than no gate: it converts "nobody checked" into "somebody checked
 * and it was fine". Every case below is a way the shop is broken while each
 * half of it looks correct on its own.
 */
final class CheckAgentReadinessCommandTest extends TestCase
{
    public function testEveryEndpointAnsweringIsASuccess(): void
    {
        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt', 'text/plain')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning(new Response('ok', 200, ['Content-Type' => 'text/plain'])),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every announced endpoint answers', $tester->getDisplay());
    }

    /**
     * The failure the command was written for: a bundle registers the endpoint,
     * llms.txt and the api-catalog advertise it, and the route was never
     * imported. Both halves are individually correct; agents get a 404.
     */
    public function testAnAdvertisedEndpointWithNoRouteFails(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('match')->willThrowException(new ResourceNotFoundException('No routes found for "/llms.txt".'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt', 'text/plain')],
            router: $router,
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('NO ROUTE', $tester->getDisplay());
        self::assertStringContainsString('1 announced endpoint(s) did not answer', $tester->getDisplay());
    }

    /** A route that resolves but answers 404 is still a broken announcement. */
    public function testAnEndpointAnsweringWithAClientErrorFails(): void
    {
        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/.well-known/api-catalog', 'API catalog')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning(new Response('nope', 404, ['Content-Type' => 'text/html'])),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('HTTP 404', $tester->getDisplay());
    }

    /** A 500 from a broken provider must be reported as a failure, not swallowed as "answered". */
    public function testAnEndpointAnsweringWithAServerErrorFails(): void
    {
        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/.well-known/api-catalog', 'API catalog')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning(new Response('boom', 500)),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('HTTP 500', $tester->getDisplay());
    }

    /** A redirect is not an error; discovery documents are allowed to move. */
    public function testARedirectIsNotTreatedAsAFailure(): void
    {
        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning(new Response('', 301, ['Location' => '/llms.txt/'])),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    /**
     * A thrown kernel exception must be caught and reported, not crash the gate.
     * The distinct ERROR label matters as much as the exit code: it is what
     * separates "the route is missing" from "the handler blew up", and an
     * operator reading the table has nothing else to go on.
     */
    public function testAnExceptionFromTheKernelIsReportedAsAFailure(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')->willThrowException(new \RuntimeException('provider exploded'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            router: $this->routerMatchingEverything(),
            kernel: $kernel,
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('ERROR', $display);
        self::assertStringContainsString('RuntimeException', $display);
        self::assertStringContainsString('provider exploded', $display);
        self::assertStringNotContainsString('NO ROUTE', $display);
    }

    /**
     * A discovery document that sets a session cookie cannot be served from a
     * shared cache, which is most of the point of publishing it. The response is
     * a perfectly valid 200, so nothing else in the stack complains.
     */
    public function testADocumentThatSetsACookieFails(): void
    {
        $response = new Response('ok', 200, ['Content-Type' => 'text/plain']);
        $response->headers->setCookie(Cookie::create('PHPSESSID', 'abc'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning($response),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('COOKIE', $tester->getDisplay());
    }

    /** --routes-only is a build gate: it must resolve routes and send nothing. */
    public function testRoutesOnlyNeverSendsARequest(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            router: $this->routerMatchingEverything(),
            kernel: $kernel,
        );

        self::assertSame(Command::SUCCESS, $tester->execute(['--routes-only' => true]));
        self::assertStringContainsString('route resolves', $tester->getDisplay());
    }

    /** --routes-only still has to fail on a missing route, or it gates nothing. */
    public function testRoutesOnlyStillFailsOnAMissingRoute(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('match')->willThrowException(new ResourceNotFoundException('nope'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            router: $router,
        );

        self::assertSame(Command::FAILURE, $tester->execute(['--routes-only' => true]));
        self::assertStringContainsString('NO ROUTE', $tester->getDisplay());
    }

    /**
     * No bundle installed at all is a warning, not a failure — the package is a
     * dependency of the siblings and may legitimately stand alone.
     */
    public function testNoRegisteredEndpointsWarnsButSucceeds(): void
    {
        $tester = $this->tester(endpoints: []);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No agent endpoints are registered', $tester->getDisplay());
    }

    /** Each channel is checked separately: routing is shared, base URLs are not. */
    public function testEveryEnabledChannelIsChecked(): void
    {
        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            channels: [$this->channel('WEB_DE'), $this->channel('WEB_AT')],
            router: $this->routerMatchingEverything(),
            kernel: $this->kernelReturning(new Response('ok', 200)),
        );

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('WEB_DE', $display);
        self::assertStringContainsString('WEB_AT', $display);
    }

    /** One broken channel must fail the run even when the others are fine. */
    public function testOneFailingChannelFailsTheWholeRun(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('match')->willThrowException(new ResourceNotFoundException('nope'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            channels: [$this->channel('WEB_DE'), $this->channel('WEB_AT')],
            router: $router,
        );

        self::assertSame(Command::FAILURE, $tester->execute(['--routes-only' => true]));
        self::assertStringContainsString('2 announced endpoint(s) did not answer', $tester->getDisplay());
    }

    public function testChannelOptionNarrowsTheRunToOneChannel(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneByCode')
            ->with('WEB_AT')
            ->willReturn($this->channel('WEB_AT'));
        $repository->expects(self::never())->method('findEnabled');

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            repository: $repository,
            router: $this->routerMatchingEverything(),
        );

        self::assertSame(Command::SUCCESS, $tester->execute(['--channel' => 'WEB_AT', '--routes-only' => true]));
        self::assertStringContainsString('WEB_AT', $tester->getDisplay());
    }

    /** A typo in --channel must not be reported as a clean run. */
    public function testUnknownChannelCodeIsRejected(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findOneByCode')->willReturn(null);

        $tester = $this->tester(endpoints: [], repository: $repository);

        try {
            $tester->execute(['--channel' => 'NOPE']);
            self::fail('An unknown channel code must not be accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('No channel with code "NOPE"', $exception->getMessage());
        }
    }

    /**
     * --routes-only is meant to run in a build image where there is often no
     * database. Routing is channel-independent, so the command degrades to a
     * synthetic channel rather than refusing to run.
     */
    public function testRoutesOnlyFallsBackToASyntheticChannelWithoutADatabase(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findEnabled')->willThrowException(new \RuntimeException('no such table: sylius_channel'));

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            repository: $repository,
            router: $this->routerMatchingEverything(),
        );

        self::assertSame(Command::SUCCESS, $tester->execute(['--routes-only' => true]));
        self::assertStringContainsString('No database reachable', $tester->getDisplay());
        self::assertStringContainsString('_agent_check', $tester->getDisplay());
    }

    /**
     * The synthetic-channel rescue exists for a missing database, and an unknown
     * --channel is not one. Rescuing it makes the synthetic channel answer for a
     * channel the operator explicitly named, so a typo comes back as a green
     * gate that checked nothing they asked about — in the one mode that runs
     * unattended in a build.
     */
    public function testUnknownChannelCodeIsRejectedEvenInRoutesOnlyMode(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findOneByCode')->willReturn(null);

        $tester = $this->tester(
            endpoints: [new AgentEndpoint('/llms.txt', 'llms.txt')],
            repository: $repository,
            router: $this->routerMatchingEverything(),
        );

        try {
            $tester->execute(['--channel' => 'NOPE', '--routes-only' => true]);
            self::fail('An unknown channel code must not fall back to the synthetic channel.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('No channel with code "NOPE"', $exception->getMessage());
        }

        self::assertStringNotContainsString('_agent_check', $tester->getDisplay());
    }

    /**
     * Without --routes-only there is no synthetic-channel rescue: a missing
     * database has to surface, because the run would otherwise report a clean
     * result for a shop it never reached.
     */
    public function testAMissingDatabaseIsNotSwallowedOutsideRoutesOnly(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findEnabled')->willThrowException(new \RuntimeException('no such table: sylius_channel'));

        $tester = $this->tester(endpoints: [], repository: $repository);

        try {
            $tester->execute([]);
            self::fail('A missing database must not be reported as a clean run.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('no such table', $exception->getMessage());
        }
    }

    /** The probed path must be the endpoint's own path, not whatever the router was last asked about. */
    public function testTheAnnouncedPathIsWhatGetsProbed(): void
    {
        $matched = [];
        $router = $this->createMock(RouterInterface::class);
        $router->method('match')->willReturnCallback(static function (string $path) use (&$matched): array {
            $matched[] = $path;

            return ['_route' => 'x'];
        });

        $tester = $this->tester(
            endpoints: [
                new AgentEndpoint('/llms.txt', 'llms.txt'),
                new AgentEndpoint('/.well-known/api-catalog', 'API catalog'),
            ],
            router: $router,
        );

        $tester->execute(['--routes-only' => true]);

        self::assertSame(['/.well-known/api-catalog', '/llms.txt'], $matched);
    }

    /** @param list<AgentEndpoint> $endpoints @param list<ChannelInterface> $channels */
    private function tester(
        array $endpoints,
        ?array $channels = null,
        ?ChannelRepositoryInterface $repository = null,
        ?RouterInterface $router = null,
        ?HttpKernelInterface $kernel = null,
    ): CommandTester {
        if (null === $repository) {
            $repository = $this->createMock(ChannelRepositoryInterface::class);
            $repository->method('findEnabled')->willReturn($channels ?? [$this->channel('WEB_DE')]);
        }

        $command = new CheckAgentReadinessCommand(
            new AgentEndpointRegistry([$this->endpointProvider($endpoints)]),
            $this->contextFactory(),
            $repository,
            $router ?? $this->createMock(RouterInterface::class),
            $kernel ?? $this->createMock(HttpKernelInterface::class),
        );

        return new CommandTester($command);
    }

    /** @param list<AgentEndpoint> $endpoints */
    private function endpointProvider(array $endpoints): AgentEndpointProviderInterface
    {
        return new class($endpoints) implements AgentEndpointProviderInterface {
            /** @param list<AgentEndpoint> $endpoints */
            public function __construct(private array $endpoints)
            {
            }

            public function getAgentEndpoints(AgentContext $context): iterable
            {
                return $this->endpoints;
            }
        };
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        return new class implements AgentContextFactoryInterface {
            public function create(?ChannelInterface $channel = null): AgentContext
            {
                \assert(null !== $channel);

                return new AgentContext(
                    $channel,
                    'https://'.strtolower((string) $channel->getCode()).'.example.com',
                    'de_DE',
                    ['de_DE'],
                );
            }
        };
    }

    private function channel(string $code): ChannelInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);

        return $channel;
    }

    private function routerMatchingEverything(): RouterInterface
    {
        $router = $this->createMock(RouterInterface::class);
        $router->method('match')->willReturn(['_route' => 'koh_agent_llms_txt']);

        return $router;
    }

    private function kernelReturning(Response $response): HttpKernelInterface
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->method('handle')
            ->with(
                self::isInstanceOf(Request::class),
                HttpKernelInterface::SUB_REQUEST,
                false,
            )
            ->willReturn($response);

        return $kernel;
    }
}
