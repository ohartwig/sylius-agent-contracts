<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Cart;

use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Hands an agent's selection over to the buyer's own storefront cart.
 *
 * The one implementation behind ACP's `continue_url` and UCP's permalink. Each
 * line goes into the buyer's cart through `sylius.cart_item_add` — the event
 * the storefront's add-to-cart button fires — so every gate the shop hangs on
 * that event (age confirmation, quantity rules) runs exactly as if the buyer
 * had clicked. If a gate refuses, nothing is kept: lines added before the
 * refusal are undone, and the caller tells the buyer and redirects. Parsing the
 * protocol's link, choosing notices and destinations stay with the caller.
 *
 * Also here, because both callers need the same answers: a per-session ledger
 * so that opening a link twice does not add the items twice, the flash
 * notice, and the redirect headers (`no-store`, `no-referrer`) a link that
 * changes a cart needs.
 *
 * Holds no request state: everything per request comes in as an argument, so
 * the service is safe in long-running workers (FrankenPHP worker mode).
 */
final class CartHandoff
{
    /** How many handoffs a session remembers. */
    public const LEDGER_SIZE = 20;

    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param bool $runtimeExceptionIsRefusal Backwards compatibility: also read
     *        any `\RuntimeException` from a listener as a gate refusal, not
     *        only a {@see CartGateRefusalInterface}. Switch it off once the
     *        installation's gates implement the interface, so that a broken
     *        listener surfaces as an error instead of a notice.
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly AddToCartCommandFactoryInterface $addToCartCommandFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ObjectManager $orderManager,
        private readonly bool $runtimeExceptionIsRefusal = true,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Puts the lines into the buyer's cart and saves it, or — if a shop gate
     * refuses one — leaves the cart as it was.
     *
     * An exception that is not a refusal propagates unchanged.
     *
     * @param iterable<CartHandoffLine> $lines
     *
     * @throws \LogicException when the cart context does not hold a Sylius Core order
     */
    public function handOver(iterable $lines): CartHandoffResult
    {
        $lines = \is_array($lines) ? $lines : iterator_to_array($lines, false);
        if ([] === $lines) {
            return CartHandoffResult::nothingAdded();
        }

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface) {
            throw new \LogicException(\sprintf('The cart handoff needs a Sylius Core order, got %s.', $cart::class));
        }

        try {
            foreach ($lines as $line) {
                $item = $this->orderItemFactory->createNew();
                $item->setVariant($line->variant);
                $this->quantityModifier->modify($item, $line->quantity);

                $this->eventDispatcher->dispatch(
                    new GenericEvent($this->addToCartCommandFactory->createWithCartAndCartItem($cart, $item)),
                    SyliusCartEvents::CART_ITEM_ADD,
                );
            }
        } catch (\Throwable $exception) {
            if (!$this->isRefusal($exception)) {
                throw $exception;
            }

            // Undo what earlier lines changed in memory, so a later flush
            // elsewhere cannot persist half a handoff. A cart without an id
            // was never saved; there is nothing to go back to.
            if (null !== $cart->getId()) {
                $this->orderManager->refresh($cart);
            }

            return CartHandoffResult::refused($cart, $exception);
        }

        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        return CartHandoffResult::added($cart, \count($lines));
    }

    /**
     * Whether this session already took over handoff `$id` in `$version`.
     *
     * `$version` lets a changed source hand over again (ACP: the agent cart's
     * update time); leave it empty when the id itself identifies the content.
     */
    public function wasHandedOver(SessionInterface $session, string $ledgerKey, string $id, string $version = ''): bool
    {
        $ledger = $this->ledger($session, $ledgerKey);

        if (\array_key_exists($id, $ledger) && $ledger[$id] === $version) {
            return true;
        }

        // Before the ledger lived here, the UCP permalink kept a plain list of
        // fingerprints. Sessions written that way are still read.
        return '' === $version && \in_array($id, array_filter($ledger, 'is_int', \ARRAY_FILTER_USE_KEY), true);
    }

    /** Records handoff `$id` in `$version`; the ledger keeps the latest {@see LEDGER_SIZE}. */
    public function remember(SessionInterface $session, string $ledgerKey, string $id, string $version = ''): void
    {
        $ledger = $this->ledger($session, $ledgerKey);
        unset($ledger[$id]);
        $ledger[$id] = $version;

        $session->set($ledgerKey, \array_slice($ledger, -self::LEDGER_SIZE, null, true));
    }

    public function flash(SessionInterface $session, string $type, string $message): void
    {
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * The redirect at the end of a handoff. Never cached (the answer depends
     * on the session), and the link — which carries the agent's selection —
     * is not passed on as a referrer.
     */
    public function redirect(string $url, int $status = Response::HTTP_FOUND): RedirectResponse
    {
        $response = new RedirectResponse($url, $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function isRefusal(\Throwable $exception): bool
    {
        if ($exception instanceof CartGateRefusalInterface) {
            return true;
        }

        if ($this->runtimeExceptionIsRefusal && $exception instanceof \RuntimeException) {
            $this->logger?->info('Cart handoff: {class} from a sylius.cart_item_add listener read as a gate refusal. Implement {interface} on gate exceptions to make that explicit.', [
                'class' => $exception::class,
                'interface' => CartGateRefusalInterface::class,
                'exception' => $exception,
            ]);

            return true;
        }

        return false;
    }

    /** @return array<array-key, string> */
    private function ledger(SessionInterface $session, string $key): array
    {
        $ledger = $session->get($key, []);

        return \is_array($ledger) ? array_filter($ledger, 'is_string') : [];
    }
}
