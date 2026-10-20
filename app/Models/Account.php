<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

#[Fillable(['name', 'status', 'blocked_at', 'blocked_reason'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'blocked_at' => 'datetime',
        ];
    }

    public function isBlocked(): bool
    {
        return $this->status === AccountStatus::Blocked;
    }

    public function block(string $reason): void
    {
        $this->update([
            'status' => AccountStatus::Blocked,
            'blocked_at' => now(),
            'blocked_reason' => $reason,
        ]);

        Log::channel('refresh')->warning('account.blocked', [
            'account_id' => $this->id,
            'reason' => $reason,
        ]);
    }

    public function unblock(): void
    {
        $this->update([
            'status' => AccountStatus::Active,
            'blocked_at' => null,
            'blocked_reason' => null,
        ]);

        Log::channel('refresh')->info('account.unblocked', ['account_id' => $this->id]);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', AccountStatus::Active);
    }

    /**
     * @return HasMany<Profile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /**
     * @return HasMany<RefreshAttempt, $this>
     */
    public function refreshAttempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }
}
