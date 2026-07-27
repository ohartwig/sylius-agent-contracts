<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * One member of the RFC 9727 linkset served at /.well-known/api-catalog.
 *
 * The same data is projected twice — as `application/linkset+json` and as
 * `Link:` response headers — which is why both live in one package.
 */
final readonly class ApiCatalogEntry
{
    /**
     * @param string           $anchor      absolute URL of the described service
     * @param list<LinkTarget> $serviceDesc machine-readable description (OpenAPI, server card)
     * @param list<LinkTarget> $serviceDoc  human-readable documentation
     * @param list<LinkTarget> $describedBy further descriptions
     * @param string           $status      'active' | 'deprecated' | 'experimental'
     */
    public function __construct(
        public string $anchor,
        public array $serviceDesc = [],
        public array $serviceDoc = [],
        public array $describedBy = [],
        public string $status = 'active',
        public ?string $title = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entry = ['anchor' => $this->anchor];

        foreach (['service-desc' => $this->serviceDesc, 'service-doc' => $this->serviceDoc, 'describedby' => $this->describedBy] as $rel => $targets) {
            if ([] !== $targets) {
                $entry[$rel] = array_map(static fn (LinkTarget $t): array => $t->toArray(), $targets);
            }
        }

        if (null !== $this->title) {
            $entry['title'] = $this->title;
        }

        return $entry;
    }
}
