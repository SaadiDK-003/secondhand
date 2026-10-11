<?php

declare(strict_types=1);

namespace App\Enums;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
    case Sold = 'sold';
    case Expired = 'expired';
    case Archived = 'archived';
    case Blocked = 'blocked';
}
