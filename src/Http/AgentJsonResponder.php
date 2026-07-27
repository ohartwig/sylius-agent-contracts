<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one way this package family emits a discovery document.
 *
 * Every /.well-known/* response shares the same caching, CORS and encoding
 * rules, so they live here instead of being re-typed (and quietly diverging) in
 * fifteen controllers.
 */
final class AgentJsonResponder
{
    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRETTY_PRINT;

    public function __construct(
        private readonly int $defaultMaxAge = 3600,
        private readonly int $staleWhileRevalidate = 86400,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function respond(
        array $data,
        string $contentType = 'application/json',
        ?int $maxAge = null,
        ?Request $request = null,
    ): Response {
        return $this->respondWithBody(
            json_encode($data, self::ENCODE_FLAGS),
            $contentType,
            $maxAge,
            $request,
        );
    }

    /** For documents that are not JSON — llms.txt, robots.txt, auth.md. */
    public function respondWithBody(
        string $body,
        string $contentType,
        ?int $maxAge = null,
        ?Request $request = null,
    ): Response {
        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => $contentType.'; charset=utf-8',
            // Discovery documents are read cross-origin by in-browser agents.
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setPublic();
        $response->setMaxAge($maxAge ?? $this->defaultMaxAge);
        $response->headers->addCacheControlDirective('stale-while-revalidate', (string) $this->staleWhileRevalidate);

        // Returning crawlers send If-None-Match; a 304 is much cheaper than a rebuild.
        $response->setEtag(hash('xxh128', $body));
        if (null !== $request) {
            $response->isNotModified($request);
        }

        return $response;
    }
}
