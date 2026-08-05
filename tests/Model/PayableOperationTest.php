<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Tests\Model;

use Koh\SyliusAgentContracts\Model\PayableOperation;
use PHPUnit\Framework\TestCase;

/**
 * toPaymentInfo() is the declaration half of the price of an API call: it feeds
 * the MPP `x-payment-info` OpenAPI extension, while the same object feeds the
 * x402 enforcement table. The whole reason both live on one shape is that a
 * route must never advertise a price it does not charge, so what this method
 * omits matters exactly as much as what it emits — a dropped `amount` reads to
 * an agent as dynamic pricing, and a dropped `currency` reads as the default.
 */
final class PayableOperationTest extends TestCase
{
    public function testAFixedPriceIsDeclaredInFull(): void
    {
        $offer = (new PayableOperation(
            path: '/api/agent/feed',
            method: 'GET',
            intent: 'charge',
            payMethod: 'x402',
            amount: '2500',
            currency: 'EUR',
            description: 'Full product feed',
        ))->toPaymentInfo();

        self::assertSame(
            [
                'intent' => 'charge',
                'method' => 'x402',
                'amount' => '2500',
                'currency' => 'EUR',
                'description' => 'Full product feed',
            ],
            $offer,
        );
    }

    /**
     * The amount stays a string. Passing it through an integer or a float would
     * quietly turn '2500' into 2500 and, for a token contract with more decimals
     * than a float can hold, into the wrong number entirely.
     */
    public function testTheAmountIsEmittedVerbatimAsAString(): void
    {
        $offer = (new PayableOperation('/api/agent/feed', amount: '000100'))->toPaymentInfo();

        self::assertSame('000100', $offer['amount']);
    }

    /** A large token amount must survive intact; this is why the field is not numeric. */
    public function testALargeTokenAmountIsNotRounded(): void
    {
        $offer = (new PayableOperation(
            '/api/agent/feed',
            amount: '123456789012345678901234',
            currency: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
        ))->toPaymentInfo();

        self::assertSame('123456789012345678901234', $offer['amount']);
        self::assertSame('0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', $offer['currency']);
    }

    /**
     * Dynamic pricing is declared by an explicitly null amount, not by leaving
     * the key out. An absent key is "the shop forgot"; a null is "ask me".
     */
    public function testDynamicPricingKeepsAnExplicitNullAmount(): void
    {
        $offer = (new PayableOperation('/api/agent/quote'))->toPaymentInfo();

        self::assertArrayHasKey('amount', $offer);
        self::assertNull($offer['amount']);
    }

    /** The two required MPP fields have defaults, and those defaults are part of the contract. */
    public function testIntentAndMethodDefaultToAPerRequestX402Charge(): void
    {
        $offer = (new PayableOperation('/api/agent/feed'))->toPaymentInfo();

        self::assertSame('charge', $offer['intent']);
        self::assertSame('x402', $offer['method']);
    }

    public function testASessionIntentIsCarriedThrough(): void
    {
        $offer = (new PayableOperation('/api/agent/session', intent: 'session', payMethod: 'stripe'))->toPaymentInfo();

        self::assertSame('session', $offer['intent']);
        self::assertSame('stripe', $offer['method']);
    }

    /** An unset currency must be absent rather than null — a null currency is not a currency. */
    public function testAnAbsentCurrencyIsOmittedEntirely(): void
    {
        $offer = (new PayableOperation('/api/agent/feed', amount: '2500'))->toPaymentInfo();

        self::assertArrayNotHasKey('currency', $offer);
        self::assertArrayNotHasKey('description', $offer);
    }

    /** An empty string is what an unfilled admin field produces; it must not be published. */
    public function testEmptyOptionalFieldsAreOmittedRatherThanPublishedBlank(): void
    {
        $offer = (new PayableOperation('/api/agent/feed', amount: '2500', currency: '', description: ''))
            ->toPaymentInfo();

        self::assertArrayNotHasKey('currency', $offer);
        self::assertArrayNotHasKey('description', $offer);
    }

    /**
     * Two different things are called "method" here and they must not be
     * confused: $method is the HTTP verb of the route being charged, while the
     * offer's `method` is the MPP payment rail. Emitting the verb where the rail
     * belongs would advertise a route as payable by "POST".
     */
    public function testTheOfferMethodIsThePaymentRailAndNotTheHttpVerb(): void
    {
        $operation = new PayableOperation('/api/agent/feed', 'POST', payMethod: 'stripe', amount: '2500');
        $offer = $operation->toPaymentInfo();

        self::assertSame('POST', $operation->method);
        self::assertSame('stripe', $offer['method']);
        self::assertArrayNotHasKey('path', $offer);
    }
}
