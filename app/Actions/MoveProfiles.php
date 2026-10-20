<?php

namespace App\Actions;

use App\Logging\RefreshLog;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Re-points profiles to another account (e.g. away from a blocked one) without losing data.
 * Single code path for the artisan command and the UI.
 */
class MoveProfiles
{
    /**
     * @param  list<int>|null  $profileIds  only these profiles of $from; null = all of them
     * @return array{moved: int, merged: int}
     */
    public function handle(Account $from, Account $to, ?array $profileIds = null): array
    {
        if ($from->is($to)) {
            throw new InvalidArgumentException('Source and target account are the same.');
        }

        if ($to->isBlocked()) {
            throw new InvalidArgumentException("Target account [{$to->name}] is blocked.");
        }

        if ($from->upstream !== $to->upstream) {
            // Revisions are only comparable within one upstream (fake: integers, FansAPI: data timestamps)
            throw new InvalidArgumentException("Cannot move profiles from a {$from->upstream->value} account to a {$to->upstream->value} account: their data versions are not comparable.");
        }

        $result = ['moved' => 0, 'merged' => 0];

        DB::transaction(function () use ($from, $to, $profileIds, &$result) {
            // lazyById pages by primary key, so rows leaving the set mid-loop don't skip others
            $from->profiles()
                ->when($profileIds !== null, fn ($query) => $query->whereKey($profileIds))
                ->lockForUpdate()
                ->lazyById(500)
                ->each(function (Profile $profile) use ($from, $to, &$result) {
                    $existing = $to->profiles()
                        ->where('username', $profile->username)
                        ->lockForUpdate()
                        ->first();

                    if ($existing === null) {
                        $profile->update(['account_id' => $to->id]);
                        $result['moved']++;

                        RefreshLog::info('profile.moved', [
                            'profile_id' => $profile->id,
                            'username' => $profile->username,
                            'from_account_id' => $from->id,
                            'to_account_id' => $to->id,
                        ]);

                        return;
                    }

                    $this->merge($profile, $existing);
                    $result['merged']++;
                });
        });

        return $result;
    }

    /**
     * The target account already tracks this username: keep one row with the newest data
     * and both histories. Attempts keep their original account_id (who really made them).
     */
    private function merge(Profile $source, Profile $target): void
    {
        $targetRevision = $target->revision;
        $sourceIsNewer = ($source->revision ?? -1) > ($targetRevision ?? -1);

        if ($sourceIsNewer) {
            $target->update($source->only(Profile::DATA_COLUMNS));
        }

        RefreshAttempt::where('profile_id', $source->id)->update(['profile_id' => $target->id]);

        $source->delete();

        RefreshLog::info('profile.merged', [
            'username' => $target->username,
            'source_profile_id' => $source->id,
            'target_profile_id' => $target->id,
            'source_revision' => $source->revision,
            'target_revision' => $targetRevision,
            'kept' => $sourceIsNewer ? 'source_data' : 'target_data',
        ]);
    }
}
