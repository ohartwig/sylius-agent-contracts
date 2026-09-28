<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Context;

use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything a discovery document needs to render itself, resolved once per request.
 *
 * Sylius has N channels where a CMS has one site, so every document is channel-
 * *and* locale-dependent: base URL, catalogue, currency and available locales all
 * differ per channel. Passing this object through the provider chain is what keeps
 * a multi-channel shop from serving channel A's URLs under channel B's hostname.
 */
final readonly class AgentContext
{
    /**
     * @param list<string> $localeCodes all locales enabled on the channel
     * @param ?Request     $request     null when built from the CLI (dump commands)
     */
    public function __construct(
        public ChannelInterface $channel,
        public string $baseUrl,
        public string $localeCode,
        public array $localeCodes = [],
        public ?Request $request = null,
    ) {
    }

    /**
     * Absolute URL for a package-relative path. Idempotent on leading slashes so
     * callers may pass '/llms.txt' or 'llms.txt' interchangeably.
     */
    public function url(string $path): string
    {
        return '' === $path ? $this->baseUrl : $this->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * The only sanctioned way to build a cache key in this package family.
     *
     * Forgetting the channel here is the classic multi-channel bug — one channel
     * warms the cache and every other channel is served its documents.
     */
    public function cacheKey(string $prefix): string
    {
        return \sprintf('%s.%s.%s', $prefix, (string) $this->channel->getCode(), $this->localeCode);
    }
}
