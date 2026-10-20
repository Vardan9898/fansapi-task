<?php

namespace App\Upstream;

use App\Logging\RefreshLog;
use App\Models\Profile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches one profile from the account's upstream (config/upstream.php).
 * Transport only: returns the raw response or throws UpstreamTimeout. No retries here;
 * retry/backoff decisions belong to the queue job, which knows the attempt count.
 */
class UpstreamClient
{
    public function fetchProfile(Profile $profile): UpstreamResponse
    {
        $driver = $profile->account->upstream;
        $config = config("upstream.drivers.{$driver->value}");
        $limit = config('upstream.max_body_bytes');
        $url = rtrim($config['base_url'], '/').strtr($config['profile_path'], [
            '{account}' => rawurlencode($profile->account->name),
            '{username}' => rawurlencode($profile->username),
        ]);

        RefreshLog::debug('upstream.request', ['driver' => $driver->value, 'url' => $url]);

        $startedAt = hrtime(true);
        $status = null;

        try {
            // curl handler: `timeout` is a hard limit for the whole request, `connect_timeout` for the TCP/TLS handshake
            $response = Http::acceptJson()
                ->when($config['token'], fn ($request, $token) => $request->withToken($token))
                ->timeout(config('upstream.timeout'))
                ->connectTimeout(config('upstream.connect_timeout'))
                ->withOptions([
                    'on_headers' => function (ResponseInterface $headers) use (&$status) {
                        $status = $headers->getStatusCode();
                    },
                    // Abort as soon as the declared or downloaded size passes the limit (bounded memory)
                    'progress' => function ($declaredBytes, $downloadedBytes) use ($limit) {
                        if ($declaredBytes > $limit || $downloadedBytes > $limit) {
                            throw new ResponseTooLarge("Body exceeds {$limit} bytes");
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            if ($this->causedBy($e, ResponseTooLarge::class)) {
                return $this->logged(new UpstreamResponse(
                    status: $status ?? 200,
                    body: '',
                    durationMs: $this->elapsedMs($startedAt),
                    truncated: true,
                ), $driver->value);
            }

            RefreshLog::warning('upstream.timeout', [
                'driver' => $driver->value,
                'duration_ms' => $this->elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            throw new UpstreamTimeout($e->getMessage(), previous: $e);
        }

        // Second guard for handlers that don't report progress (e.g. Http::fake in tests)
        $body = $response->body();
        $truncated = strlen($body) > $limit;

        return $this->logged(new UpstreamResponse(
            status: $response->status(),
            body: $truncated ? '' : $body,
            durationMs: $this->elapsedMs($startedAt),
            retryAfter: $response->header('Retry-After') ?: null,
            truncated: $truncated,
        ), $driver->value);
    }

    private function logged(UpstreamResponse $response, string $driver): UpstreamResponse
    {
        RefreshLog::debug('upstream.response', [
            'driver' => $driver,
            'status' => $response->status,
            'duration_ms' => $response->durationMs,
            'body_bytes' => $response->bodyBytes(),
            'truncated' => $response->truncated,
            'retry_after' => $response->retryAfter,
        ]);

        return $response;
    }

    private function causedBy(Throwable $e, string $class): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
