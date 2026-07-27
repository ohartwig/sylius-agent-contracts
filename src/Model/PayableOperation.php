<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Model;

/**
 * An HTTP operation an agent has to pay for.
 *
 * Feeds both the MPP `x-payment-info` OpenAPI extension (declaration) and the
 * x402 price table (enforcement). Keeping them on one shape means a route can
 * never advertise a price it does not actually charge.
 */
final readonly class PayableOperation
{
    /**
     * @param string  $path      absolute path, e.g. '/api/agent/feed'
     * @param string  $method    HTTP method
     * @param string  $intent    MPP intent: 'charge' (per request) or 'session'
     * @param string  $payMethod MPP method: 'tempo' | 'stripe' | 'lightning' | 'card' | 'x402'
     * @param ?string $amount    ASCII digits in the currency's smallest unit; null = dynamic pricing
     * @param ?string $currency  ISO 4217 for fiat, contract address for tokens
     */
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public string $intent = 'charge',
        public string $payMethod = 'x402',
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $summary = null,
    ) {
    }

    /**
     * The `x-payment-info` offer object for this operation.
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
