<?php

namespace App\Models;

use App\Enums\RefreshOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: rows are inserted once per attempt and their content is never updated.
 * Only exception: MoveProfiles re-parents profile_id when merging duplicate profiles,
 * so history follows the surviving row (account_id stays = who made the attempt).
 */
#[Fillable([
    'profile_id', 'account_id', 'job_uuid', 'attempt', 'handler',
    'outcome', 'http_status', 'duration_ms', 'response_format',
    'revision_before', 'revision_received', 'changes', 'error',
])]
class RefreshAttempt extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Rows older than the retention window (same as refresh log files). Deleted by the
     * scheduled `model:prune` in chunked DELETE queries, without loading models into memory.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('refresh.retention_days')));
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'outcome' => RefreshOutcome::class,
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'revision_before' => 'integer',
            'revision_received' => 'integer',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
