<?php

namespace App\Enums;

/** A preset discount, or the Senior Citizen / PWD discount that records the beneficiary (D-068, D-148). */
enum DiscountKind: string
{
    case Standard = 'standard';
    case SeniorPwd = 'senior_pwd';
}
