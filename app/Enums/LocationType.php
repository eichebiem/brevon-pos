<?php

namespace App\Enums;

/** Operating location type (D-057). */
enum LocationType: string
{
    case Permanent = 'permanent';
    case PopUp = 'pop_up';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent branch',
            self::PopUp => 'Pop-up stand',
        };
    }
}
