<?php

declare(strict_types=1);

namespace App\Enums;

enum ListingCondition: string
{
    case LikeNew = 'like_new';
    case Good = 'good';
    case Fair = 'fair';
    case ForParts = 'for_parts';
}
