<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentContracts\Cart;

enum CartHandoffOutcome: string
{
    /** Every line went into the buyer's cart, and the cart was saved. */
    case Added = 'added';

    /** A shop gate refused a line; the cart is as it was before. */
    case Refused = 'refused';

    /** There were no lines to hand over; the cart was not touched. */
    case NothingAdded = 'nothing_added';
}
