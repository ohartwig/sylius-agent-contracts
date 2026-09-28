<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/** An RFC 9727 link target: an absolute href plus its media type. */
final readonly class LinkTarget
{
    public function __construct(
        public string $href,
        public ?string $type = null,
    ) {
    }

    /** @return array{href: string, type?: string} */
    public function toArray(): array
    {
        $out = ['href' => $this->href];
        if (null !== $this->type) {
            $out['type'] = $this->type;
        }

        return $out;
    }
}
