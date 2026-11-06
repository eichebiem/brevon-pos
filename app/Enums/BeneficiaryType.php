<?php

namespace App\Enums;

/** Who an SC/PWD discount is for (D-148). */
enum BeneficiaryType: string
{
    case SeniorCitizen = 'senior_citizen';
    case Pwd = 'pwd';

    public function label(): string
    {
        return match ($this) {
            self::SeniorCitizen => 'Senior citizen',
            self::Pwd => 'Person with disability',
        };
    }
}
