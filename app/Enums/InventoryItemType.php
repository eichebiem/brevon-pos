<?php

namespace App\Enums;

enum InventoryItemType: string
{
    case Ingredient = 'ingredient';
    case Packaging = 'packaging';
    case FinishedGood = 'finished_good';
    case Retail = 'retail';
}
