<?php

namespace Tests\Unit;

use App\Upstream\Fake\FakeResponses;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FakeResponsesTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__."/../Fixtures/upstream/{$name}.json"), true);
    }

    public function test_old_and_new_format_match_the_brief_fixtures(): void
    {
        $old = FakeResponses::render(['type' => 'old_format', 'likes' => 120000, 'revision' => 10]);
        $new = FakeResponses::render(['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $this->assertSame(200, $old['status']);
        $this->assertSame($this->fixture('old_format'), json_decode($old['body'], true));
        $this->assertSame(200, $new['status']);
        $this->assertSame($this->fixture('new_format'), json_decode($new['body'], true));
    }

    public function test_invalid_has_no_likes_anywhere(): void
    {
        $invalid = FakeResponses::render(['type' => 'invalid', 'revision' => 12]);

        $this->assertSame($this->fixture('invalid_missing_likes'), json_decode($invalid['body'], true));
    }

    public function test_failures_have_empty_bodies_like_the_brief(): void
    {
        $this->assertSame(['status' => 429, 'body' => ''], FakeResponses::render(['type' => 'rate_limited']));
        $this->assertSame(['status' => 500, 'body' => ''], FakeResponses::render(['type' => 'server_error']));
        $this->assertSame(404, FakeResponses::render(['type' => 'not_found'])['status']);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FakeResponses::render(['type' => 'nope']);
    }
}
