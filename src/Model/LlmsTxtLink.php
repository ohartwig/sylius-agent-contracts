<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
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
        // Titles and descriptions are shop data — taxon and product names reach
        // this unreviewed. See MarkdownText for why a raw newline here is a
        // forged section rather than a formatting glitch.
        $line = \sprintf('- [%s](%s)', MarkdownText::linkTitle($this->title), MarkdownText::inline($this->url));

        return null !== $this->description && '' !== $this->description
            ? $line.': '.MarkdownText::inline($this->description)
            : $line;
    }
}
