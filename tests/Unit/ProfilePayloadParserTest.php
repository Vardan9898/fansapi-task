<?php

namespace Tests\Unit;

use App\Refresh\InvalidPayload;
use App\Refresh\ProfilePayloadParser;
use App\Upstream\UpstreamResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfilePayloadParserTest extends TestCase
{
    private function parse(string $body, bool $truncated = false)
    {
        return (new ProfilePayloadParser)->parse(new UpstreamResponse(200, $body, 1, truncated: $truncated));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__."/../Fixtures/upstream/{$name}.json");
    }

    public function test_parses_the_old_top_level_format(): void
    {
        $parsed = $this->parse($this->fixture('old_format'));

        $this->assertSame([120000, 10, 'top_level'], [$parsed->likes, $parsed->revision, $parsed->format]);
    }

    public function test_parses_the_new_nested_format(): void
    {
        $parsed = $this->parse($this->fixture('new_format'));

        $this->assertSame([121000, 11, 'nested'], [$parsed->likes, $parsed->revision, $parsed->format]);
    }

    public function test_accepts_both_shapes_when_they_agree(): void
    {
        $parsed = $this->parse('{"likes":5,"profile":{"likes":5},"revision":3}');

        $this->assertSame(5, $parsed->likes);
    }

    public function test_zero_likes_is_valid_when_explicit(): void
    {
        $this->assertSame(0, $this->parse('{"profile":{"likes":0},"revision":11}')->likes);
    }

    public static function invalidBodies(): array
    {
        return [
            'empty body (429/500 style)' => ['', 'Empty response body'],
            'not json' => ['<html>busy</html>', 'not a JSON object'],
            'json but not an object' => ['123', 'not a JSON object'],
            'likes missing everywhere' => ['{"revision":12}', 'likes not found'],
            'profile without likes' => ['{"profile":{"name":"x"},"revision":12}', 'likes not found'],
            'likes null' => ['{"profile":{"likes":null},"revision":12}', 'likes must be an integer'],
            'likes negative' => ['{"likes":-1,"revision":12}', 'likes must be an integer'],
            'likes as string' => ['{"likes":"121000","revision":12}', 'likes must be an integer'],
            'likes as float' => ['{"likes":1.5,"revision":12}', 'likes must be an integer'],
            'revision missing' => ['{"likes":5}', 'revision must be an integer'],
            'revision as string' => ['{"likes":5,"revision":"11"}', 'revision must be an integer'],
            'shapes disagree' => ['{"likes":5,"profile":{"likes":6},"revision":3}', 'differs'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_rejects_invalid_bodies_instead_of_defaulting(string $body, string $reason): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($reason);

        $this->parse($body);
    }

    public function test_rejects_truncated_body(): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage('size limit');

        $this->parse('', truncated: true);
    }
}
