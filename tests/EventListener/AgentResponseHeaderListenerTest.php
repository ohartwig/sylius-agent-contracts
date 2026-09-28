<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\EventListener\AgentResponseHeaderListener;
use Koh\SyliusAgentContracts\Provider\ResponseHeaderContributorInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * This listener runs on every single response the application produces, which
 * makes both of its guards load-bearing in a way that is invisible in review.
 * Dropping the main-request check duplicates every header on every ESI or
 * forwarded sub-request; dropping the lazy context build puts a channel
 * repository lookup on the critical path of every asset, error page and admin
 * screen in the shop.
 */
final class AgentResponseHeaderListenerTest extends TestCase
{
    /** A sub-request shares the response object; contributing twice duplicates every header. */
    public function testSubRequestsAreIgnored(): void
    {
        $contributor = $this->recordingContributor(supports: true);

        $this->listen([$contributor], type: HttpKernelInterface::SUB_REQUEST);

        self::assertSame(0, $contributor->supportsCalls);
        self::assertSame(0, $contributor->contributeCalls);
    }

    /**
     * The context build can hit the channel repository, so it must not happen
     * until at least one contributor has said it wants to run. This listener is
     * on every response in the application.
     */
    public function testTheContextIsNotBuiltWhenNoContributorApplies(): void
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $contributor = $this->recordingContributor(supports: false);

        $this->listen([$contributor], factory: $factory);

        self::assertSame(1, $contributor->supportsCalls);
        self::assertSame(0, $contributor->contributeCalls);
    }

    /**
     * With no contributors registered at all — this package installed on its
     * own, which is a supported state — the listener must leave the response
     * byte-for-byte alone and never reach for the channel repository.
     */
    public function testAnEmptyContributorListLeavesTheResponseUntouched(): void
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->expects(self::never())->method('create');

        $response = new Response('body', 200, ['Content-Type' => 'text/html']);
        $before = $response->headers->all();

        $listener = new AgentResponseHeaderListener([], $factory);
        $listener($this->event(new Request(), $response));

        self::assertSame($before, $response->headers->all());
        self::assertSame('body', $response->getContent());
    }

    /** Once something applies, the context is built exactly once and shared by all of them. */
    public function testTheContextIsBuiltOnceAndSharedAcrossContributors(): void
    {
        $context = $this->context();
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($context);

        $first = $this->recordingContributor(supports: true);
        $second = $this->recordingContributor(supports: true);

        $this->listen([$first, $second], factory: $factory);

        self::assertSame(1, $first->contributeCalls);
        self::assertSame(1, $second->contributeCalls);
        self::assertSame($context, $first->seenContext);
        self::assertSame($context, $second->seenContext);
    }

    /** A contributor that declined must not be called anyway once a sibling opts in. */
    public function testOnlySupportingContributorsContribute(): void
    {
        $declining = $this->recordingContributor(supports: false);
        $applying = $this->recordingContributor(supports: true);

        $this->listen([$declining, $applying]);

        self::assertSame(0, $declining->contributeCalls);
        self::assertSame(1, $applying->contributeCalls);
    }

    /** The contributors have to see the very response that is about to be sent, not a copy. */
    public function testContributorsReceiveTheLiveRequestAndResponse(): void
    {
        $request = Request::create('https://shop.example.com/.well-known/api-catalog');
        $response = new Response('{}', 200, ['Content-Type' => 'application/linkset+json']);

        $contributor = new class implements ResponseHeaderContributorInterface {
            public function supports(Request $request, Response $response): bool
            {
                return str_starts_with((string) $response->headers->get('Content-Type'), 'application/linkset+json');
            }

            public function contribute(Request $request, Response $response, AgentContext $context): void
            {
                $response->headers->set('Link', \sprintf('<%s>; rel="service-desc"', $context->url('/openapi.json')));
            }
        };

        $listener = new AgentResponseHeaderListener([$contributor], $this->factory());
        $listener($this->event($request, $response));

        self::assertSame(
            '<https://shop.example.com/openapi.json>; rel="service-desc"',
            $response->headers->get('Link'),
        );
    }

    /** supports() is answered against the final response, which is the reason for priority -100. */
    public function testSupportsIsAskedAboutTheFinalContentType(): void
    {
        $seen = null;
        $contributor = new class($seen) implements ResponseHeaderContributorInterface {
            public function __construct(public ?string &$seen)
            {
            }

            public function supports(Request $request, Response $response): bool
            {
                $this->seen = $response->headers->get('Content-Type');

                return false;
            }

            public function contribute(Request $request, Response $response, AgentContext $context): void
            {
            }
        };

        $listener = new AgentResponseHeaderListener([$contributor], $this->factory());
        $listener($this->event(new Request(), new Response('x', 200, ['Content-Type' => 'text/markdown'])));

        self::assertSame('text/markdown', $seen);
    }

    /** @param list<ResponseHeaderContributorInterface> $contributors */
    private function listen(
        array $contributors,
        ?AgentContextFactoryInterface $factory = null,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $listener = new AgentResponseHeaderListener($contributors, $factory ?? $this->factory());
        $listener($this->event(new Request(), new Response(), $type));
    }

    private function event(
        Request $request,
        Response $response,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response);
    }

    private function factory(?AgentContext $context = null): AgentContextFactoryInterface
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($context ?? $this->context());

        return $factory;
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }

    private function recordingContributor(bool $supports): RecordingContributor
    {
        return new RecordingContributor($supports);
    }
}

final class RecordingContributor implements ResponseHeaderContributorInterface
{
    public int $supportsCalls = 0;

    public int $contributeCalls = 0;

    public ?AgentContext $seenContext = null;

    public function __construct(private readonly bool $supports)
    {
    }

    public function supports(Request $request, Response $response): bool
    {
        ++$this->supportsCalls;

        return $this->supports;
    }

    public function contribute(Request $request, Response $response, AgentContext $context): void
    {
        ++$this->contributeCalls;
        $this->seenContext = $context;
    }
}
