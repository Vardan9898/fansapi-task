<?php

namespace App\Http\Controllers;

use App\Actions\MoveProfiles;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AccountController
{
    public function block(Request $request, Account $account): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;

        $account->block($reason ?: 'blocked manually from the dashboard');

        return back()->with('status', "{$account->name} blocked. Its jobs are skipped until it is unblocked or its profiles are moved.");
    }

    public function unblock(Account $account): RedirectResponse
    {
        $account->unblock();

        return back()->with('status', "{$account->name} unblocked.");
    }

    public function moveProfiles(Request $request, Account $account, MoveProfiles $moveProfiles): RedirectResponse
    {
        $target = Account::findOrFail($request->validate(['account_id' => ['required', 'integer']])['account_id']);

        try {
            $result = $moveProfiles->handle($account, $target);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['move' => $e->getMessage()]);
        }

        return back()->with('status', "Moved {$result['moved']}, merged {$result['merged']} profile(s) from {$account->name} to {$target->name}.");
    }
}
