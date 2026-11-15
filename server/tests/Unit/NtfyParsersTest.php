<?php

namespace Tests\Unit;

use App\Support\Ntfy\ActionParser;
use App\Support\Ntfy\DelayParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NtfyParsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    }

    /** @return array<string, array{string, int}> */
    public static function durations(): array
    {
        return [
            'minutes' => ['30m', 30 * 60],
            'hours' => ['2h', 2 * 3600],
            'combined' => ['1h30m', 90 * 60],
            'days' => ['1d', 86400],
            'words' => ['10 minutes', 600],
        ];
    }

    #[DataProvider('durations')]
    public function test_durations_are_parsed(string $delay, int $seconds): void
    {
        $this->assertSame($seconds, (int) CarbonImmutable::now()->diffInSeconds(DelayParser::parse($delay)));
    }

    public function test_timestamps_and_invalid_delays(): void
    {
        $this->assertSame(1794736800, DelayParser::parse('1794736800')?->getTimestamp());
        $this->assertNull(DelayParser::parse('soon'));
        $this->assertNull(DelayParser::parse(''));
    }

    public function test_short_action_format(): void
    {
        $actions = ActionParser::parse('view, Open portal, https://example.com; http, Close door, https://api.example.com/door, method=PUT, headers.X-Key=abc, body={"open":false}');

        $this->assertCount(2, $actions);
        $this->assertSame(['action' => 'view', 'label' => 'Open portal', 'url' => 'https://example.com'], array_intersect_key($actions[0], array_flip(['action', 'label', 'url'])));
        $this->assertSame('PUT', $actions[1]['method']);
        $this->assertSame(['X-Key' => 'abc'], $actions[1]['headers']);
        $this->assertSame('{"open":false}', $actions[1]['body']);
    }

    public function test_json_actions_and_limits(): void
    {
        $json = json_encode(array_fill(0, 5, ['action' => 'view', 'label' => 'Open', 'url' => 'https://example.com']));

        $this->assertCount(ActionParser::MAX_ACTIONS, ActionParser::parse($json));
        $this->assertSame([], ActionParser::parse('unknown, Label, https://example.com'));
        $this->assertSame([], ActionParser::parse(null));
    }
}
