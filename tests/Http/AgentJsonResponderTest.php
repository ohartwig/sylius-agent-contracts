<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Http;

use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AgentJsonResponderTest extends TestCase
{
    public function testDiscoveryDocumentsAreCacheableAndCorsOpen(): void
    {
        $response = (new AgentJsonResponder())->respond(['protocol_version' => '2026-04-08']);

        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame(3600, $response->getMaxAge());
        self::assertSame('86400', $response->headers->getCacheControlDirective('stale-while-revalidate'));
        self::assertNotNull($response->getEtag());
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $body = (string) (new AgentJsonResponder())
            ->respond(['url' => 'https://shop.example.com/ucp/v1', 'name' => 'Grüße'])
            ->getContent();

        self::assertStringContainsString('https://shop.example.com/ucp/v1', $body);
        self::assertStringContainsString('Grüße', $body);
    }

    /** Returning crawlers send If-None-Match; answering 304 avoids a rebuild. */
    public function testMatchingEtagYields304(): void
    {
        $responder = new AgentJsonResponder();
        $etag = (string) $responder->respond(['a' => 1])->getEtag();

        $request = Request::create('/.well-known/acp.json');
        $request->headers->set('If-None-Match', $etag);

        self::assertSame(304, $responder->respond(['a' => 1], request: $request)->getStatusCode());
    }

    public function testNonJsonDocumentsKeepTheirContentType(): void
    {
        $response = (new AgentJsonResponder())->respondWithBody("# Shop\n", 'text/plain');

        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame("# Shop\n", $response->getContent());
    }
}
