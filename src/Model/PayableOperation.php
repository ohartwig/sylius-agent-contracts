<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * An HTTP operation an agent has to pay for.
 *
 * Feeds both the MPP `x-payment-info` OpenAPI extension (declaration) and the
 * x402 price table (enforcement). Keeping them on one shape means a route can
 * never advertise a price it does not actually charge.
 *
 * Not every payable operation is an MPP offer, though. x402 and MPP are two
 * different protocols: an x402 price is enforced with `PAYMENT-REQUIRED` /
 * `PAYMENT-SIGNATURE`, and `x402` is not a method in the MPP sense — MPP method
 * identifiers are lowercase letters only (draft-httpauth-payment-01, "Method
 * Identifier Format"), so `x402` cannot even be spelt as one. Publishing such an
 * operation in `x-payment-info` would point an MPP client at a challenge that
 * never comes. {@see self::isMppOffer()} is the check a discovery document makes
 * before it declares anything.
 */
final readonly class PayableOperation
{
    /** The rail of an operation priced by the x402 gate; not an MPP method. */
    public const PAY_METHOD_X402 = 'x402';

    /** MPP intents a discovery offer may carry (draft-payment-discovery-01). */
    public const MPP_INTENTS = ['charge', 'session'];

    /**
     * @param string  $path      absolute path, e.g. '/api/agent/feed'
     * @param string  $method    HTTP method
     * @param string  $intent    MPP intent: 'charge' (per request) or 'session'
     * @param string  $payMethod payment rail: an MPP method identifier such as
     *                           'tempo' or 'stripe', or 'x402' for an operation
     *                           the x402 gate prices (not an MPP offer). The
     *                           default stays 'x402' for compatibility with
     *                           providers written before the distinction.
     * @param ?string $amount    ASCII digits in the currency's smallest unit; null = dynamic pricing
     * @param ?string $currency  ISO 4217 for fiat, contract address for tokens
     */
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public string $intent = 'charge',
        public string $payMethod = self::PAY_METHOD_X402,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $summary = null,
    ) {
    }

    /**
     * Whether this operation is a valid MPP payment offer and may appear in
     * `x-payment-info`.
     *
     * Mirrors the offer schema of draft-payment-discovery-01 (appendix "JSON
     * Schema for x-payment-info") and the method syntax of
     * draft-httpauth-payment-01: intent `charge` or `session`, a method of
     * lowercase ASCII letters, and an amount that is null or a non-negative
     * integer without leading zeros. Anything else — an x402 price above all —
     * is enforced elsewhere or not at all, and is not declared as MPP.
     */
    public function isMppOffer(): bool
    {
        return \in_array($this->intent, self::MPP_INTENTS, true)
            && 1 === preg_match('/^[a-z]+\z/', $this->payMethod)
            && (null === $this->amount || 1 === preg_match('/^(0|[1-9][0-9]*)\z/', $this->amount));
    }

    /**
     * The `x-payment-info` offer object for this operation.
     *
     * Only meaningful for an operation where {@see self::isMppOffer()} holds;
     * callers building a discovery document check that first.
     *
     * @return array<string, mixed>
     */
    public function toPaymentInfo(): array
    {
        $offer = [
            'intent' => $this->intent,
            'method' => $this->payMethod,
            'amount' => $this->amount,
        ];

        foreach (['currency' => $this->currency, 'description' => $this->description] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $offer[$key] = $value;
            }
        }

        return $offer;
    }
}
