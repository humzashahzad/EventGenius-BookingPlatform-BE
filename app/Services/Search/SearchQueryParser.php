<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns free text like "shadi hall in lhr for 300 guests under 3k" into a structured search:
 * city, event type (category slug), guests, per-head budget, intents, amenities and leftover terms.
 *
 * Pure string work over a small vocabulary (cities, categories, amenities). The vocabulary comes
 * from the DB (cached 10 min) unless one is injected, which keeps the parser unit-testable.
 */
class SearchQueryParser
{
    public const CACHE_KEY = 'search.vocabulary.v1';
    public const CACHE_TTL = 600;

    private const CUR = '(?:rs|pkr|rupees?|rupay|rupaye|rupe)';
    private const NUM = '(\d+(?:\.\d+)?)';
    private const GUEST_WORDS = 'guests?|people|peoples|persons?|pax|log|logon|logo|logoon|logo?n|afrad|afraad|attendees?|members?'
        . '|mehmans?|mehmaan|mehmaano|mehmano|mehmanon|mehmaanon|visitors|invitees|heads|seats|adults|banday|bande|crowd';
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    private const PRICE_WORDS = '/\b(price|prices|budget|rate|rates|cost|costs|kharcha|kharch|qeemat|qimat|keemat|charges|fee|fees|per head|rs|pkr|rupees?)\b/';

    /** Keyword => candidate category slugs (first one present in the vocabulary wins). */
    private const EVENT_WORDS = [
        'weddings' => ['shadi', 'shaadi', 'shadee', 'wedding', 'weddings', 'marriage', 'marriages', 'barat', 'baraat',
            'walima', 'valima', 'waleema', 'walimah', 'nikah', 'nikkah', 'nikaah', 'mehndi', 'mehendi', 'mehandi', 'mayun', 'mayoon',
            'dholki', 'rukhsati', 'reception', 'wedding reception', 'bridal'],
        'engagement_parties' => ['mangni', 'mangnee', 'mangini', 'engagement', 'engagements', 'ring ceremony', 'engagement party'],
        'birthday_parties' => ['birthday', 'birthdays', 'bday', 'b day', 'salgirah', 'salgira', 'saalgirah', 'saalgira', 'birthday party', 'kids party'],
        'conferences' => ['conference', 'conferences', 'summit', 'symposium'],
        'seminars_workshops' => ['seminar', 'seminars', 'workshop', 'workshops', 'training', 'trainings', 'lecture', 'webinar'],
        'corporate_events' => ['corporate', 'office', 'meeting', 'meetings', 'company', 'business', 'team building', 'offsite',
            'product launch', 'launch event', 'corporate event', 'office party', 'annual dinner', 'board meeting'],
        'concerts_music_events' => ['concert', 'concerts', 'music', 'gig', 'gigs', 'qawwali', 'qawali', 'live music', 'music event',
            'musical night', 'mushaira', 'sufi night', 'dj night'],
        'graduation_parties' => ['graduation', 'convocation', 'farewell', 'graduation party'],
        'baby_showers' => ['baby shower', 'aqiqa', 'aqeeqa', 'aqiqah', 'aqeeqah', 'gender reveal'],
        'bridal_showers' => ['bridal shower', 'bachelorette'],
        'anniversaries' => ['anniversary', 'anniversaries', 'anniversery'],
        'festivals' => ['festival', 'festivals', 'mela', 'fest', 'exhibition'],
        'religious_events' => ['religious', 'milad', 'meelad', 'mehfil', 'mehfil e milad', 'majlis', 'iftar', 'iftari', 'iftaar', 'dars',
            'quran khwani', 'quran khani', 'khatam', 'khatm', 'naat', 'dua', 'religious event'],
    ];

    /** Fallback slugs when the primary one is not an active category. */
    private const EVENT_FALLBACKS = [
        'weddings' => ['marriage'],
        'conferences' => ['corporate_events', 'seminars_workshops'],
        'seminars_workshops' => ['conferences', 'corporate_events'],
        'corporate_events' => ['meetings', 'conferences'],
        'engagement_parties' => ['weddings'],
        'bridal_showers' => ['weddings'],
    ];

    private const INTENT_WORDS = [
        'top_rated' => ['top rated', 'highest rated', 'best rated', 'highly rated', 'high rated', 'well rated', 'best reviewed',
            'top reviewed', 'top', 'best', 'behtareen', 'behtreen', 'fourstar'],
        'popular' => ['most popular', 'popular', 'trending', 'most booked', 'famous', 'mashhoor', 'mashoor', 'in demand'],
        'cheap' => ['cheapest', 'cheap', 'cheaper', 'affordable', 'budget friendly', 'low cost', 'low budget', 'low price', 'economical',
            'economy', 'inexpensive', 'sasta', 'sasti', 'saste', 'sastay', 'kam qeemat', 'reasonable', 'budget'],
        'luxury' => ['luxury', 'luxurious', 'premium', 'fivestar', 'high end', 'upscale', 'vip', 'posh', 'classy', 'elite', 'lavish'],
        'near_me' => ['near me', 'nearby', 'near by', 'close to me', 'around me', 'closest', 'nearest', 'mere qareeb', 'mere paas',
            'qareeb', 'qareebi', 'nazdeek', 'near my location'],
        'new' => ['newest', 'new', 'latest', 'recently added', 'newly opened', 'brand new', 'naya', 'nayi', 'naye'],
    ];

    public const INTENT_LABELS = [
        'top_rated' => 'Top rated',
        'popular' => 'Popular',
        'cheap' => 'Budget-friendly',
        'luxury' => 'Luxury',
        'near_me' => 'Near me',
        'new' => 'New',
    ];

    /** Canonical city => aliases (only used when the city is in the vocabulary). */
    private const CITY_ALIASES = [
        'Lahore' => ['lhr', 'lahor', 'lahoure'],
        'Karachi' => ['khi', 'krachi', 'karachii'],
        'Islamabad' => ['isb', 'isl', 'isloo', 'islamabad'],
        'Rawalpindi' => ['pindi', 'rwp', 'rawal pindi'],
        'Faisalabad' => ['fsd', 'lyallpur', 'faisal abad', 'faislabad'],
        'Multan' => ['mul', 'mux'],
        'Peshawar' => ['pesh', 'psh', 'peshawer'],
        'Kamalia' => [],
    ];

    /** Query phrase => needle searched in normalized amenity names. */
    private const AMENITY_SYNONYMS = [
        'ac' => 'air conditioning', 'a c' => 'air conditioning', 'air conditioned' => 'air conditioning', 'airconditioned' => 'air conditioning',
        'air conditioner' => 'air conditioning', 'aircon' => 'air conditioning', 'central ac' => 'air conditioning', 'centrally air conditioned' => 'air conditioning',
        'parking' => 'parking', 'car parking' => 'parking', 'valet' => 'parking',
        'wifi' => 'wi fi', 'wi fi' => 'wi fi', 'internet' => 'wi fi', 'wireless' => 'wi fi',
        'generator' => 'generator', 'backup' => 'generator', 'power backup' => 'generator', 'ups' => 'generator',
        'sound' => 'sound', 'sound system' => 'sound', 'speakers' => 'sound', 'audio' => 'sound', 'pa system' => 'sound',
        'dj' => 'dj', 'dj setup' => 'dj', 'dj system' => 'dj',
        'photography' => 'photography', 'photographer' => 'photography', 'photoshoot' => 'photography', 'photo shoot' => 'photography',
        'fireworks' => 'fireworks', 'atishbazi' => 'fireworks', 'aatishbazi' => 'fireworks',
        'stage' => 'stage', 'decor' => 'decoration', 'decoration' => 'decoration', 'decorations' => 'decoration',
        'bridal room' => 'bridal room', 'dressing room' => 'bridal room', 'bride room' => 'bridal room', 'changing room' => 'bridal room',
        'prayer' => 'prayer', 'prayer area' => 'prayer', 'prayer room' => 'prayer', 'namaz' => 'prayer', 'masjid' => 'prayer', 'mosque' => 'prayer',
        'wheelchair' => 'wheelchair', 'accessible' => 'wheelchair', 'disabled access' => 'wheelchair', 'handicap' => 'wheelchair',
        'projector' => 'projector', 'multimedia' => 'projector', 'screen' => 'screen',
        'led' => 'led', 'led wall' => 'led', 'led screen' => 'led', 'smd' => 'led', 'smd screen' => 'led',
        'bbq' => 'bbq', 'barbecue' => 'bbq', 'barbeque' => 'bbq', 'live bbq' => 'bbq',
        'pool' => 'pool', 'swimming pool' => 'pool', 'catering' => 'catering', 'security' => 'security',
    ];

    /** Words in amenity names too generic to match on their own. */
    private const AMENITY_GENERIC = ['room', 'area', 'system', 'setup', 'station', 'wall', 'access', 'backup', 'screen', 'live', 'with',
        'free', 'service', 'services', 'available', 'hall', 'facility', 'facilities', 'and'];

    private const VENUE_TYPE_PLURALS = [
        'halls' => 'hall', 'marquees' => 'marquee', 'marque' => 'marquee', 'marqee' => 'marquee', 'marqees' => 'marquee', 'lawns' => 'lawn',
        'banquets' => 'banquet', 'gardens' => 'garden', 'rooftops' => 'rooftop', 'ballrooms' => 'ballroom', 'farmhouses' => 'farmhouse',
        'hotels' => 'hotel', 'restaurants' => 'restaurant', 'auditoriums' => 'auditorium', 'terraces' => 'terrace', 'lounges' => 'lounge',
        'pavilions' => 'pavilion', 'arenas' => 'arena', 'courtyards' => 'courtyard', 'clubs' => 'club', 'resorts' => 'resort',
    ];

    private const STOPWORDS = [
        'a', 'an', 'the', 'in', 'at', 'on', 'of', 'for', 'and', 'or', 'with', 'without', 'near', 'me', 'my', 'i', 'we', 'us', 'our',
        'need', 'needed', 'want', 'wanted', 'looking', 'look', 'find', 'search', 'show', 'get', 'book', 'booking', 'some', 'any', 'good',
        'nice', 'great', 'venue', 'venues', 'place', 'places', 'location', 'locations', 'spot', 'space', 'spaces', 'under', 'per', 'head',
        'heads', 'guest', 'guests', 'people', 'person', 'persons', 'pax', 'to', 'from', 'is', 'are', 'be', 'can', 'please', 'pls', 'plz',
        'which', 'what', 'where', 'that', 'this', 'it', 'event', 'events', 'party', 'parties', 'function', 'functions', 'ceremony',
        'occasion', 'hire', 'rent', 'rental', 'available', 'around', 'about', 'city', 'area', 'k', 'ke', 'ki', 'ka', 'liye', 'lie',
        'liay', 'keliye', 'mein', 'main', 'mai', 'mujhe', 'mjhe', 'humein', 'chahiye', 'chahye', 'chaiye', 'chahiay', 'koi', 'acha',
        'achi', 'achay', 'wala', 'wali', 'wale', 'walay', 'aur', 'ya', 'se', 'say', 'sy', 'ko', 'hai', 'hain', 'tak', 'kam', 'zyada',
        'ziada', 'rs', 'pkr', 'rupees', 'rupee', 'price', 'prices', 'cost', 'rate', 'rates', 'charges', 'below', 'less', 'than', 'max',
        'maximum', 'min', 'minimum', 'upto', 'up', 'within', 'between', 'above', 'over', 'more', 'least', 'star', 'stars', 'log', 'logon',
        'afrad', 'mehman', 'mehmaan', 'all', 'by', 'as', 'do', 'does', 'have', 'has', 'there', 'their', 'you', 'your', 'how', 'much',
        'many', 'like', 'type', 'kind', 'options', 'option', 'list', 'near', 'jan', 'january', 'feb', 'february', 'march', 'april',
        'may', 'june', 'july', 'august', 'sept', 'september', 'october', 'november', 'december', 'date', 'day', 'days', 'night',
        'evening', 'morning', 'today', 'tomorrow', 'weekend', 'hours', 'hour', 'capacity', 'approx', 'approximately', 'almost',
        'plus', 'only', 'also', 'very', 'so', 'just', 'kahan', 'kidhar', 'konsa', 'kaunsa', 'batao', 'bataen', 'dikhao', 'chahte',
        'karna', 'karni', 'krna', 'hy', 'he', 'ho', 'sath', 'saath', 'wa', 'yeh', 'ye', 'woh', 'hum', 'tum', 'aap',
    ];

    /** A few common Urdu-script words, rewritten to Roman Urdu/English before parsing. */
    private const URDU_SCRIPT = [
        'شادی' => 'shadi', 'ولیمہ' => 'walima', 'بارات' => 'barat', 'مہندی' => 'mehndi', 'نکاح' => 'nikah', 'منگنی' => 'mangni',
        'سالگرہ' => 'salgirah', 'میلاد' => 'milad', 'افطار' => 'iftar', 'ہال' => 'hall', 'لان' => 'lawn', 'مارکی' => 'marquee',
        'لاہور' => 'lahore', 'کراچی' => 'karachi', 'اسلام آباد' => 'islamabad', 'راولپنڈی' => 'rawalpindi', 'پنڈی' => 'pindi',
        'فیصل آباد' => 'faisalabad', 'ملتان' => 'multan', 'پشاور' => 'peshawar', 'مہمان' => 'mehman', 'مہمانوں' => 'mehmanon',
        'افراد' => 'afrad', 'لوگ' => 'log', 'لوگوں' => 'logon', 'کے لیے' => 'for', 'کیلئے' => 'for', 'میں' => 'in', 'روپے' => 'rs',
        'سستا' => 'sasta', 'سستی' => 'sasti', 'سے کم' => 'se kam', 'تک' => 'tak', 'ہزار' => 'hazar',
    ];

    private array $cities = [];      // normalized name => canonical
    private array $categories = [];  // slug => name
    private array $phrases = [];     // normalized phrase => [kind, value], longest first
    private array $reserved = [];    // words never fuzzy-matched to a city

    public function __construct(?array $vocabulary = null)
    {
        $vocabulary ??= self::vocabulary();

        foreach ($vocabulary['cities'] ?? [] as $city) {
            $city = trim((string) $city);
            if ($city !== '') {
                $this->cities[self::normalizeName($city)] = $city;
            }
        }
        foreach ($vocabulary['categories'] ?? [] as $category) {
            $slug = trim((string) ($category['slug'] ?? ''));
            if ($slug !== '') {
                $this->categories[$slug] = trim((string) ($category['name'] ?? '')) ?: ucwords(str_replace('_', ' ', $slug));
            }
        }
        $amenities = [];
        foreach ($vocabulary['amenities'] ?? [] as $amenity) {
            $amenity = trim((string) $amenity);
            if ($amenity !== '') {
                $amenities[self::normalizeName($amenity)] = $amenity;
            }
        }

        $this->buildPhrases($amenities);
    }

    /**
     * Cities of public venues, active categories and amenity names, cached for 10 minutes.
     *
     * @return array{cities: string[], categories: array<int, array{slug: string, name: string}>, amenities: string[]}
     */
    public static function vocabulary(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn() => [
                'cities' => DB::table('venues')
                    ->join('stores', 'stores.id', '=', 'venues.store_id')
                    ->where('venues.status', 'active')
                    ->where('stores.status', 'approved')
                    ->whereNull('venues.deleted_at')
                    ->whereNull('stores.deleted_at')
                    ->whereNotNull('venues.city')
                    ->where('venues.city', '!=', '')
                    ->distinct()
                    ->orderBy('venues.city')
                    ->pluck('venues.city')
                    ->map(fn($c) => trim((string) $c))
                    ->unique()
                    ->values()
                    ->all(),
                'categories' => DB::table('categories')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->get(['slug', 'name'])
                    ->map(fn($c) => ['slug' => (string) $c->slug, 'name' => (string) $c->name])
                    ->all(),
                'amenities' => DB::table('venue_amenities')
                    ->whereNull('deleted_at')
                    ->distinct()
                    ->orderBy('name')
                    ->pluck('name')
                    ->map(fn($a) => trim((string) $a))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        } catch (Throwable $e) {
            self::report($e);
            return ['cities' => [], 'categories' => [], 'amenities' => []];
        }
    }

    public function parse(?string $q): array
    {
        $raw = trim((string) $q);
        $result = [
            'raw' => $raw,
            'terms' => [],
            'city' => null,
            'event_type' => null,
            'guests' => null,
            'max_price' => null,
            'min_price' => null,
            'intents' => [],
            'amenities' => [],
            'chips' => [],
        ];
        if ($raw === '') {
            return $result;
        }

        try {
            $s = ' ' . $this->normalize($raw) . ' ';
            $this->parseNumbers($s, $result);
            $this->parsePhrases($s, $result);
            $this->parseTokens($s, $result);
        } catch (Throwable $e) {
            // Never break search because of an odd query: fall back to plain keywords.
            self::report($e);
            $result['terms'] = array_values(array_unique(array_filter(
                preg_split('/\s+/u', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $raw))) ?: [],
                fn($w) => mb_strlen($w) >= 2 && !in_array($w, self::STOPWORDS, true)
            )));
        }

        if ($result['min_price'] !== null && $result['max_price'] !== null && $result['min_price'] > $result['max_price']) {
            [$result['min_price'], $result['max_price']] = [$result['max_price'], $result['min_price']];
        }
        $result['chips'] = $this->chips($result);

        return $result;
    }

    // ─── Normalisation ────────────────────────────────────────────────────

    private function normalize(string $q): string
    {
        $s = strtr($q, self::URDU_SCRIPT);
        // Urdu / Arabic-Indic digits → ASCII.
        $s = strtr($s, array_combine(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9']
        ));
        $s = mb_strtolower($s);

        $s = preg_replace('/\b\d{1,2}:\d{2}\s*(am|pm)?\b|\b\d{1,2}\s*(am|pm)\b/', ' ', $s);      // times
        $s = preg_replace('/\b\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\b/', ' ', $s);                       // dates
        while (preg_match('/(\d),(\d{3})(?!\d)/', $s)) {
            $s = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', $s);                                 // 3,000 → 3000
        }
        $s = preg_replace('/\b(rs|pkr)\.?(?=\s*\d)/', ' $1 ', $s);                                 // Rs.3000 → rs 3000
        $s = preg_replace('/(\d)\s*\/-/', '$1 rs ', $s);                                           // 3000/- → 3000 rs
        $s = preg_replace('/\s*\/\s*(head|person|plate|guest|pax)\b/', ' per head', $s);
        $s = preg_replace('/\b(per|a|each|fi|har)\s+(person|plate|guest|pax|head|banda|bandah)\b/', ' per head ', $s);
        $s = preg_replace('/\bb\s*\/\s*w\b/', ' between ', $s);
        $s = preg_replace('/\b(5|five|seven|7)\s*-?\s*stars?\b/', ' fivestar ', $s);
        $s = preg_replace('/\b(4|four)\s*-?\s*stars?\b|\b4\s*\+\s*(rating|rated|stars?)\b/', ' fourstar ', $s);
        $s = preg_replace('/\b(top|best)\s+\d{1,2}\b/', ' $1 ', $s);                              // "top 10" isn't 10 guests
        $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s?k\b/', fn($m) => ' rs ' . self::num((float) $m[1] * 1000) . ' ', $s);
        $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s?(thousand|hazar|hazaar|hzar|hazzar)\b/', fn($m) => ' ' . self::num((float) $m[1] * 1000) . ' ', $s);
        $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s?(lakh|lakhs|lac|lacs)\b/', fn($m) => ' rs ' . self::num((float) $m[1] * 100000) . ' ', $s);
        $s = preg_replace('/(\d)\s*-\s*(\d)/', '$1 to $2', $s);
        $s = str_replace(['&', '+'], [' and ', ' + '], $s);
        $s = preg_replace('/\b(farm)\s+(house)\b/', 'farmhouse', $s);
        $s = preg_replace('/\b(roof)\s+(top)\b/', 'rooftop', $s);
        $s = preg_replace('/\b(ball)\s+(room)\b/', 'ballroom', $s);
        // Keep letters (any script), digits, decimal points between digits and '+'.
        $s = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{M}\p{N}.+\s]+/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function normalizeName(string $name): string
    {
        $s = mb_strtolower(str_replace('&', ' and ', $name));
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function num(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : (string) $n;
    }

    // ─── Numbers: guests, budgets, dates ──────────────────────────────────

    /** Replace every match with spaces of the same byte length so later offsets stay valid. */
    private function consume(string &$s, string $pattern, callable $handle): void
    {
        $s = preg_replace_callback($pattern, function ($m) use ($handle) {
            return $handle($m) === false ? $m[0] : str_repeat(' ', strlen($m[0]));
        }, $s);
    }

    private function parseNumbers(string &$s, array &$r): void
    {
        $N = self::NUM;
        $CUR = self::CUR;
        $G = self::GUEST_WORDS;
        $M = self::MONTHS;
        $end = '(?![\p{L}\p{N}])';
        $priceWords = (bool) preg_match(self::PRICE_WORDS, $s);

        $setGuests = function (float $n) use (&$r) {
            if ($r['guests'] === null && $n >= 1 && $n <= 100000) {
                $r['guests'] = (int) round($n);
            }
        };
        $setMax = function (float $n) use (&$r) {
            if ($r['max_price'] === null && $n > 0 && $n <= 10000000) {
                $r['max_price'] = (float) $n;
            }
        };
        $setMin = function (float $n) use (&$r) {
            if ($r['min_price'] === null && $n > 0 && $n <= 10000000) {
                $r['min_price'] = (float) $n;
            }
        };
        $hasCur = fn(string $text) => (bool) preg_match('/\b' . $CUR . '\b|per head/', $text);

        // Dates, ordinals and durations are never guests or budgets.
        $this->consume($s, "/\\b\\d{1,2}(?:st|nd|rd|th)?\\s+(?:of\\s+)?(?:$M)\\b(?:,?\\s*\\d{4})?/", fn() => true);
        $this->consume($s, "/\\b(?:$M)\\s+\\d{1,2}(?:st|nd|rd|th)?\\b(?:,?\\s*\\d{4})?/", fn() => true);
        $this->consume($s, '/\b\d+(?:st|nd|rd|th)\b/', fn() => true);
        $this->consume($s, '/\b\d+(?:\.\d+)?\s*(?:hours?|hrs?|days?|nights?|din|ghante|ghantay|minutes?|mins?|years?|saal|floors?|stars?|km|kms|kilometers?|miles?|marla|kanal|acres?)\b/', fn() => true);

        // Ranges: "between 2000 and 4000", "2000-4000 rs", "200 to 300 guests".
        $this->consume($s, "/(?:\\b(between|from)\\s+)?(?:$CUR\\s*)?$N\\s*(?:$CUR\\s*)?(?:and|to|se|say|till)\\s*(?:$CUR\\s*)?$N(?:\\s*$CUR\\b)?(\\s*per head)?(\\s*\\+?\\s*(?:$G)$end)?/u",
            function ($m) use ($hasCur, $setGuests, $setMax, $setMin) {
                $a = (float) $m[2];
                $b = (float) $m[3];
                [$lo, $hi] = $a <= $b ? [$a, $b] : [$b, $a];
                if (!empty($m[5])) {
                    $setGuests($hi);
                    return true;
                }
                $price = $hasCur($m[0]) || ($lo >= 500 && $hi >= 500) || (!empty($m[1]) && $hi >= 500);
                if ($price) {
                    $setMin($lo);
                    $setMax($hi);
                    return true;
                }
                if ($hi >= 10 && ($a < 500 && $b < 500)) {
                    $setGuests($hi);
                    return true;
                }
                return false;
            });

        // "300 guests", "for 300 people", "300+ log", "500 afrad".
        $this->consume($s, "/(?:\\b(?:for|about|around|approx(?:imately)?|almost|upto|up to|over|more than|at ?least|minimum|min|max|maximum|takriban|taqreeban)\\s+)?$N\\s*\\+?\\s*(?:$G)$end/u",
            fn($m) => $setGuests((float) $m[1]) ?? true);
        $this->consume($s, "/\\b(?:capacity|gunjaish|gunjaesh)\\s*(?:of|for|is|hai)?\\s*$N$end/u", fn($m) => $setGuests((float) $m[1]) ?? true);

        // Budget ceilings: "under 3000", "budget 2500", "upto rs 3000 per head", "3000 se kam", "3000 tak".
        $this->consume($s, "/\\b(?:under|below|less than|lower than|cheaper than|not more than|no more than|max(?:imum)?|up ?to|within|budget(?:\\s+(?:of|is|around|about|hai))?|at most|around|about)\\s*(?:of\\s*)?(?:$CUR\\s*)?$N(?:\\s*$CUR\\b)?(?:\\s*per head)?$end/u",
            function ($m) use ($setMax, $setGuests, $hasCur, $priceWords) {
                $n = (float) $m[1];
                // "around 300" without any money context reads as a head count.
                if (preg_match('/^\s*(around|about)\b/', $m[0]) && !$hasCur($m[0]) && !$priceWords && $n < 500) {
                    $setGuests($n);
                    return true;
                }
                $setMax($n);
                return true;
            });
        $this->consume($s, "/$N\\s*(?:$CUR\\s*)?(?:per head\\s*)?(?:se kam|say kam|sy kam|se kum|tak|or less|or below|and below|or under|max(?:imum)?)$end/u",
            fn($m) => $setMax((float) $m[1]) ?? true);

        // Budget floors: "above 2000", "starting from rs 1500", "2000 se zyada".
        $this->consume($s, "/\\b(?:above|over|more than|greater than|min(?:imum)?|at ?least|starting(?:\\s+(?:from|at))?|from)\\s*(?:$CUR\\s*)?$N(?:\\s*$CUR\\b)?(?:\\s*per head)?$end/u",
            function ($m) use ($setMin, $setGuests, $hasCur) {
                $n = (float) $m[1];
                if ($hasCur($m[0]) || $n >= 500) {
                    $setMin($n);
                } elseif ($n >= 10) {
                    $setGuests($n);
                }
                return true;
            });
        $this->consume($s, "/$N\\s*(?:$CUR\\s*)?(?:per head\\s*)?(?:se zyada|se ziada|se upar|say zyada|or more|and above|or above|plus)$end/u",
            function ($m) use ($setMin, $setGuests, $hasCur) {
                $n = (float) $m[1];
                $hasCur($m[0]) || $n >= 500 ? $setMin($n) : $setGuests($n);
                return true;
            });

        // Money markers: "rs 3000", "3000 pkr", "3000 per head".
        $this->consume($s, "/(?:\\b$CUR\\s*$N|$N\\s*$CUR\\b)(?:\\s*per head)?$end/u",
            fn($m) => $setMax((float) ($m[1] !== '' ? $m[1] : $m[2])) ?? true);
        $this->consume($s, "/$N\\s*per head$end/u", fn($m) => $setMax((float) $m[1]) ?? true);

        // "for 300" → guests.
        $this->consume($s, "/\\bfor\\s+$N$end/u", function ($m) use ($setGuests) {
            $n = (float) $m[1];
            if ($n < 10) {
                return false;
            }
            $setGuests($n);
            return true;
        });

        // Bare numbers: money if the query talks about price, else small → guests, large → budget.
        $this->consume($s, "/(?<![\\p{L}\\p{N}.])$N$end/u", function ($m) use ($priceWords, $setGuests, $setMax) {
            $n = (float) $m[1];
            if ($n >= 2020 && $n <= 2035 && floor($n) == $n) {
                return true; // a year
            }
            if ($priceWords && $n >= 100) {
                $setMax($n);
            } elseif ($n >= 20 && $n < 1000) {
                $setGuests($n);
            } elseif ($n >= 1000 && $n <= 1000000) {
                $setMax($n);
            }
            return true;
        });
    }

    // ─── Phrases: events, cities, amenities, intents ──────────────────────

    private function buildPhrases(array $amenities): void
    {
        $add = function (string $phrase, string $kind, string $value) {
            $phrase = trim($phrase);
            if ($phrase !== '' && !isset($this->phrases[$phrase])) {
                $this->phrases[$phrase] = [$kind, $value];
            }
        };

        // Event words → first available slug.
        foreach (self::EVENT_WORDS as $slug => $words) {
            $target = $this->availableSlug($slug);
            if ($target === null) {
                continue;
            }
            foreach ($words as $word) {
                $add($word, 'event', $target);
            }
        }
        // Category names and slugs straight from the vocabulary ("graduation parties", "festival").
        foreach ($this->categories as $slug => $name) {
            foreach ([self::normalizeName($name), str_replace('_', ' ', $slug)] as $p) {
                $add($p, 'event', $slug);
                $add(self::singular($p), 'event', $slug);
            }
        }

        foreach (self::INTENT_WORDS as $intent => $words) {
            foreach ($words as $word) {
                $add($word, 'intent', $intent);
            }
        }

        foreach ($this->cities as $norm => $city) {
            $add($norm, 'city', $city);
        }
        foreach (self::CITY_ALIASES as $canonical => $aliases) {
            $city = $this->cities[self::normalizeName($canonical)] ?? null;
            if ($city !== null) {
                foreach ($aliases as $alias) {
                    $add($alias, 'city', $city);
                }
            }
        }

        // Amenities: full names, synonyms and distinctive words ("parking" → Valet Parking).
        $eventWords = [];
        foreach ($this->phrases as $p => [$kind]) {
            if ($kind === 'event') {
                foreach (explode(' ', $p) as $w) {
                    $eventWords[$w] = true;
                }
            }
        }
        foreach ($amenities as $norm => $name) {
            $add($norm, 'amenity', $name);
        }
        foreach (self::AMENITY_SYNONYMS as $phrase => $needle) {
            $match = $this->findAmenity($amenities, $needle);
            if ($match !== null) {
                $add($phrase, 'amenity', $match);
            }
        }
        foreach ($amenities as $norm => $name) {
            foreach (explode(' ', $norm) as $w) {
                if (strlen($w) >= 4 && !in_array($w, self::AMENITY_GENERIC, true) && !isset($eventWords[$w])) {
                    $add($w, 'amenity', $name);
                }
            }
        }

        uksort($this->phrases, fn($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        foreach (array_keys($this->phrases) as $p) {
            foreach (explode(' ', $p) as $w) {
                $this->reserved[$w] = true;
            }
        }
        foreach (array_merge(self::STOPWORDS, array_keys(self::VENUE_TYPE_PLURALS), array_values(self::VENUE_TYPE_PLURALS)) as $w) {
            $this->reserved[$w] = true;
        }
    }

    private function availableSlug(string $slug): ?string
    {
        foreach (array_merge([$slug], self::EVENT_FALLBACKS[$slug] ?? []) as $candidate) {
            if (isset($this->categories[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    private function findAmenity(array $amenities, string $needle): ?string
    {
        foreach ($amenities as $norm => $name) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $norm)) {
                return $name;
            }
        }
        return null;
    }

    private static function singular(string $phrase): string
    {
        if (str_ends_with($phrase, 'ies')) {
            return substr($phrase, 0, -3) . 'y';
        }
        if (str_ends_with($phrase, 's') && !str_ends_with($phrase, 'ss')) {
            return substr($phrase, 0, -1);
        }
        return $phrase;
    }

    private function parsePhrases(string &$s, array &$r): void
    {
        $found = ['event' => [], 'city' => [], 'intent' => [], 'amenity' => []];
        foreach ($this->phrases as $phrase => [$kind, $value]) {
            if (!str_contains($s, $phrase)) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u';
            if (!preg_match_all($pattern, $s, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $found[$kind][] = [$matches[0][0][1], $value];
            $s = preg_replace_callback($pattern, fn($m) => str_repeat(' ', strlen($m[0])), $s);
        }

        // Keep query order; the first mention wins when several cities / event types appear.
        foreach ($found as $kind => $hits) {
            usort($found[$kind], fn($a, $b) => $a[0] <=> $b[0]);
            $found[$kind] = array_values(array_unique(array_column($found[$kind], 1)));
        }
        $r['event_type'] = $found['event'][0] ?? null;
        $r['city'] = $found['city'][0] ?? null;
        $r['intents'] = $found['intent'];
        $r['amenities'] = $found['amenity'];
    }

    // ─── Leftover tokens: typo'd cities and keywords ──────────────────────

    private function parseTokens(string $s, array &$r): void
    {
        $terms = [];
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $word) {
            $word = trim($word, '.+');
            if ($word === '' || preg_match('/^\d/', $word) || mb_strlen($word) < 2) {
                continue;
            }
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            if ($r['city'] === null && ($city = $this->fuzzyCity($word)) !== null) {
                $r['city'] = $city;
                continue;
            }
            $terms[] = self::VENUE_TYPE_PLURALS[$word] ?? $word;
        }
        $r['terms'] = array_values(array_unique($terms));
    }

    private function fuzzyCity(string $word): ?string
    {
        if (strlen($word) < 5 || isset($this->reserved[$word]) || !preg_match('/^[a-z]+$/', $word)) {
            return null;
        }
        $best = null;
        $bestDistance = 3;
        foreach ($this->cities as $norm => $city) {
            if (!preg_match('/^[a-z ]+$/', $norm)) {
                continue;
            }
            $d = levenshtein($word, str_replace(' ', '', $norm));
            if ($d < $bestDistance) {
                $best = $city;
                $bestDistance = $d;
            }
        }
        return $bestDistance <= 2 ? $best : null;
    }

    // ─── Chips ────────────────────────────────────────────────────────────

    private function chips(array $r): array
    {
        $chips = [];
        if ($r['event_type'] !== null) {
            $chips[] = ['type' => 'event_type', 'label' => $this->categories[$r['event_type']] ?? $r['event_type'],
                'param' => 'event_type', 'value' => $r['event_type']];
        }
        if ($r['city'] !== null) {
            $chips[] = ['type' => 'city', 'label' => $r['city'], 'param' => 'city', 'value' => $r['city']];
        }
        if ($r['guests'] !== null) {
            $chips[] = ['type' => 'guests', 'label' => number_format($r['guests']) . ' guests', 'param' => 'guests', 'value' => $r['guests']];
        }
        if ($r['min_price'] !== null) {
            $chips[] = ['type' => 'min_price', 'label' => 'From PKR ' . self::money($r['min_price']) . ' / head',
                'param' => 'min_price', 'value' => $r['min_price']];
        }
        if ($r['max_price'] !== null) {
            $chips[] = ['type' => 'max_price', 'label' => 'Up to PKR ' . self::money($r['max_price']) . ' / head',
                'param' => 'max_price', 'value' => $r['max_price']];
        }
        foreach ($r['intents'] as $intent) {
            $chips[] = ['type' => 'intent', 'label' => self::INTENT_LABELS[$intent] ?? $intent, 'param' => 'q', 'value' => $intent];
        }
        foreach ($r['amenities'] as $amenity) {
            $chips[] = ['type' => 'amenity', 'label' => $amenity, 'param' => 'q', 'value' => $amenity];
        }
        foreach ($r['terms'] as $term) {
            $chips[] = ['type' => 'term', 'label' => '“' . $term . '”', 'param' => 'q', 'value' => $term];
        }
        return $chips;
    }

    private static function report(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // No application container (pure unit tests).
        }
    }

    private static function money(float $n): string
    {
        return number_format($n, floor($n) == $n ? 0 : 2);
    }
}
