<?php

namespace App\Models;

use App\Enums\RefreshOutcome;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

#[Fillable([
    'account_id', 'username',
    'upstream_id', 'name', 'about', 'avatar_url', 'header_url',
    'likes', 'posts_count', 'photos_count', 'videos_count', 'audios_count',
    'revision',
    'last_success_at', 'last_attempt_at', 'last_outcome', 'last_error', 'consecutive_failures',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, Searchable;

    /**
     * Columns that come from upstream and describe the stored version of the profile.
     * They change together: only from a validated response, or when merging duplicates.
     */
    public const DATA_COLUMNS = [
        'upstream_id', 'name', 'about', 'avatar_url', 'header_url',
        'likes', 'posts_count', 'photos_count', 'videos_count', 'audios_count',
        'revision', 'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'upstream_id' => 'integer',
            'likes' => 'integer',
            'posts_count' => 'integer',
            'photos_count' => 'integer',
            'videos_count' => 'integer',
            'audios_count' => 'integer',
            'revision' => 'integer',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_outcome' => RefreshOutcome::class,
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * Dashboard badge: never (no valid data yet), failing (last attempts failed, showing older data),
     * stale (no successful refresh within refresh.stale_after_minutes) or fresh.
     */
    public function freshness(): string
    {
        return match (true) {
            $this->last_success_at === null => 'never',
            $this->consecutive_failures > 0 => 'failing',
            $this->last_success_at->lt(now()->subMinutes(config('refresh.stale_after_minutes'))) => 'stale',
            default => 'fresh',
        };
    }

    /**
     * Columns the Scout database engine searches with LIKE.
     */
    public function toSearchableArray(): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<RefreshAttempt, $this>
     */
    public function refreshAttempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }
}
