<?php

namespace App\Services\Assistant;

use Carbon\CarbonImmutable;

/**
 * Finds one event date in a chat message: "12 March", "March 12th 2027", "12/3", "2027-03-12",
 * "tomorrow", "next Saturday", "this weekend", plus Roman Urdu ("kal", "parson", "jumma", "itwar")
 * and Urdu-script month and day names. Dates without a year roll over to the next year once passed.
 */
class DateParser
{
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
        'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
        'december' => 12, 'dec' => 12,
    ];

    /** ISO weekday (1 = Monday) by English, Roman Urdu and abbreviated names. */
    private const DAYS = [
        'monday' => 1, 'mon' => 1, 'peer' => 1, 'pir' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2, 'mangal' => 2,
        'wednesday' => 3, 'wed' => 3, 'budh' => 3, 'budhwar' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'jumerat' => 4, 'jumeraat' => 4, 'jumairat' => 4,
        'friday' => 5, 'fri' => 5, 'juma' => 5, 'jumma' => 5, 'jummah' => 5, 'jumah' => 5,
        'saturday' => 6, 'sat' => 6, 'sanichar' => 6,
        'sunday' => 7, 'sun' => 7, 'itwar' => 7, 'itwaar' => 7, 'itvar' => 7,
    ];

    private const URDU = [
        'جنوری' => 'january', 'فروری' => 'february', 'مارچ' => 'march', 'اپریل' => 'april', 'مئی' => 'may', 'جون' => 'june',
        'جولائی' => 'july', 'اگست' => 'august', 'ستمبر' => 'september', 'اکتوبر' => 'october', 'نومبر' => 'november',
        'دسمبر' => 'december', 'پیر' => 'monday', 'منگل' => 'tuesday', 'بدھ' => 'wednesday', 'جمعرات' => 'thursday',
        'جمعہ' => 'friday', 'ہفتہ' => 'saturday', 'اتوار' => 'sunday', 'آج' => 'today', 'کل' => 'tomorrow', 'پرسوں' => 'parson',
        'اگلے' => 'next', 'اگلا' => 'next',
    ];

    public function __construct(private ?CarbonImmutable $today = null) {}

    /**
     * @return array{date: string, label: string}|null  date as Y-m-d, label like "Saturday 12 December 2026"
     */
    public function parse(string $text, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? $this->today ?? CarbonImmutable::now())->startOfDay();
        $s = mb_strtolower(strtr($text, self::URDU));
        $s = strtr($s, array_combine(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']
        ));
        $s = ' ' . preg_replace('/\s+/u', ' ', $s) . ' ';
        $monthRe = implode('|', array_keys(self::MONTHS));

        $date = null;
        if (preg_match('/\b(20\d\d)-(\d{1,2})-(\d{1,2})\b/', $s, $m)) {
            $date = $this->make((int) $m[1], (int) $m[2], (int) $m[3]);
        } elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $s, $m)
            || preg_match('/\b(\d{1,2})[.-](\d{1,2})[.-](\d{2,4})\b/', $s, $m)) {
            // Pakistani order: day/month[/year].
            $date = $this->withYear((int) $m[2], (int) $m[1], $m[3] ?? null, $today);
        } elseif (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?(' . $monthRe . ')\b\.?,?(?:\s+(\d{4}))?/u', $s, $m)) {
            $date = $this->withYear(self::MONTHS[$m[2]], (int) $m[1], $m[3] ?? null, $today);
        } elseif (preg_match('/\b(' . $monthRe . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b,?(?:\s+(\d{4}))?/u', $s, $m)) {
            $date = $this->withYear(self::MONTHS[$m[1]], (int) $m[2], $m[3] ?? null, $today);
        } elseif (preg_match('/\b(today|aaj|tonight)\b/', $s)) {
            $date = $today;
        } elseif (preg_match('/\b(day after tomorrow|parson|parso|parsoon)\b/', $s)) {
            $date = $today->addDays(2);
        } elseif (preg_match('/\b(tomorrow|tmrw|tmr|kal)\b/', $s)) {
            $date = $today->addDay();
        } elseif (preg_match('/\b(this|next|coming|agle|agla|aglay)?\s*(weekend)\b/', $s, $m)) {
            $date = $this->nextWeekday($today, 6, ($m[1] ?? '') !== '' && $m[1] !== 'this' && $m[1] !== 'coming');
        } elseif (preg_match('/\b(this|next|coming|agle|agla|aglay|on)?\s*(' . implode('|', array_keys(self::DAYS)) . ')\b/', $s, $m)) {
            // "sat"/"sun"/"mon"/"wed" are too common as plain words unless a cue word comes first.
            $short = in_array($m[2], ['sat', 'sun', 'mon', 'wed', 'peer', 'pir', 'budh', 'mangal'], true);
            if (!$short || ($m[1] ?? '') !== '') {
                $date = $this->nextWeekday($today, self::DAYS[$m[2]], false);
            }
        }

        if (!$date) {
            return null;
        }
        return ['date' => $date->toDateString(), 'label' => $date->format('l j F Y')];
    }

    private function withYear(int $month, int $day, ?string $year, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($year !== null && $year !== '') {
            $y = (int) $year;
            $y = $y < 100 ? 2000 + $y : $y;
            return $this->make($y, $month, $day);
        }
        $date = $this->make($today->year, $month, $day);
        if ($date && $date->lt($today)) {
            $date = $this->make($today->year + 1, $month, $day);
        }
        return $date;
    }

    private function make(int $y, int $m, int $d): ?CarbonImmutable
    {
        if ($y < 2000 || $y > 2100 || !checkdate($m, $d, $y)) {
            return null;
        }
        return CarbonImmutable::create($y, $m, $d)->startOfDay();
    }

    /** First given weekday strictly after today ("next" weekend skips this one if today is Sat/Sun). */
    private function nextWeekday(CarbonImmutable $today, int $isoDay, bool $skipCurrentWeekend): CarbonImmutable
    {
        $days = ($isoDay - $today->dayOfWeekIso + 7) % 7;
        $days = $days === 0 ? 7 : $days;
        $date = $today->addDays($days);
        if ($skipCurrentWeekend && $today->dayOfWeekIso >= 6) {
            $date = $date->addDays(7);
        }
        return $date;
    }
}
