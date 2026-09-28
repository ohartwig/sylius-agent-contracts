<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Context;

use Koh\SyliusAgentContracts\Context\AgentContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

final class AgentContextTest extends TestCase
{
    #[DataProvider('urlCases')]
    public function testUrlIsIdempotentOnLeadingSlashes(string $path, string $expected): void
    {
        self::assertSame($expected, $this->context()->url($path));
    }

    /** @return iterable<string, array{string, string}> */
    public static function urlCases(): iterable
    {
        yield 'leading slash' => ['/llms.txt', 'https://shop.example.com/llms.txt'];
        yield 'no leading slash' => ['llms.txt', 'https://shop.example.com/llms.txt'];
        yield 'nested' => ['/.well-known/acp.json', 'https://shop.example.com/.well-known/acp.json'];
        yield 'empty' => ['', 'https://shop.example.com'];
    }

    public function testCacheKeyIsScopedToChannelAndLocale(): void
    {
        self::assertSame('koh_llms_txt.WEB_DE.de_DE', $this->context()->cacheKey('koh_llms_txt'));
    }

    /**
     * The multi-channel bug this guards against: two channels sharing a cache
     * entry means one channel is served the other's absolute URLs.
     */
    public function testCacheKeyDiffersBetweenChannels(): void
    {
        $other = $this->context(channelCode: 'WEB_AT', localeCode: 'de_AT');

        self::assertNotSame(
            $this->context()->cacheKey('koh_llms_txt'),
            $other->cacheKey('koh_llms_txt'),
        );
    }

    private function context(string $channelCode = 'WEB_DE', string $localeCode = 'de_DE'): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn($channelCode);

        return new AgentContext(
            channel: $channel,
            baseUrl: 'https://shop.example.com',
            localeCode: $localeCode,
            localeCodes: [$localeCode],
        );
    }
}
