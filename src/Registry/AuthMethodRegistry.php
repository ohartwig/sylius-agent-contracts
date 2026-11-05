<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
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
     * non-empty value and objects (`identity_assertion`) merge key by key the
     * same way — so two methods that each accept a different registration type
     * advertise both without either bundle knowing about the other.
     *
     * @return array<string, mixed>
     */
    public function toAgentAuth(AgentContext $context): array
    {
        $merged = [];

        foreach ($this->all($context) as $descriptor) {
            $merged = self::merge($merged, $descriptor->toAgentAuthFragment());
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $into
     * @param array<string, mixed> $from
     *
     * @return array<string, mixed>
     */
    private static function merge(array $into, array $from): array
    {
        foreach ($from as $key => $value) {
            $current = $into[$key] ?? null;
            if (!\is_array($value)) {
                $into[$key] ??= $value;
            } elseif (array_is_list($value)) {
                $into[$key] = array_values(array_unique([...(\is_array($current) ? $current : []), ...$value]));
            } else {
                $into[$key] = self::merge(\is_array($current) ? $current : [], $value);
            }
        }

        return $into;
    }
}
