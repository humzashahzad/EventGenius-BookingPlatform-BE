<?php

namespace Tests\Unit;

use App\Services\Assistant\DateParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantDateParserTest extends TestCase
{
    public static function cases(): array
    {
        // Today is Wednesday 25 November 2026.
        return [
            ['is it free on 12 March?', '2027-03-12'],
            ['12th of march 2028', '2028-03-12'],
            ['December 5th', '2026-12-05'],
            ['on 5 dec', '2026-12-05'],
            ['2027-01-09', '2027-01-09'],
            ['15/12', '2026-12-15'],
            ['15/12/2026', '2026-12-15'],
            ['tomorrow', '2026-11-26'],
            ['kal free hai?', '2026-11-26'],
            ['parson', '2026-11-27'],
            ['next saturday', '2026-11-28'],
            ['this friday', '2026-11-27'],
            ['jumma ko', '2026-11-27'],
            ['itwar', '2026-11-29'],
            ['this weekend', '2026-11-28'],
            ['۱۲ مارچ', '2027-03-12'],
            ['25 November', '2026-11-25'],
            ['today', '2026-11-25'],
        ];
    }

    #[DataProvider('cases')]
    public function test_parses(string $text, string $expected): void
    {
        $r = (new DateParser())->parse($text, CarbonImmutable::create(2026, 11, 25));
        $this->assertNotNull($r, $text);
        $this->assertSame($expected, $r['date'], $text);
    }

    public function test_no_date_in_plain_requests(): void
    {
        $p = new DateParser(CarbonImmutable::create(2026, 11, 25));
        foreach (['wedding hall in Lahore for 300 guests', 'rating above 4.5', 'may I see more', 'under 3,000 per head', '31 February'] as $t) {
            $this->assertNull($p->parse($t), $t);
        }
    }
}
