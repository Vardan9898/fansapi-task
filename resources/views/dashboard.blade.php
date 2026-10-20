@extends('layouts.app')

@section('content')
    <header>
        <h1>FansAPI · profile refresh</h1>
        <form method="get" action="{{ route('dashboard') }}" class="inline">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search username or name" aria-label="Search profiles">
            <button type="submit">Search</button>
            @if ($search !== '')
                <a href="{{ route('dashboard') }}">clear</a>
            @endif
        </form>
        <a href="{{ url('/horizon') }}">Horizon</a>
        <a href="{{ url('/log-viewer') }}">Log viewer</a>
    </header>

    <main>
        @if (session('status'))
            <div class="flash ok">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error">{{ $errors->first() }}</div>
        @endif

        <h2>Accounts</h2>
        <table>
            <thead>
                <tr>
                    <th>Account</th><th>Upstream</th><th>Status</th><th>Profiles</th><th>Success (24h)</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($accounts as $account)
                    @php($rate = $stats[$account->id] ?? null)
                    <tr>
                        <td><strong>{{ $account->name }}</strong></td>
                        <td>{{ $account->upstream->value }}</td>
                        <td>
                            @if ($account->isBlocked())
                                <span class="badge red">blocked</span>
                                <span class="muted">{{ $account->blocked_reason }} · {{ $account->blocked_at?->diffForHumans() }}</span>
                            @elseif ($cooldowns[$account->id] > 0)
                                <span class="badge amber">cooling down {{ $cooldowns[$account->id] }}s</span>
                                <span class="muted">upstream rate limit</span>
                            @else
                                <span class="badge green">active</span>
                            @endif
                        </td>
                        <td class="num">{{ $account->profiles_count }}</td>
                        <td class="num">
                            @if ($rate && $rate['total'] > 0)
                                {{ round(100 * $rate['successful'] / $rate['total']) }}% <span class="muted">of {{ $rate['total'] }}</span>
                            @else
                                <span class="muted">no attempts</span>
                            @endif
                        </td>
                        <td>
                            @if ($account->isBlocked())
                                <form method="post" action="{{ route('accounts.unblock', $account) }}" class="inline">
                                    @csrf
                                    <button type="submit">Unblock</button>
                                </form>
                            @else
                                <form method="post" action="{{ route('accounts.block', $account) }}" class="inline">
                                    @csrf
                                    <input type="text" name="reason" placeholder="reason" size="12" aria-label="Block reason">
                                    <button type="submit" class="danger">Block</button>
                                </form>
                            @endif

                            @if ($account->profiles_count > 0)
                                <form method="post" action="{{ route('accounts.move-profiles', $account) }}" class="inline"
                                      onsubmit="return confirm(@js('Move all profiles of '.$account->name.'? Data and history are kept.'))">
                                    @csrf
                                    <select name="account_id" aria-label="Move all profiles to">
                                        @foreach ($moveTargets->where('id', '!=', $account->id) as $target)
                                            <option value="{{ $target->id }}">{{ $target->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit">Move all profiles</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No accounts yet. Run <code>php artisan db:seed</code>.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>Profiles <span class="muted">({{ $profiles->total() }}{{ $search !== '' ? ' matching "'.$search.'"' : '' }})</span></h2>
        <table>
            <thead>
                <tr>
                    <th>Profile</th><th>Account</th><th>Likes</th><th>Revision</th><th>Data</th><th>Last success</th><th>Last attempt</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($profiles as $profile)
                    @php($freshness = $profile->freshness())
                    <tr class="row-{{ $freshness }}">
                        <td><strong>{{ $profile->username }}</strong> @if ($profile->name)<span class="muted">{{ $profile->name }}</span>@endif</td>
                        <td>{{ $profile->account->name }}</td>
                        <td class="num">{{ $profile->likes === null ? '—' : number_format($profile->likes) }}</td>
                        <td class="num">{{ $profile->revision ?? '—' }}</td>
                        <td>
                            @switch ($freshness)
                                @case('fresh') <span class="badge green">fresh</span> @break
                                @case('stale') <span class="badge amber">stale</span> @break
                                @case('failing') <span class="badge red">failing ×{{ $profile->consecutive_failures }}</span> @break
                                @default <span class="badge grey">never fetched</span>
                            @endswitch
                        </td>
                        <td>{{ $profile->last_success_at?->diffForHumans() ?? '—' }}</td>
                        <td>
                            @if ($profile->last_outcome)
                                <span class="badge {{ $profile->last_outcome->isSuccessful() ? 'green' : ($profile->last_outcome->isFailure() ? 'red' : 'grey') }}">{{ $profile->last_outcome->value }}</span>
                                <span class="muted" title="{{ $profile->last_error }}">{{ \Illuminate\Support\Str::limit($profile->last_error, 40) }}</span>
                                <div class="muted">{{ $profile->last_attempt_at?->diffForHumans() }}</div>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>
                            <form method="post" action="{{ route('profiles.refresh', $profile) }}" class="inline">
                                @csrf
                                <button type="submit">Refresh</button>
                            </form>
                            <form method="post" action="{{ route('profiles.move', $profile) }}" class="inline">
                                @csrf
                                <select name="account_id" aria-label="Move {{ $profile->username }} to account">
                                    @foreach ($moveTargets->where('id', '!=', $profile->account_id) as $target)
                                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit">Move</button>
                            </form>
                            <a href="{{ url('/log-viewer').'?query='.urlencode('"profile_id":'.$profile->id) }}">logs</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No profiles{{ $search !== '' ? ' match "'.$search.'"' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($profiles->hasPages())
            <nav class="pages">
                @if ($profiles->onFirstPage()) <span class="muted">« Previous</span> @else <a href="{{ $profiles->previousPageUrl() }}">« Previous</a> @endif
                <span class="muted">page {{ $profiles->currentPage() }} of {{ $profiles->lastPage() }}</span>
                @if ($profiles->hasMorePages()) <a href="{{ $profiles->nextPageUrl() }}">Next »</a> @else <span class="muted">Next »</span> @endif
            </nav>
        @endif
    </main>
@endsection
