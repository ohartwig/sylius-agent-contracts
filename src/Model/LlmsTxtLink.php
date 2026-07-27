<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/** One `- [title](url): description` line in llms.txt. */
final readonly class LlmsTxtLink
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $description = null,
    ) {
    }

    public function toMarkdown(): string
    {
        $line = \sprintf('- [%s](%s)', $this->title, $this->url);

        return null !== $this->description && '' !== $this->description
            ? $line.': '.$this->description
            : $line;
    }
}
