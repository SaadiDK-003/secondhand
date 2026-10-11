<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SellerResponseSummary
{
    /** @return array{sample_size: int, replied_percent: int}|null */
    public function forSeller(User $seller): ?array
    {
        return Cache::remember('seller-response.v1.'.$seller->id, 300, function () use ($seller): ?array {
            // Give new conversations 24 hours before including them in the denominator.
            $conversations = Conversation::where('seller_id', $seller->id)
                ->whereBetween('created_at', [now()->subDays(90), now()->subDay()])
                ->withMin(['messages as first_buyer_message' => fn ($query) => $query->whereColumn('sender_id', 'conversations.buyer_id')], 'created_at')
                ->withMin(['messages as first_seller_message' => fn ($query) => $query->where('sender_id', $seller->id)], 'created_at')
                ->latest('id')->limit(100)->get()
                ->filter(fn ($conversation) => $conversation->getAttribute('first_buyer_message') !== null);
            if ($conversations->count() < 5) {
                return null;
            }
            $replied = $conversations->filter(fn ($conversation) => $conversation->getAttribute('first_seller_message') !== null
                && $conversation->getAttribute('first_seller_message') >= $conversation->getAttribute('first_buyer_message'))->count();

            return ['sample_size' => $conversations->count(), 'replied_percent' => (int) round($replied / $conversations->count() * 100)];
        });
    }
}
