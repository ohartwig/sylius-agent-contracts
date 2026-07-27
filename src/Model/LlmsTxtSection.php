<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/** One `## Heading` block of llms.txt, contributed by whichever bundle owns the data. */
final readonly class LlmsTxtSection
{
    /**
     * @param list<LlmsTxtLink> $links
     * @param int               $priority higher sorts earlier; llmstxt.org reserves '## Optional' for last
     */
    public function __construct(
        public string $title,
        public array $links,
        public int $priority = 0,
        public ?string $intro = null,
    ) {
    }

    public function toMarkdown(): string
    {
        $out = '## '.$this->title."\n";

        if (null !== $this->intro && '' !== $this->intro) {
            $out .= "\n".$this->intro."\n";
        }

        if ([] !== $this->links) {
            $out .= "\n";
            foreach ($this->links as $link) {
                $out .= $link->toMarkdown()."\n";
            }
        }

        return $out;
    }
}
