<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Registry;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AuthMethodDescriptor;
use Koh\SyliusAgentContracts\Provider\AuthMethodDescriptorInterface;

final class AuthMethodRegistry
{
    /** @param iterable<AuthMethodDescriptorInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return list<AuthMethodDescriptor> */
    public function all(AgentContext $context): array
    {
        $methods = [];
        foreach ($this->providers as $provider) {
            $descriptor = $provider->getAuthMethodDescriptor($context);
            if (null !== $descriptor) {
                $methods[$descriptor->id] = $descriptor;
            }
        }

        return array_values($methods);
    }

    /**
     * The merged `agent_auth` block for the RFC 8414 metadata.
     *
     * List-valued keys union across methods, scalar keys take the first
     * non-empty value — so a shop offering OAuth *and* x402 advertises both
     * credential types without either bundle knowing about the other.
     *
     * @return array<string, mixed>
     */
    public function toAgentAuth(AgentContext $context): array
    {
        $merged = [];

        foreach ($this->all($context) as $descriptor) {
            foreach ($descriptor->toAgentAuthFragment() as $key => $value) {
                if (\is_array($value)) {
                    $merged[$key] = array_values(array_unique([...($merged[$key] ?? []), ...$value]));
                } elseif (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }
}
