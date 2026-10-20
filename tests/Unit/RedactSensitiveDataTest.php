<?php

namespace Tests\Unit;

use App\Logging\RedactSensitiveData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactSensitiveDataTest extends TestCase
{
    private function process(string $message, array $context = [], array $extra = []): LogRecord
    {
        $record = new LogRecord(new DateTimeImmutable, 'refresh', Level::Info, $message, $context, $extra);

        return (new RedactSensitiveData)($record);
    }

    public function test_masks_credential_keys_at_any_depth_and_any_case(): void
    {
        $record = $this->process('fetch.started', [
            'headers' => [
                'Cookie' => 'sess=abc; auth_id=1',
                'Authorization' => 'Bearer xyz',
                'sign' => '12:abcdef:6a1b:65f0',
                'x-bc' => 'deadbeef',
                'app-token' => '33d57ade8c02dbc5a333db99ff9ae26a',
                'user-id' => '123',
                'Accept' => 'application/json',
            ],
            'config' => ['api_key' => 'k', 'password' => 'p'],
        ]);

        $headers = $record->context['headers'];
        foreach (['Cookie', 'Authorization', 'sign', 'x-bc', 'app-token', 'user-id'] as $key) {
            $this->assertSame(RedactSensitiveData::MASK, $headers[$key], $key);
        }
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame(RedactSensitiveData::MASK, $record->context['config']['api_key']);
        $this->assertSame(RedactSensitiveData::MASK, $record->context['config']['password']);
    }

    public function test_keeps_normal_fields_untouched(): void
    {
        $context = ['profile_id' => 1, 'likes' => 121000, 'revision' => 11, 'username' => 'madison420ivy', 'http_status' => 429];

        $this->assertSame($context, $this->process('fetch.finished', $context)->context);
    }

    public function test_masks_bearer_tokens_and_credential_query_params_in_strings(): void
    {
        $record = $this->process('calling https://api.test/users?api_key=SECRET&x=1', [
            'error' => 'upstream said: Bearer abc.def.ghi is invalid',
        ], ['url' => 'https://api.test/x?token=SECRET']);

        $this->assertStringNotContainsString('SECRET', $record->message);
        $this->assertStringContainsString('x=1', $record->message);
        $this->assertStringNotContainsString('abc.def.ghi', $record->context['error']);
        $this->assertStringNotContainsString('SECRET', $record->extra['url']);
    }
}
