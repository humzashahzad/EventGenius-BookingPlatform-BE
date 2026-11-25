<?php

namespace Tests\Unit;

use App\Services\Search\SearchQueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!class_exists(SearchQueryParser::class)) {
    // vendor/ may be shared with another checkout whose autoloader points at its own app/.
    require_once __DIR__ . '/../../app/Services/Search/SearchQueryParser.php';
}

class SearchQueryParserTest extends TestCase
{
    private const KEYS = ['raw', 'terms', 'city', 'event_type', 'guests', 'max_price', 'min_price', 'intents', 'amenities', 'chips'];

    private static function vocabulary(): array
    {
        $slugs = ['weddings', 'birthday_parties', 'corporate_events', 'conferences', 'seminars_workshops', 'engagement_parties',
            'graduation_parties', 'baby_showers', 'bridal_showers', 'anniversaries', 'concerts_music_events', 'festivals', 'religious_events'];
        $names = ['seminars_workshops' => 'Seminars & Workshops', 'concerts_music_events' => 'Concerts & Music Events'];

        return [
            'cities' => ['Lahore', 'Karachi', 'Islamabad', 'Faisalabad', 'Multan', 'Rawalpindi', 'Peshawar', 'Kamalia'],
            'categories' => array_map(fn($s) => ['slug' => $s, 'name' => $names[$s] ?? ucwords(str_replace('_', ' ', $s))], $slugs),
            'amenities' => ['Air Conditioning', 'Valet Parking', 'Generator Backup', 'Bridal Room', 'Prayer Area', 'Sound System',
                'Stage Decoration', 'Photography', 'Fireworks', 'Wi-Fi', 'Wheelchair Access', 'Projector & Screen', 'LED Wall',
                'DJ Setup', 'Live BBQ Station'],
        ];
    }

    private function parse(?string $q, ?array $vocabulary = null): array
    {
        return (new SearchQueryParser($vocabulary ?? self::vocabulary()))->parse($q);
    }

    public function test_contract_example(): void
    {
        $r = $this->parse('shadi hall in lhr for 300 guests under 3k');

        $this->assertSame('Lahore', $r['city']);
        $this->assertSame('weddings', $r['event_type']);
        $this->assertSame(300, $r['guests']);
        $this->assertSame(3000.0, $r['max_price']);
        $this->assertNull($r['min_price']);
        $this->assertSame(['hall'], $r['terms']);
        $this->assertSame([], $r['intents']);
    }

    public function test_all_keys_present_for_empty_and_null_input(): void
    {
        foreach ([null, '', '   ', '!!!'] as $q) {
            $r = $this->parse($q);
            $this->assertSame(self::KEYS, array_keys($r));
            $this->assertNull($r['city']);
            $this->assertNull($r['guests']);
            $this->assertSame([], $r['terms']);
            $this->assertSame([], $r['chips']);
        }
        $this->assertSame('', $this->parse(null)['raw']);
        $this->assertSame('hall', $this->parse('  hall  ')['raw']);
    }

    public static function eventWords(): array
    {
        return [
            ['shadi', 'weddings'], ['Shaadi', 'weddings'], ['marriage hall', 'weddings'], ['barat', 'weddings'],
            ['baraat venue', 'weddings'], ['walima', 'weddings'], ['valima', 'weddings'], ['nikah', 'weddings'],
            ['nikkah', 'weddings'], ['mehndi night', 'weddings'], ['mehendi', 'weddings'],
            ['mangni', 'engagement_parties'], ['engagement', 'engagement_parties'], ['ring ceremony', 'engagement_parties'],
            ['birthday', 'birthday_parties'], ['bday', 'birthday_parties'], ['salgirah', 'birthday_parties'],
            ['conference', 'conferences'], ['seminar', 'seminars_workshops'], ['workshop', 'seminars_workshops'],
            ['training session', 'seminars_workshops'], ['corporate dinner', 'corporate_events'], ['office party', 'corporate_events'],
            ['company meeting', 'corporate_events'], ['concert', 'concerts_music_events'], ['qawwali', 'concerts_music_events'],
            ['music gig', 'concerts_music_events'], ['graduation', 'graduation_parties'], ['convocation', 'graduation_parties'],
            ['baby shower', 'baby_showers'], ['aqiqa', 'baby_showers'], ['bridal shower', 'bridal_showers'],
            ['anniversary', 'anniversaries'], ['festival', 'festivals'], ['mela', 'festivals'],
            ['milad', 'religious_events'], ['majlis', 'religious_events'], ['iftar dinner', 'religious_events'], ['dars', 'religious_events'],
            ['Graduation Parties', 'graduation_parties'], ['festivals', 'festivals'],
        ];
    }

    #[DataProvider('eventWords')]
    public function test_event_words_map_to_category_slugs(string $q, string $slug): void
    {
        $this->assertSame($slug, $this->parse($q)['event_type'], $q);
    }

    public function test_event_words_only_map_to_active_categories(): void
    {
        $vocab = self::vocabulary();
        $vocab['categories'] = [['slug' => 'birthday_parties', 'name' => 'Birthday Parties']];

        $r = $this->parse('shadi in lahore', $vocab);
        $this->assertNull($r['event_type']);
        $this->assertSame('birthday_parties', $this->parse('bday', $vocab)['event_type']);
    }

    public function test_first_event_mentioned_wins(): void
    {
        $this->assertSame('birthday_parties', $this->parse('birthday or anniversary')['event_type']);
        $this->assertSame('anniversaries', $this->parse('anniversary or birthday')['event_type']);
    }

    public static function cityAliases(): array
    {
        return [
            ['lhr', 'Lahore'], ['LAHORE', 'Lahore'], ['khi', 'Karachi'], ['isb', 'Islamabad'], ['isl', 'Islamabad'],
            ['pindi', 'Rawalpindi'], ['rwp', 'Rawalpindi'], ['fsd', 'Faisalabad'], ['lyallpur', 'Faisalabad'],
            ['mul', 'Multan'], ['pesh', 'Peshawar'], ['psh', 'Peshawar'], ['kamalia', 'Kamalia'],
        ];
    }

    #[DataProvider('cityAliases')]
    public function test_city_aliases(string $alias, string $city): void
    {
        $r = $this->parse("hall in $alias");
        $this->assertSame($city, $r['city']);
        $this->assertSame(['hall'], $r['terms']);
    }

    public function test_city_aliases_require_city_in_vocabulary(): void
    {
        $vocab = self::vocabulary();
        $vocab['cities'] = ['Lahore'];

        $r = $this->parse('hall in khi', $vocab);
        $this->assertNull($r['city']);
        $this->assertContains('khi', $r['terms']);
    }

    public function test_fuzzy_city_typos(): void
    {
        $this->assertSame('Islamabad', $this->parse('venue in islamabd')['city']);
        $this->assertSame('Karachi', $this->parse('karachii marquee')['city']);
        $this->assertSame('Lahore', $this->parse('lahroe wedding')['city']);
        $this->assertSame('Rawalpindi', $this->parse('rawalpndi hall')['city']);
        $this->assertSame('Peshawar', $this->parse('peshwar')['city']);
    }

    public function test_fuzzy_city_is_conservative(): void
    {
        // Short words are never fuzzy-matched, nor are known keywords.
        $this->assertNull($this->parse('lahr')['city']);
        $this->assertNull($this->parse('garden lawn')['city']);
        $this->assertNull($this->parse('marquee')['city']);
        // Distance > 2 is not a typo.
        $this->assertNull($this->parse('london')['city']);
        // Only vocabulary cities are candidates.
        $vocab = self::vocabulary();
        $vocab['cities'] = ['Lahore'];
        $this->assertNull($this->parse('karachii', $vocab)['city']);
    }

    public static function guestQueries(): array
    {
        return [
            ['300 guests', 300], ['for 250 people', 250], ['150 persons', 150], ['80 pax', 80], ['500 log', 500],
            ['400 afrad', 400], ['200 logon ke liye', 200], ['for 300', 300], ['1,200 guests', 1200], ['300+ guests', 300],
            ['200 to 300 guests', 300], ['capacity 150', 150], ['over 200', 200], ['hall 300', 300],
        ];
    }

    #[DataProvider('guestQueries')]
    public function test_guest_counts(string $q, int $guests): void
    {
        $r = $this->parse($q);
        $this->assertSame($guests, $r['guests'], $q);
        $this->assertNull($r['max_price'], $q);
    }

    public static function budgetQueries(): array
    {
        return [
            ['under 3000', 3000.0], ['below 2500', 2500.0], ['less than 4000', 4000.0], ['max 3500', 3500.0],
            ['upto 2000', 2000.0], ['up to 2000', 2000.0], ['within 5000', 5000.0], ['budget 3000', 3000.0],
            ['3k', 3000.0], ['2.5k per head', 2500.0], ['3,000', 3000.0], ['Rs 3000', 3000.0], ['Rs.3000', 3000.0],
            ['PKR 3,500 per head', 3500.0], ['3000 pkr', 3000.0], ['3000/head', 3000.0], ['2500 per person', 2500.0],
            ['3000 se kam', 3000.0], ['4000 tak', 4000.0], ['my budget is 2800', 2800.0], ['1500/-', 1500.0],
        ];
    }

    #[DataProvider('budgetQueries')]
    public function test_budgets(string $q, float $max): void
    {
        $r = $this->parse($q);
        $this->assertSame($max, $r['max_price'], $q);
        $this->assertNull($r['guests'], $q);
    }

    public function test_price_ranges(): void
    {
        $r = $this->parse('between 2000 and 4000');
        $this->assertSame(2000.0, $r['min_price']);
        $this->assertSame(4000.0, $r['max_price']);

        $r = $this->parse('rs 4000-2000 per head');
        $this->assertSame(2000.0, $r['min_price']);
        $this->assertSame(4000.0, $r['max_price']);

        $r = $this->parse('2k to 3.5k');
        $this->assertSame(2000.0, $r['min_price']);
        $this->assertSame(3500.0, $r['max_price']);
    }

    public function test_min_price(): void
    {
        $this->assertSame(2500.0, $this->parse('above 2500')['min_price']);
        $this->assertSame(1500.0, $this->parse('starting from rs 1500')['min_price']);
        $this->assertNull($this->parse('above 2500')['max_price']);
    }

    public function test_guests_and_budget_together(): void
    {
        $r = $this->parse('Rs. 3,000 per head mehndi venue pindi 500 log');
        $this->assertSame(3000.0, $r['max_price']);
        $this->assertSame(500, $r['guests']);
        $this->assertSame('Rawalpindi', $r['city']);
        $this->assertSame('weddings', $r['event_type']);

        $r = $this->parse('for 200 guests under 2500');
        $this->assertSame(200, $r['guests']);
        $this->assertSame(2500.0, $r['max_price']);
    }

    public function test_numbers_that_are_not_guests_or_budget(): void
    {
        $r = $this->parse('5 star marquee in karachi');
        $this->assertNull($r['guests']);
        $this->assertNull($r['max_price']);
        $this->assertSame(['luxury'], $r['intents']);
        $this->assertSame(['marquee'], $r['terms']);

        $r = $this->parse('birthday on 25 december 2026 for 3 hours');
        $this->assertNull($r['guests']);
        $this->assertNull($r['max_price']);

        $r = $this->parse('top 10 halls in lahore');
        $this->assertNull($r['guests']);
        $this->assertSame(['top_rated'], $r['intents']);
        $this->assertSame(['hall'], $r['terms']);

        $this->assertNull($this->parse('dha phase 5')['guests']);
    }

    public static function intentQueries(): array
    {
        return [
            ['best hall', 'top_rated'], ['top rated venues', 'top_rated'], ['highest rated marquee', 'top_rated'],
            ['popular', 'popular'], ['trending venues', 'popular'], ['cheap', 'cheap'], ['affordable hall', 'cheap'],
            ['sasta hall', 'cheap'], ['budget venue', 'cheap'], ['luxury', 'luxury'], ['premium marquee', 'luxury'],
            ['5-star hotel', 'luxury'], ['five star', 'luxury'], ['near me', 'near_me'], ['nearby', 'near_me'],
            ['close to me', 'near_me'], ['new venues', 'new'], ['newest', 'new'], ['latest', 'new'],
        ];
    }

    #[DataProvider('intentQueries')]
    public function test_intents(string $q, string $intent): void
    {
        $this->assertContains($intent, $this->parse($q)['intents'], $q);
    }

    public function test_multiple_intents_and_no_leftover_stopwords(): void
    {
        $r = $this->parse('top rated cheap rooftop near me');
        $this->assertSame(['top_rated', 'cheap', 'near_me'], $r['intents']);
        $this->assertSame(['rooftop'], $r['terms']);
    }

    public function test_amenities(): void
    {
        $r = $this->parse('hall with ac and parking');
        $this->assertSame(['Air Conditioning', 'Valet Parking'], $r['amenities']);
        $this->assertSame(['hall'], $r['terms']);

        $this->assertSame(['Wi-Fi'], $this->parse('wifi')['amenities']);
        $this->assertSame(['Wi-Fi'], $this->parse('wi-fi')['amenities']);
        $this->assertSame(['Projector & Screen'], $this->parse('projector')['amenities']);
        $this->assertSame(['Generator Backup'], $this->parse('generator')['amenities']);
        $this->assertSame(['DJ Setup'], $this->parse('dj')['amenities']);
        $this->assertSame(['Live BBQ Station'], $this->parse('bbq')['amenities']);
        $this->assertSame(['Bridal Room'], $this->parse('bridal room')['amenities']);
        $this->assertSame(['Prayer Area'], $this->parse('namaz area')['amenities']);
    }

    public function test_amenities_only_from_vocabulary(): void
    {
        $vocab = self::vocabulary();
        $vocab['amenities'] = ['Valet Parking'];

        $r = $this->parse('hall with wifi and parking', $vocab);
        $this->assertSame(['Valet Parking'], $r['amenities']);
        $this->assertContains('wifi', $r['terms']);
    }

    public function test_terms_keep_venue_types_and_drop_stopwords(): void
    {
        $r = $this->parse('Looking for a nice garden lawn or banquet halls with the rooftop in Lahore please');
        $this->assertSame(['garden', 'lawn', 'banquet', 'hall', 'rooftop'], $r['terms']);
        $this->assertSame('Lahore', $r['city']);

        $this->assertSame(['dha', 'marquee'], $this->parse('DHA marquee')['terms']);
    }

    public function test_punctuation_and_mixed_case(): void
    {
        $r = $this->parse('WEDDING Hall, LAHORE!!! 300 People; 2,500/Head???');
        $this->assertSame('weddings', $r['event_type']);
        $this->assertSame('Lahore', $r['city']);
        $this->assertSame(300, $r['guests']);
        $this->assertSame(2500.0, $r['max_price']);
        $this->assertSame(['hall'], $r['terms']);
    }

    public function test_roman_urdu_mix(): void
    {
        $r = $this->parse('mujhe barat ke liye hall chahiye faisalabad mein 400 afrad 2500 se kam');
        $this->assertSame('weddings', $r['event_type']);
        $this->assertSame('Faisalabad', $r['city']);
        $this->assertSame(400, $r['guests']);
        $this->assertSame(2500.0, $r['max_price']);
        $this->assertSame(['hall'], $r['terms']);
    }

    public function test_urdu_script(): void
    {
        $r = $this->parse('شادی ہال لاہور ۳۰۰ مہمان');
        $this->assertSame('weddings', $r['event_type']);
        $this->assertSame('Lahore', $r['city']);
        $this->assertSame(300, $r['guests']);
        $this->assertSame(['hall'], $r['terms']);
    }

    public function test_chips_have_human_labels(): void
    {
        $r = $this->parse('shadi hall in lhr for 300 guests under 3k top rated with ac');
        $chips = [];
        foreach ($r['chips'] as $chip) {
            $this->assertSame(['type', 'label', 'param', 'value'], array_keys($chip));
            $chips[$chip['type']][] = $chip;
        }

        $this->assertSame('Weddings', $chips['event_type'][0]['label']);
        $this->assertSame('weddings', $chips['event_type'][0]['value']);
        $this->assertSame('event_type', $chips['event_type'][0]['param']);
        $this->assertSame('Lahore', $chips['city'][0]['label']);
        $this->assertSame('city', $chips['city'][0]['param']);
        $this->assertSame('300 guests', $chips['guests'][0]['label']);
        $this->assertSame(300, $chips['guests'][0]['value']);
        $this->assertSame('Up to PKR 3,000 / head', $chips['max_price'][0]['label']);
        $this->assertSame(3000.0, $chips['max_price'][0]['value']);
        $this->assertSame('Top rated', $chips['intent'][0]['label']);
        $this->assertSame('Air Conditioning', $chips['amenity'][0]['label']);
        $this->assertSame('hall', $chips['term'][0]['value']);
    }

    public function test_min_price_chip_label(): void
    {
        $r = $this->parse('between 2000 and 4500');
        $labels = array_column($r['chips'], 'label', 'type');
        $this->assertSame('From PKR 2,000 / head', $labels['min_price']);
        $this->assertSame('Up to PKR 4,500 / head', $labels['max_price']);
    }

    public function test_plain_venue_name_is_kept_as_terms(): void
    {
        $r = $this->parse('Royal Palace Kamalia');
        $this->assertSame(['royal', 'palace'], $r['terms']);
        $this->assertSame('Kamalia', $r['city']);
        $this->assertNull($r['event_type']);
    }

    public function test_empty_vocabulary_still_parses_numbers_and_intents(): void
    {
        $r = $this->parse('cheap hall in lahore for 300 under 2000', ['cities' => [], 'categories' => [], 'amenities' => []]);
        $this->assertNull($r['city']);
        $this->assertNull($r['event_type']);
        $this->assertSame(300, $r['guests']);
        $this->assertSame(2000.0, $r['max_price']);
        $this->assertSame(['cheap'], $r['intents']);
        $this->assertSame(['hall', 'lahore'], $r['terms']);
    }

    public function test_long_and_weird_input_does_not_break(): void
    {
        $r = $this->parse(str_repeat('wedding 300 %_ <script> ', 50) . "\u{1F389}");
        $this->assertSame('weddings', $r['event_type']);
        $this->assertSame(300, $r['guests']);
        $this->assertSame(self::KEYS, array_keys($r));
    }

    public function test_reformulated_query_reparses_the_same(): void
    {
        $original = $this->parse('shadi hall in lhr for 300 guests under 3k');
        $again = $this->parse('Wedding hall in Lahore for 300 guests under 3000');

        foreach (['city', 'event_type', 'guests', 'max_price', 'terms'] as $key) {
            $this->assertSame($original[$key], $again[$key], $key);
        }
    }
}
