<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Cart;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAgentContracts\Cart\CartGateRefusalInterface;
use Koh\SyliusAgentContracts\Cart\CartHandoff;
use Koh\SyliusAgentContracts\Cart\CartHandoffLine;
use Koh\SyliusAgentContracts\Cart\CartHandoffOutcome;
use Koh\SyliusAgentContracts\Cart\CartHandoffResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\OrderBundle\Controller\AddToCartCommandInterface;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactory;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(CartHandoff::class)]
#[CoversClass(CartHandoffResult::class)]
final class CartHandoffTest extends TestCase
{
    private EventDispatcher $dispatcher;

    private Order $cart;

    /** @var list<array{string, int}> variant code and quantity of each add-to-cart event */
    private array $added = [];

    /** @var list<string> */
    private array $manager = [];

    private int $cartLookups = 0;

    protected function setUp(): void
    {
        $this->cart = new Order();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, function (GenericEvent $event): void {
            $command = $event->getSubject();
            self::assertInstanceOf(AddToCartCommandInterface::class, $command);
            self::assertSame($this->cart, $command->getCart());
            $item = $command->getCartItem();
            $this->added[] = [(string) $item->getVariant()?->getCode(), $item->getQuantity()];
        });
    }

    public function testEveryLineGoesThroughTheAddToCartEventAndTheCartIsSaved(): void
    {
        $result = $this->handoff()->handOver([$this->line('A', 2), $this->line('B', 1)]);

        self::assertSame([['A', 2], ['B', 1]], $this->added);
        self::assertSame(['persist', 'flush'], $this->manager);
        self::assertSame(CartHandoffOutcome::Added, $result->outcome);
        self::assertTrue($result->isAdded());
        self::assertFalse($result->isRefused());
        self::assertSame($this->cart, $result->cart);
        self::assertSame(2, $result->linesAdded);
        self::assertNull($result->refusal);
        self::assertNull($result->refusalMessage());
    }

    public function testLinesMayComeFromAGenerator(): void
    {
        $lines = (function () {
            yield $this->line('A', 1);
            yield $this->line('B', 3);
        })();

        $result = $this->handoff()->handOver($lines);

        self::assertSame([['A', 1], ['B', 3]], $this->added);
        self::assertSame(2, $result->linesAdded);
    }

    public function testNoLinesTouchNeitherTheCartNorTheDatabase(): void
    {
        $result = $this->handoff()->handOver([]);

        self::assertSame(CartHandoffOutcome::NothingAdded, $result->outcome);
        self::assertFalse($result->isAdded());
        self::assertFalse($result->isRefused());
        self::assertNull($result->cart);
        self::assertSame(0, $result->linesAdded);
        self::assertSame(0, $this->cartLookups);
        self::assertSame([], $this->manager);
    }

    public function testAGateRefusalUndoesEarlierLinesAndSavesNothing(): void
    {
        $refusal = new GateRefused('age not confirmed');
        $this->refuseFrom('B', $refusal);
        $this->setId($this->cart, 7);

        $result = $this->handoff()->handOver([$this->line('A', 1), $this->line('B', 1), $this->line('C', 1)]);

        self::assertSame(CartHandoffOutcome::Refused, $result->outcome);
        self::assertTrue($result->isRefused());
        self::assertFalse($result->isAdded());
        self::assertSame($refusal, $result->refusal);
        self::assertSame('age not confirmed', $result->refusalMessage());
        self::assertSame($this->cart, $result->cart);
        self::assertSame(0, $result->linesAdded);
        self::assertSame([['A', 1]], $this->added, 'nothing after the refusal is dispatched');
        self::assertSame(['refresh'], $this->manager);
    }

    public function testACartThatWasNeverSavedIsNotRefreshed(): void
    {
        $this->refuseFrom('A', new GateRefused('no'));

        $result = $this->handoff()->handOver([$this->line('A', 1)]);

        self::assertTrue($result->isRefused());
        self::assertSame([], $this->manager);
    }

    /** Backwards compatibility: gates that only throw a RuntimeException still refuse. */
    public function testARuntimeExceptionCountsAsARefusalByDefaultAndIsLogged(): void
    {
        $this->refuseFrom('A', new \RuntimeException('legacy gate'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('gate refusal'),
            self::callback(static fn (array $context): bool => \RuntimeException::class === $context['class']
                && CartGateRefusalInterface::class === $context['interface']
                && $context['exception'] instanceof \RuntimeException),
        );

        $result = $this->handoff(logger: $logger)->handOver([$this->line('A', 1)]);

        self::assertTrue($result->isRefused());
        self::assertSame('legacy gate', $result->refusalMessage());
    }

    public function testAMarkedRefusalIsNotLogged(): void
    {
        $this->refuseFrom('A', new GateRefused('no'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        self::assertTrue($this->handoff(logger: $logger)->handOver([$this->line('A', 1)])->isRefused());
    }

    public function testWithTheFallbackOffARuntimeExceptionIsAnError(): void
    {
        $exception = new \RuntimeException('database gone');
        $this->refuseFrom('A', $exception);
        $this->setId($this->cart, 7);

        try {
            $this->handoff(runtimeExceptionIsRefusal: false)->handOver([$this->line('A', 1)]);
            self::fail('The exception was swallowed.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        self::assertSame([], $this->manager);
    }

    public function testWithTheFallbackOffAMarkedRefusalStillRefuses(): void
    {
        $this->refuseFrom('A', new GateRefused('no'));

        self::assertTrue($this->handoff(runtimeExceptionIsRefusal: false)->handOver([$this->line('A', 1)])->isRefused());
    }

    public function testOtherExceptionsPropagate(): void
    {
        $this->refuseFrom('A', new \LogicException('bug'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bug');

        $this->handoff()->handOver([$this->line('A', 1)]);
    }

    public function testACartOutsideSyliusCoreIsAConfigurationError(): void
    {
        $context = $this->createStub(CartContextInterface::class);
        $context->method('getCart')->willReturn($this->createStub(BaseOrderInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/needs a Sylius Core order, got /');

        $this->handoff(cartContext: $context)->handOver([$this->line('A', 1)]);
    }

    public function testTheLedgerRemembersAHandoffPerVersion(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();

        self::assertFalse($handoff->wasHandedOver($session, 'k', 'cart-1', 'v1'));
        $handoff->remember($session, 'k', 'cart-1', 'v1');

        self::assertTrue($handoff->wasHandedOver($session, 'k', 'cart-1', 'v1'));
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'cart-1', 'v2'), 'a changed source hands over again');
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'cart-1'));
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'cart-2', 'v1'));
        self::assertFalse($handoff->wasHandedOver($session, 'other', 'cart-1', 'v1'));
        self::assertSame(['cart-1' => 'v1'], $session->get('k'));

        $handoff->remember($session, 'k', 'link');
        self::assertTrue($handoff->wasHandedOver($session, 'k', 'link'));
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'link', 'v1'));
    }

    public function testTheLedgerKeepsTheLatestTwenty(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();
        for ($i = 0; $i < 25; ++$i) {
            $handoff->remember($session, 'k', 'id-'.$i, '1');
        }

        $ledger = $session->get('k');
        self::assertIsArray($ledger);
        self::assertCount(CartHandoff::LEDGER_SIZE, $ledger);
        self::assertArrayHasKey('id-24', $ledger);
        self::assertArrayHasKey('id-5', $ledger);
        self::assertArrayNotHasKey('id-4', $ledger);
    }

    public function testRememberingAgainMakesAnEntryTheNewest(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();
        $handoff->remember($session, 'k', 'a', '1');
        $handoff->remember($session, 'k', 'b', '1');
        $handoff->remember($session, 'k', 'a', '2');

        self::assertSame(['b' => '1', 'a' => '2'], $session->get('k'));
    }

    /** UCP permalinks up to 1.2 stored a list of fingerprints. */
    public function testALegacyListLedgerIsStillRead(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();
        $session->set('k', ['fp-1', 'fp-2']);

        self::assertTrue($handoff->wasHandedOver($session, 'k', 'fp-2'));
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'fp-2', 'v1'));
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'fp-3'));

        $handoff->remember($session, 'k', 'fp-3');
        self::assertSame([0 => 'fp-1', 1 => 'fp-2', 'fp-3' => ''], $session->get('k'));
        self::assertTrue($handoff->wasHandedOver($session, 'k', 'fp-1'));
    }

    /** A version that happens to equal an id stored as a legacy value is no match. */
    public function testALegacyValueIsNotMistakenForAVersionedEntry(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();
        $session->set('k', ['x' => 'fp-1']);

        self::assertFalse($handoff->wasHandedOver($session, 'k', 'fp-1'));
    }

    public function testAGarbledLedgerIsTreatedAsEmpty(): void
    {
        $handoff = $this->handoff();
        $session = $this->session();
        $session->set('k', 'nonsense');
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'nonsense'));

        $session->set('k', ['a' => 1, 'b' => '1', 'c' => null]);
        self::assertFalse($handoff->wasHandedOver($session, 'k', 'a', '1'));
        $handoff->remember($session, 'k', 'd', '1');
        self::assertSame(['b' => '1', 'd' => '1'], $session->get('k'));
    }

    public function testFlashesGoToTheFlashBag(): void
    {
        $session = $this->session();

        $this->handoff()->flash($session, 'info', 'blocked');

        self::assertSame(['blocked'], $session->getFlashBag()->peek('info'));
    }

    public function testASessionWithoutAFlashBagIsLeftAlone(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method(self::anything());

        $this->handoff()->flash($session, 'info', 'blocked');
    }

    public function testTheRedirectIsNeitherCachedNorAReferrer(): void
    {
        $response = $this->handoff()->redirect('https://shop.example.com/cart');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://shop.example.com/cart', $response->getTargetUrl());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(303, $this->handoff()->redirect('/x', 303)->getStatusCode());
    }

    private function refuseFrom(string $code, \Throwable $exception): void
    {
        $this->dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, static function (GenericEvent $event) use ($code, $exception): void {
            $command = $event->getSubject();
            if ($command instanceof AddToCartCommandInterface && $code === $command->getCartItem()->getVariant()?->getCode()) {
                throw $exception;
            }
        }, 512);
    }

    private function line(string $code, int $quantity): CartHandoffLine
    {
        $variant = new ProductVariant();
        $variant->setCode($code);

        return new CartHandoffLine($variant, $quantity);
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function setId(Order $order, int $id): void
    {
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, $id);
    }

    private function handoff(
        ?CartContextInterface $cartContext = null,
        bool $runtimeExceptionIsRefusal = true,
        ?LoggerInterface $logger = null,
    ): CartHandoff {
        if (null === $cartContext) {
            $cartContext = $this->createStub(CartContextInterface::class);
            $cartContext->method('getCart')->willReturnCallback(function (): Order {
                ++$this->cartLookups;

                return $this->cart;
            });
        }

        $itemFactory = $this->createStub(FactoryInterface::class);
        $itemFactory->method('createNew')->willReturnCallback(static fn (): OrderItem => new OrderItem());

        $quantity = $this->createStub(OrderItemQuantityModifierInterface::class);
        $quantity->method('modify')->willReturnCallback(static function (OrderItem $item, int $quantity): void {
            (new \ReflectionProperty(OrderItem::class, 'quantity'))->setValue($item, $quantity);
        });

        $manager = $this->createStub(ObjectManager::class);
        foreach (['persist', 'flush', 'refresh'] as $method) {
            $manager->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): void {
                if ([] !== $arguments) {
                    self::assertSame($this->cart, $arguments[0]);
                }
                $this->manager[] = $method;
            });
        }

        return new CartHandoff(
            $cartContext,
            $itemFactory,
            $quantity,
            new AddToCartCommandFactory(),
            $this->dispatcher,
            $manager,
            $runtimeExceptionIsRefusal,
            $logger,
        );
    }
}

final class GateRefused extends \DomainException implements CartGateRefusalInterface
{
}
