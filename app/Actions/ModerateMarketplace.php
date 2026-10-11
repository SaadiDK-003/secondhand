<?php

namespace App\Actions;

use App\Enums\ListingStatus;
use App\Models\ActivityLog;
use App\Models\Listing;
use App\Models\ProhibitedKeyword;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ModerateMarketplace
{
    public function listing(User $admin, Listing $listing, string $decision, string $reason): void
    {
        Gate::forUser($admin)->authorize('moderate');
        $this->reason($reason);
        DB::transaction(function () use ($admin, $listing, $decision, $reason): void {
            $locked = Listing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            $state = match ($decision) {
                'approve' => ListingStatus::Published,'reject' => ListingStatus::Rejected,'block' => ListingStatus::Blocked,default => throw ValidationException::withMessages(['decision' => 'Invalid decision.'])
            };
            $allowed = match ($decision) {
                'approve' => [ListingStatus::Pending, ListingStatus::Blocked],'reject' => [ListingStatus::Pending],'block' => [ListingStatus::Pending, ListingStatus::Published, ListingStatus::Rejected]
            };
            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['decision' => 'The listing state changed. Refresh and review it again.']);
            }
            if ($decision === 'approve' && ($locked->user->suspended_at !== null || $locked->images()->count() < 1 || $locked->images()->count() > 12 || $locked->images()->where('status', '!=', 'ready')->exists())) {
                throw ValidationException::withMessages(['decision' => 'Approval needs an active seller and 1–12 ready photos.']);
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => $state, 'moderation_note' => $reason, ...($decision === 'approve' ? ['published_at' => now(), 'expires_at' => now()->addDays(config('listings.expiry_days'))] : [])])->save();
            $this->log($admin, 'listing.'.$decision, 'listing', $locked->id, ['reason' => $reason, 'from' => $before, 'to' => $state->value]);
        });
    }

    public function suspend(User $admin, User $user, bool $suspended, string $reason): void
    {
        Gate::forUser($admin)->authorize('moderate');
        $this->reason($reason);
        DB::transaction(function () use ($admin, $user, $suspended, $reason): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_admin) {
                throw ValidationException::withMessages(['reason' => 'Administrators cannot be suspended here.']);
            }
            $locked->forceFill(['suspended_at' => $suspended ? now() : null, 'suspension_reason' => $suspended ? $reason : null, 'remember_token' => null])->save();
            $this->log($admin, $suspended ? 'user.suspend' : 'user.restore', 'user', $locked->id, ['reason' => $reason]);
        });
    }

    public function resolve(User $admin, Report $report, string $status, string $reason): void
    {
        Gate::forUser($admin)->authorize('moderate');
        $this->reason($reason);
        if (! in_array($status, ['resolved', 'dismissed'], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid resolution.']);
        }
        DB::transaction(function () use ($admin, $report, $status, $reason): void {
            $locked = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'This report has already been reviewed.']);
            }
            $locked->update(['status' => $status, 'resolution' => $reason]);
            $this->log($admin, 'report.'.$status, 'report', $locked->id, ['reason' => $reason]);
        });
    }

    public function keyword(User $admin, string $phrase, bool $active): void
    {
        Gate::forUser($admin)->authorize('moderate');
        $phrase = mb_strtolower(trim($phrase));
        if (str_contains($phrase, "\0") || mb_strlen($phrase) < 2 || mb_strlen($phrase) > 100) {
            throw ValidationException::withMessages(['phrase' => 'Use 2–100 characters.']);
        }
        DB::transaction(function () use ($admin, $phrase, $active): void {
            $keyword = ProhibitedKeyword::updateOrCreate(['phrase' => $phrase], ['is_active' => $active]);
            $this->log($admin, 'keyword.update', 'keyword', $keyword->id, ['phrase' => $phrase, 'active' => $active]);
        });
    }

    private function reason(string $reason): void
    {
        if (str_contains($reason, "\0") || mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Give a reason between 3 and 2,000 characters.']);
        }
    }

    /** @param array<string,mixed> $details */
    private function log(User $admin, string $action, string $type, int $id, array $details): void
    {
        ActivityLog::create(['ip_address' => request()->ip(), 'actor_id' => $admin->id, 'action' => $action, 'target_type' => $type, 'target_id' => $id, 'details' => $details]);
    }
}
