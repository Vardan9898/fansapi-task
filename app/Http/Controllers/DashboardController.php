<?php

namespace App\Http\Controllers;

use App\Enums\RefreshOutcome;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController
{
    public function __invoke(Request $request, AccountThrottle $throttle): View
    {
        $search = trim((string) $request->query('q', ''));

        // Paginated so the page never loads every profile; search goes through Scout (database engine: LIKE on username/name)
        $profiles = $search === ''
            ? Profile::with('account')->latest('id')->paginate(25)
            : Profile::search($search)->query(fn ($query) => $query->with('account'))->paginate(25);

        $profiles->withQueryString();

        $accounts = Account::withCount('profiles')->orderBy('name')->get();

        return view('dashboard', [
            'profiles' => $profiles,
            'accounts' => $accounts,
            'search' => $search,
            'stats' => $this->successRates(),
            'cooldowns' => $accounts->mapWithKeys(fn (Account $account) => [$account->id => $throttle->cooldownRemaining($account)]),
            'moveTargets' => $accounts->reject(fn (Account $account) => $account->isBlocked()),
        ]);
    }

    /**
     * Last 24h per account: [account_id => ['total' => n, 'successful' => n]] in one query.
     */
    private function successRates(): array
    {
        $successful = collect(RefreshOutcome::cases())->filter->isSuccessful()->map->value->values()->all();

        return RefreshAttempt::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('account_id, count(*) as total')
            ->selectRaw('sum(case when outcome in ('.implode(',', array_fill(0, count($successful), '?')).') then 1 else 0 end) as successful', $successful)
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id')
            ->map(fn ($row) => ['total' => (int) $row->total, 'successful' => (int) $row->successful])
            ->all();
    }
}
