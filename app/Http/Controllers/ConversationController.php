<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Messaging;
use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000']]);
        $user = $request->user();
        $conversations = Conversation::where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->with(['buyer:id,name', 'seller:id,name', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_id', '!=', $user->id)])
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return Inertia::render('messages/Index', ['conversations' => $conversations->through(fn (Conversation $c) => ['id' => $c->id, 'title' => $c->listing_title, 'other_name' => $c->buyer_id === $user->id ? $c->seller->name : $c->buyer->name, 'last_message' => $c->latestMessage?->body, 'unread_count' => (int) $c->getAttribute('unread_count'), 'updated_at' => $c->updated_at?->toIso8601String()])]);
    }

    public function status(Request $request, Messaging $messaging): JsonResponse
    {
        return response()->json($messaging->inbox($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function store(SendMessageRequest $request, Listing $listing, Messaging $messaging): RedirectResponse
    {
        $conversation = $messaging->start($request->user(), $listing, $request->validated('body'));

        return to_route('messages.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation, Messaging $messaging): Response
    {
        Gate::authorize('view', $conversation);
        $request->validate(['page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000']]);
        $user = $request->user();
        $conversation->load(['buyer:id,name', 'seller:id,name']);
        $other = $conversation->buyer_id === $user->id ? $conversation->seller : $conversation->buyer;
        $listing = Listing::published()->whereKey($conversation->listing_id)->first(['id', 'slug']);
        $messages = $conversation->messages()->orderByDesc('id')->paginate(30);
        // Acknowledge only incoming messages included in this page, never unseen history or concurrent arrivals.
        $conversation->messages()->whereIn('id', $messages->getCollection()->modelKeys())
            ->where('sender_id', '!=', $user->id)->whereNull('read_at')->update(['read_at' => now()]);

        return Inertia::render('messages/Show', [
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->listing_title, 'listing_url' => $listing ? route('listings.show', $listing->slug) : null, 'other' => ['id' => $other->id, 'name' => $other->name]],
            'blocked' => $messaging->blocked($user->id, $other->id),
            'blockedByMe' => DB::table('blocked_users')->where('user_id', $user->id)->where('blocked_user_id', $other->id)->exists(),
            'messages' => $messages->through(fn (Message $m) => ['id' => $m->id, 'body' => $m->body, 'mine' => $m->sender_id === $user->id, 'created_at' => $m->created_at->toIso8601String()]),
        ]);
    }

    public function send(SendMessageRequest $request, Conversation $conversation, Messaging $messaging): RedirectResponse
    {
        $messaging->send($request->user(), $conversation, $request->validated('body'));

        return to_route('messages.show', $conversation);
    }
}
