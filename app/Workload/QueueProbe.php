<?php

namespace App\Workload;

use Illuminate\Support\Facades\Redis;

/**
 * Reads the Redis queue directly to measure waiting jobs.
 *
 * "Waiting" = ready (in the list, waiting for a worker) + delayed (re-queued for later).
 * Age = now - the job's original dispatch time (payload createdAt survives releases), so a job
 * that keeps being deferred keeps getting older, which is exactly the reported symptom.
 */
class QueueProbe
{
    public function __construct(private string $queue = 'refresh') {}

    /**
     * @param  array<int, string>  $accountByProfile  profile id => account name
     * @return array{ready: int, delayed: int, reserved: int, oldest: array<string, int|null>}
     */
    public function sample(array $accountByProfile, ?int $now = null): array
    {
        $now ??= time();
        $ready = Redis::lrange("queues:{$this->queue}", 0, -1);
        $delayed = Redis::zrange("queues:{$this->queue}:delayed", 0, -1);

        $oldest = array_fill_keys(array_unique(array_values($accountByProfile)), null);

        foreach ([...$ready, ...$delayed] as $payload) {
            [$profileId, $createdAt] = $this->parse($payload);
            $account = $accountByProfile[$profileId] ?? null;

            if ($account !== null && $createdAt !== null) {
                $oldest[$account] = max($oldest[$account] ?? 0, $now - $createdAt);
            }
        }

        return [
            'ready' => count($ready),
            'delayed' => count($delayed),
            'reserved' => (int) Redis::zcard("queues:{$this->queue}:reserved"),
            'oldest' => $oldest,
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null} [profile id, createdAt]
     */
    public function parse(string $payload): array
    {
        $data = json_decode($payload, true);
        $command = $data['data']['command'] ?? '';

        return [
            preg_match('/"profileId";i:(\d+);/', $command, $match) ? (int) $match[1] : null,
            $data['createdAt'] ?? null,
        ];
    }
}
