<?php

namespace App\Enums;

enum InventoryItemType: string
{
    case FinishedGood = 'finished_good';
    case Retail = 'retail';
}
