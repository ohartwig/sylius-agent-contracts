<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Context;

use Doctrine\Common\Collections\ArrayCollection;
use Koh\SyliusAgentContracts\Context\AgentContextFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Context\LocaleNotFoundException;
use Sylius\Component\Locale\Model\LocaleInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The factory is the package's only defence against the request shapes real
 * crawlers produce, and every branch in it exists because a specific caller
 * fails without it: a bot on the bare IP, a preview hostname, an ingress that
 * terminates TLS, a shop with no locale context on the CLI. Those are exactly
 * the situations nobody reproduces by hand, so they are asserted here.
 */
final class AgentContextFactoryTest extends TestCase
{
    /** The happy path: the channel context knows the channel, nothing else is consulted. */
    public function testChannelContextWins(): void
    {
        $channel = $this->channel('WEB_DE', 'shop.example.com');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneEnabledByHostname');
        $repository->expects(self::never())->method('findEnabled');

        $context = $this->factory(
            channelContext: $this->channelContext($channel),
            repository: $repository,
        )->create();

        self::assertSame($channel, $context->channel);
        self::assertSame('https://shop.example.com', $context->baseUrl);
    }

    /**
     * A bot arriving over a preview hostname makes Sylius' composite channel
     * context throw. Letting that bubble turns every /.well-known/* request into
     * a 500, which is the single most common reason a readiness scan fails.
     */
    public function testHostnameLookupRescuesAChannelNotFoundException(): void
    {
        $channel = $this->channel('WEB_AT', 'at.example.com');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneEnabledByHostname')
            ->with('preview.example.com')
            ->willReturn($channel);
        $repository->expects(self::never())->method('findEnabled');

        $context = $this->factory(
            channelContext: $this->throwingChannelContext(),
            repository: $repository,
            request: Request::create('https://preview.example.com/.well-known/llms.txt'),
        )->create();

        self::assertSame($channel, $context->channel);
    }

    /** A bot on the bare IP matches no hostname at all; the first enabled channel is the last resort. */
    public function testFirstEnabledChannelIsTheLastResort(): void
    {
        $fallback = $this->channel('WEB_DE', 'shop.example.com');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findOneEnabledByHostname')->willReturn(null);
        $repository->expects(self::once())
            ->method('findEnabled')
            ->willReturn([$fallback, $this->channel('WEB_AT', 'at.example.com')]);

        $context = $this->factory(
            channelContext: $this->throwingChannelContext(),
            repository: $repository,
            request: Request::create('https://10.0.0.7/.well-known/llms.txt'),
        )->create();

        self::assertSame($fallback, $context->channel);
    }

    /** With no request at all — the dump commands — the hostname stage must be skipped, not called with ''. */
    public function testWithoutARequestTheHostnameLookupIsSkipped(): void
    {
        $fallback = $this->channel('WEB_DE', 'shop.example.com');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneEnabledByHostname');
        $repository->method('findEnabled')->willReturn([$fallback]);

        $context = $this->factory(
            channelContext: $this->throwingChannelContext(),
            repository: $repository,
        )->create();

        self::assertSame($fallback, $context->channel);
        self::assertNull($context->request);
    }

    public function testNoEnabledChannelAnywhereIsAHardFailure(): void
    {
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->method('findOneEnabledByHostname')->willReturn(null);
        $repository->method('findEnabled')->willReturn([]);

        try {
            $this->factory(
                channelContext: $this->throwingChannelContext(),
                repository: $repository,
            )->create();
            self::fail('Building a context without any enabled channel must not succeed.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('No enabled Sylius channel', $exception->getMessage());
        }
    }

    /** An explicitly passed channel short-circuits the whole resolution chain. */
    public function testExplicitChannelBypassesResolution(): void
    {
        $channelContext = $this->createMock(ChannelContextInterface::class);
        $channelContext->expects(self::never())->method('getChannel');
        $repository = $this->createMock(ChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('findEnabled');

        $explicit = $this->channel('WEB_AT', 'at.example.com');

        $context = $this->factory(channelContext: $channelContext, repository: $repository)
            ->create($explicit);

        self::assertSame($explicit, $context->channel);
        self::assertSame('https://at.example.com', $context->baseUrl);
    }

    /**
     * The forced scheme is the whole point: TLS ends at the ingress, so the
     * request scheme is http while every published URL must say https.
     */
    public function testChannelHostnameWinsOverTheRequestHostAndUsesTheForcedScheme(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com')),
            request: Request::create('http://internal-cluster-host.svc/.well-known/llms.txt'),
        )->create();

        self::assertSame('https://shop.example.com', $context->baseUrl);
    }

    public function testConfiguredSchemeIsUsedForTheChannelHostname(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com')),
            scheme: 'http',
        )->create();

        self::assertSame('http://shop.example.com', $context->baseUrl);
    }

    /** A trailing slash on the channel hostname must not produce a double slash in every document. */
    public function testTrailingSlashOnTheChannelHostnameIsTrimmed(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com/')),
        )->create();

        self::assertSame('https://shop.example.com', $context->baseUrl);
    }

    /** Opt-in only, and once opted in the request must actually win. */
    public function testTrustRequestHostPrefersTheRequest(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com')),
            request: Request::create('https://real-host.example.com/.well-known/llms.txt'),
            trustRequestHost: true,
        )->create();

        self::assertSame('https://real-host.example.com', $context->baseUrl);
    }

    /** trust_request_host with no request (CLI) must still fall back to the channel hostname. */
    public function testTrustRequestHostWithoutARequestFallsBackToTheChannel(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com')),
            trustRequestHost: true,
        )->create();

        self::assertSame('https://shop.example.com', $context->baseUrl);
    }

    public function testChannelWithoutHostnameFallsBackToTheRequest(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', null)),
            request: Request::create('https://fallback.example.com/.well-known/llms.txt'),
        )->create();

        self::assertSame('https://fallback.example.com', $context->baseUrl);
    }

    /** An empty-string hostname is what an unconfigured channel actually stores. */
    public function testEmptyChannelHostnameFallsBackToTheRequest(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', '')),
            request: Request::create('https://fallback.example.com/'),
        )->create();

        self::assertSame('https://fallback.example.com', $context->baseUrl);
    }

    /** No hostname and no request: an empty base URL beats a fatal, and relative URLs still work. */
    public function testNoHostnameAndNoRequestYieldsAnEmptyBaseUrl(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', null)),
        )->create();

        self::assertSame('', $context->baseUrl);
    }

    public function testLocaleContextWins(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com', 'de_DE', ['de_DE', 'en_US'])),
            localeContext: $this->localeContext('en_US'),
        )->create();

        self::assertSame('en_US', $context->localeCode);
    }

    /** On the CLI there is no request and therefore no locale context; the channel default is the answer. */
    public function testChannelDefaultLocaleRescuesAMissingLocaleContext(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com', 'de_DE', ['de_DE'])),
            localeContext: $this->throwingLocaleContext(),
        )->create();

        self::assertSame('de_DE', $context->localeCode);
    }

    /** A channel with neither a locale context nor a default locale must still build, not fatal. */
    public function testMissingDefaultLocaleDegradesToAnEmptyString(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com', null, [])),
            localeContext: $this->throwingLocaleContext(),
        )->create();

        self::assertSame('', $context->localeCode);
    }

    /** Duplicates would be published verbatim in the hreflang-style locale lists of every document. */
    public function testLocaleCodesAreDeduplicatedAndReindexed(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext(
                $this->channel('WEB_DE', 'shop.example.com', 'de_DE', ['de_DE', 'en_US', 'de_DE']),
            ),
        )->create();

        self::assertSame(['de_DE', 'en_US'], $context->localeCodes);
    }

    /** A locale row with a null code exists in the wild; it must be dropped, not stringified to ''. */
    public function testLocalesWithoutACodeAreDropped(): void
    {
        $context = $this->factory(
            channelContext: $this->channelContext(
                $this->channel('WEB_DE', 'shop.example.com', 'de_DE', ['de_DE', null, 'en_US']),
            ),
        )->create();

        self::assertSame(['de_DE', 'en_US'], $context->localeCodes);
    }

    /** The request has to reach the context — providers use it for content negotiation. */
    public function testTheMainRequestIsHandedToTheContext(): void
    {
        $request = Request::create('https://shop.example.com/.well-known/llms.txt');

        $context = $this->factory(
            channelContext: $this->channelContext($this->channel('WEB_DE', 'shop.example.com')),
            request: $request,
        )->create();

        self::assertSame($request, $context->request);
    }

    private function factory(
        ?ChannelContextInterface $channelContext = null,
        ?ChannelRepositoryInterface $repository = null,
        ?LocaleContextInterface $localeContext = null,
        ?Request $request = null,
        string $scheme = 'https',
        bool $trustRequestHost = false,
    ): AgentContextFactory {
        $requestStack = new RequestStack();
        if (null !== $request) {
            $requestStack->push($request);
        }

        return new AgentContextFactory(
            $channelContext ?? $this->createMock(ChannelContextInterface::class),
            $repository ?? $this->createMock(ChannelRepositoryInterface::class),
            $localeContext ?? $this->localeContext('de_DE'),
            $requestStack,
            $scheme,
            $trustRequestHost,
        );
    }

    /** @param list<?string> $localeCodes */
    private function channel(
        string $code,
        ?string $hostname,
        ?string $defaultLocale = 'de_DE',
        array $localeCodes = ['de_DE'],
    ): ChannelInterface {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($code);
        $channel->method('getHostname')->willReturn($hostname);
        $channel->method('getDefaultLocale')->willReturn(
            null === $defaultLocale ? null : $this->locale($defaultLocale),
        );
        $channel->method('getLocales')->willReturn(
            new ArrayCollection(array_map(fn (?string $c): LocaleInterface => $this->locale($c), $localeCodes)),
        );

        return $channel;
    }

    private function locale(?string $code): LocaleInterface
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('getCode')->willReturn($code);

        return $locale;
    }

    private function channelContext(ChannelInterface $channel): ChannelContextInterface
    {
        $context = $this->createMock(ChannelContextInterface::class);
        $context->method('getChannel')->willReturn($channel);

        return $context;
    }

    private function throwingChannelContext(): ChannelContextInterface
    {
        $context = $this->createMock(ChannelContextInterface::class);
        $context->method('getChannel')->willThrowException(new ChannelNotFoundException());

        return $context;
    }

    private function localeContext(string $code): LocaleContextInterface
    {
        $context = $this->createMock(LocaleContextInterface::class);
        $context->method('getLocaleCode')->willReturn($code);

        return $context;
    }

    private function throwingLocaleContext(): LocaleContextInterface
    {
        $context = $this->createMock(LocaleContextInterface::class);
        $context->method('getLocaleCode')->willThrowException(new LocaleNotFoundException());

        return $context;
    }
}
