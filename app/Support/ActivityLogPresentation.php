<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

class ActivityLogPresentation
{
    /** @return array<string, string> */
    public static function actions(): array
    {
        return [
            'admin.grant.cli' => 'Admin access granted',
            'keyword.update' => 'Keyword updated',
            'listing.approve' => 'Listing approved',
            'listing.reject' => 'Listing rejected',
            'listing.block' => 'Listing blocked',
            'user.suspend' => 'Account suspended',
            'user.restore' => 'Account restored',
            'report.resolved' => 'Report resolved',
            'report.dismissed' => 'Report dismissed',
        ];
    }

    public static function action(ActivityLog $record): string
    {
        if ($record->action === 'keyword.update' && is_bool($record->details['active'] ?? null)) {
            return $record->details['active'] ? 'Keyword enabled' : 'Keyword disabled';
        }

        return self::actions()[$record->action] ?? Str::headline(str_replace('.', ' ', $record->action));
    }

    public static function target(ActivityLog $record): string
    {
        $type = match ($record->target_type) {
            'user' => 'Account',
            'keyword' => 'Keyword',
            'listing' => 'Listing',
            'report' => 'Report',
            default => Str::headline($record->target_type),
        };

        return $type.' #'.$record->target_id;
    }

    public static function summary(ActivityLog $record): string
    {
        if ($record->action === 'admin.grant.cli') {
            return 'Administrator permissions granted using a server command.';
        }

        $parts = [];
        $phrase = $record->details['phrase'] ?? null;
        if (is_string($phrase) && $phrase !== '') {
            $parts[] = 'Phrase: “'.$phrase.'”';
        }

        $from = $record->details['from'] ?? null;
        $to = $record->details['to'] ?? null;
        if (is_string($from) && is_string($to)) {
            $parts[] = Str::headline($from).' → '.Str::headline($to);
        }

        $reason = $record->details['reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $parts[] = 'Reason: '.$reason;
        }

        return $parts === [] ? 'No additional details recorded.' : implode(' · ', $parts);
    }
}
