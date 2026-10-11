<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Messaging
{
    /** @return array{unread_count: int, latest: array{id: int, conversation_id: int, sender_name: string}|null} */
    public function inbox(User $user): array
    {
        $unread = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where(fn ($q) => $q->where('conversations.buyer_id', $user->id)->orWhere('conversations.seller_id', $user->id))
            ->where('messages.sender_id', '!=', $user->id)
            ->whereNull('messages.read_at');
        $count = (clone $unread)->count();
        $latest = $count ? $unread->join('users', 'users.id', '=', 'messages.sender_id')
            ->orderByDesc('messages.id')->toBase()->first(['messages.id', 'messages.conversation_id', 'users.name as sender_name']) : null;

        return [
            'unread_count' => $count,
            'latest' => $latest ? ['id' => (int) $latest->id, 'conversation_id' => (int) $latest->conversation_id, 'sender_name' => $latest->sender_name] : null,
        ];
    }

    public function blocked(int $one, int $two): bool
    {
        return DB::table('blocked_users')->where(fn ($q) => $q->where('user_id', $one)->where('blocked_user_id', $two))->orWhere(fn ($q) => $q->where('user_id', $two)->where('blocked_user_id', $one))->exists();
    }

    // Block and send operations lock the same users in a stable order.
    private function lockUsers(int $one, int $two): void
    {
        User::whereIn('id', [$one, $two])->orderBy('id')->lockForUpdate()->get();
    }

    public function start(User $buyer, Listing $listing, string $body): Conversation
    {
        return DB::transaction(function () use ($buyer, $listing, $body): Conversation {
            $this->lockUsers($buyer->id, $listing->user_id);
            $listing = Listing::published()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_if($buyer->id === $listing->user_id, 403, 'You cannot message yourself.');
            $this->ensureAllowed($buyer->id, $listing->user_id);
            $conversation = Conversation::firstOrCreate(['listing_id' => $listing->id, 'buyer_id' => $buyer->id], ['seller_id' => $listing->user_id, 'listing_title' => $listing->title]);
            $this->append($conversation, $buyer, $body);

            return $conversation;
        });
    }

    public function send(User $sender, Conversation $conversation, string $body): void
    {
        Gate::forUser($sender)->authorize('send', $conversation);
        DB::transaction(function () use ($sender, $conversation, $body): void {
            $this->lockUsers($sender->id, $conversation->otherUserId($sender));
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->ensureAllowed($sender->id, $conversation->otherUserId($sender));
            $this->append($conversation, $sender, $body);
        });
    }

    private function ensureAllowed(int $one, int $two): void
    {
        if (User::whereIn('id', [$one, $two])->whereNotNull('suspended_at')->exists() || $this->blocked($one, $two)) {
            throw ValidationException::withMessages(['body' => 'Messaging is unavailable between these accounts.']);
        }
    }

    private function append(Conversation $conversation, User $sender, string $body): void
    {
        $conversation->messages()->create(['sender_id' => $sender->id, 'body' => $body]);
        $conversation->touch();
    }

    public function block(User $user, User $other, bool $blocked): void
    {
        abort_if($user->id === $other->id, 422, 'You cannot block yourself.');
        DB::transaction(function () use ($user, $other, $blocked): void {
            $this->lockUsers($user->id, $other->id);
            $query = DB::table('blocked_users')->where('user_id', $user->id)->where('blocked_user_id', $other->id);
            if ($blocked) {
                DB::table('blocked_users')->insertOrIgnore(['user_id' => $user->id, 'blocked_user_id' => $other->id]);
            } else {
                $query->delete();
            }
        });
    }
}
