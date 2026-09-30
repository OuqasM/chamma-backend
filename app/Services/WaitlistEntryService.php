<?php

namespace App\Services;

use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;

/**
 * The admin side of the waiting list: who is outstanding, who has been called,
 * and marking the difference.
 *
 * Split from WaitlistService, which only ever adds. This one exists because
 * "contacted" is a fact about the owner's side of the conversation, not
 * something the customer's signup can say.
 */
class WaitlistEntryService
{
    /**
     * Mark entries as contacted, so they leave the outstanding list.
     *
     * Kept in the database rather than inferred from the mail: the alert is a
     * prompt to call, not proof anyone picked up. A row stays as the record
     * that this person was reached, which is what stops the same number being
     * dialled twice for a restock they already heard about.
     *
     * A bulk update rather than a loop: this runs with up to a page of entries
     * in it, and one query is one query.
     *
     * @param  Collection<int, WaitlistEntry>|iterable<WaitlistEntry>  $entries
     */
    public static function markNotified(iterable $entries, string $channel = 'whatsapp'): int
    {
        $ids = [];

        foreach ($entries as $entry) {
            if ($entry instanceof WaitlistEntry && ! $entry->notified_at) {
                $ids[] = $entry->id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        return WaitlistEntry::query()
            ->whereIn('id', $ids)
            ->whereNull('notified_at')
            ->update([
                'notified_at' => now(),
                'notified_channel' => $channel,
            ]);
    }
}
