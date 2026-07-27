<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Context;

use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Builds the per-request AgentContext.
 *
 * Every sibling bundle depends on this to render channel-correct URLs, so it is
 * an interface: the concrete factory reaches into Sylius' channel and locale
 * contexts, which a unit test of a listener or controller has no business
 * booting. It also lets an application substitute its own resolution — a shop
 * that keys channels off something other than the hostname, say.
 */
interface AgentContextFactoryInterface
{
    public function create(?ChannelInterface $channel = null): AgentContext;
}
