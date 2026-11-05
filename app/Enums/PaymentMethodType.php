<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case Cash = 'cash';
    case EWallet = 'e_wallet';
    case Card = 'card';
    case Other = 'other';
}
