<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Context;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Context\LocaleNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the AgentContext, tolerating the request shapes real crawlers produce.
 */
final class AgentContextFactory implements AgentContextFactoryInterface
{
    /** @param ChannelRepositoryInterface<ChannelInterface> $channelRepository */
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly LocaleContextInterface $localeContext,
        private readonly RequestStack $requestStack,
        /** Forced when the request cannot be trusted for the scheme (TLS ends at the ingress). */
        private readonly string $scheme = 'https',
        /** When true the request host wins over the channel hostname; needs framework.trusted_proxies. */
        private readonly bool $trustRequestHost = false,
    ) {
    }

    public function create(?ChannelInterface $channel = null): AgentContext
    {
        $request = $this->requestStack->getMainRequest();
        $channel ??= $this->resolveChannel($request);

        return new AgentContext(
            channel: $channel,
            baseUrl: $this->resolveBaseUrl($request, $channel),
            localeCode: $this->resolveLocale($channel),
            localeCodes: $this->resolveLocaleCodes($channel),
            request: $request,
        );
    }

    /**
     * Three stages, in this order, because each of them fails for a real caller.
     *
     * Sylius' composite channel context throws when no channel matches the host,
     * which is exactly what happens for a bot arriving over the bare IP, a preview
     * hostname or a misconfigured proxy. Letting that bubble turns every
     * /.well-known/* request into a 500 — the single most common reason an
     * agent-readiness scan fails on a multi-channel shop.
     */
    private function resolveChannel(?Request $request): ChannelInterface
    {
        try {
            $channel = $this->channelContext->getChannel();
            if ($channel instanceof ChannelInterface) {
                return $channel;
            }
        } catch (ChannelNotFoundException) {
            // fall through
        }

        if (null !== $request) {
            $channel = $this->channelRepository->findOneEnabledByHostname($request->getHost());
            if ($channel instanceof ChannelInterface) {
                return $channel;
            }
        }

        foreach ($this->channelRepository->findEnabled() as $channel) {
            if ($channel instanceof ChannelInterface) {
                return $channel;
            }
        }

        throw new \LogicException('No enabled Sylius channel — cannot build an agent context.');
    }

    /**
     * The channel hostname wins by default.
     *
     * getSchemeAndHttpHost() is only correct behind a proxy when trusted_proxies
     * is configured; where it is not, it yields the internal cluster host and
     * every absolute URL in every discovery document is wrong. Opt in explicitly
     * via $trustRequestHost once the proxy setup is verified.
     */
    private function resolveBaseUrl(?Request $request, ChannelInterface $channel): string
    {
        if ($this->trustRequestHost && null !== $request) {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        $hostname = $channel->getHostname();
        if (null !== $hostname && '' !== $hostname) {
            return \sprintf('%s://%s', $this->scheme, rtrim($hostname, '/'));
        }

        return null !== $request ? rtrim($request->getSchemeAndHttpHost(), '/') : '';
    }

    private function resolveLocale(ChannelInterface $channel): string
    {
        try {
            return $this->localeContext->getLocaleCode();
        } catch (LocaleNotFoundException) {
            return (string) $channel->getDefaultLocale()?->getCode();
        }
    }

    /** @return list<string> */
    private function resolveLocaleCodes(ChannelInterface $channel): array
    {
        $codes = [];
        foreach ($channel->getLocales() as $locale) {
            $code = $locale->getCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
