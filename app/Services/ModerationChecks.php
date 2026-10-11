<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ProhibitedKeyword;

class ModerationChecks
{
    /** @return list<string> */
    public function flags(Listing $listing): array
    {
        $text = mb_strtolower($listing->title.' '.$listing->description);
        $flags = [];
        foreach (ProhibitedKeyword::where('is_active', true)->pluck('phrase') as $phrase) {
            if (str_contains($text, mb_strtolower($phrase))) {
                $flags[] = 'Keyword: '.$phrase;
            }
        }
        if (preg_match('~(?:https?://|www\.|\b[a-z0-9-]+\.(?:com|net|org|pk)\b)~i', $text)) {
            $flags[] = 'External URL: review for spam or fraud';
        }
        if (Listing::where('user_id', $listing->user_id)->where('id', '!=', $listing->id)->whereRaw('lower(title) = ?', [mb_strtolower(trim($listing->title))])->whereIn('status', ['pending', 'published'])->exists()) {
            $flags[] = 'Possible duplicate title from this seller';
        }

        return $flags;
    }
}
