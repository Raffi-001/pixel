<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditTransactionType: string
{
    case Purchase = 'purchase';
    case Bonus = 'bonus';
    /** The worst-case price, reserved when a generation is submitted. */
    case Hold = 'hold';
    /** Gives the hold back once the generation reaches a final status. */
    case HoldRelease = 'hold_release';
    /** The actual price, charged once a generation succeeds. */
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Bonus => 'Bonus',
            self::Hold => 'Hold',
            self::HoldRelease => 'Hold released',
            self::Charge => 'Charge',
        };
    }
}
