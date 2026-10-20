<?php

namespace App\Http\Controllers;

use App\Actions\MoveProfiles;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProfileController
{
    public function refresh(Profile $profile): RedirectResponse
    {
        RefreshProfile::dispatch($profile->id);

        return back()->with('status', "Refresh queued for {$profile->username} (processed by Horizon).");
    }

    public function move(Request $request, Profile $profile, MoveProfiles $moveProfiles): RedirectResponse
    {
        $target = Account::findOrFail($request->validate(['account_id' => ['required', 'integer']])['account_id']);

        try {
            $result = $moveProfiles->handle($profile->account, $target, [$profile->id]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['move' => $e->getMessage()]);
        }

        return back()->with('status', $result['merged']
            ? "{$profile->username} merged into the existing profile on {$target->name}."
            : "{$profile->username} moved to {$target->name}.");
    }
}
