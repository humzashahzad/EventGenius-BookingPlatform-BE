<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Search\SearchQueryParser;
use Carbon\CarbonImmutable;

/**
 * Deterministic venue-finding conversation on top of the smart search engine.
 *
 * Each message is understood with SearchQueryParser (city, occasion, guests, budget, amenities, intents)
 * plus a few chat rules (references like "the first one", refinements like "cheaper", questions about a
 * venue, dates). The search state lives in ConversationStore; every number in a reply comes from the
 * Catalog. No language model is involved, so a message costs a few small queries.
 */
class AssistantEngine
{
    public const PER_PAGE = 4;
    public const TAX_RATE = 0.05;

    /** Friendly singular names for category slugs (also understood by the parser as quick replies). */
    private const EVENT_LABELS = [
        'weddings' => 'Wedding', 'birthday_parties' => 'Birthday party', 'corporate_events' => 'Corporate event',
        'conferences' => 'Conference', 'seminars_workshops' => 'Seminar or workshop', 'engagement_parties' => 'Engagement',
        'graduation_parties' => 'Graduation party', 'baby_showers' => 'Baby shower', 'bridal_showers' => 'Bridal shower',
        'anniversaries' => 'Anniversary', 'concerts_music_events' => 'Concert', 'festivals' => 'Festival',
        'religious_events' => 'Religious event',
    ];

    private const ASPECTS = [
        'food' => ['food', 'khana', 'khaana', 'catering', 'menu', 'taste', 'dinner', 'lunch', 'cuisine', 'dishes', 'biryani'],
        'service' => ['service', 'staff', 'waiters?', 'hospitality', 'behaviour', 'behavior'],
        'decor' => ['decor', 'decoration', 'decorations', 'ambience', 'ambiance', 'lighting', 'interior', 'looks?', 'beautiful'],
        'value' => ['value', 'value for money', 'worth the money', 'paisa vasool'],
        'cleanliness' => ['clean', 'cleanliness', 'washrooms?', 'hygiene', 'safai'],
        'parking' => ['parking'],
        'cooling' => ['ac', 'a c', 'cooling', 'air conditioning', 'air conditioned', 'garmi', 'hot inside'],
        'sound_av' => ['sound', 'audio', 'speakers', 'sound system', 'av'],
        'location' => ['location', 'access', 'accessible', 'easy to find'],
        'management' => ['management', 'timing', 'on time', 'punctual', 'organised', 'organized', 'manager'],
        'power_backup' => ['generator', 'power', 'backup', 'bijli', 'load shedding', 'loadshedding', 'electricity'],
    ];

    private const ASPECT_NAMES = [
        'food' => 'food', 'service' => 'service and staff', 'decor' => 'decor', 'value' => 'value for money',
        'cleanliness' => 'cleanliness', 'parking' => 'parking', 'cooling' => 'AC and cooling', 'sound_av' => 'sound and AV',
        'location' => 'location', 'management' => 'management and timing', 'power_backup' => 'power backup',
    ];

    /** Words in venue names that never identify one venue on their own. */
    private const NAME_GENERIC = ['hall', 'halls', 'marquee', 'banquet', 'ballroom', 'lawn', 'pavilion', 'arena', 'palace', 'centre',
        'center', 'lounge', 'club', 'courtyard', 'terrace', 'garden', 'farmhouse', 'conference', 'convention', 'party', 'grand',
        'royal', 'golden', 'crystal', 'emerald', 'the', 'new', 'music', 'dome', 'family', 'business', 'hub', 'executive',
        'boardroom', 'celebration', 'open', 'air', 'grounds', 'pearl', 'wedding', 'road', 'town', 'area', 'marriage', 'view',
        'and', 'shan', 'noor', 'event', 'events', 'venue', 'house', 'room', 'rooftop', 'west', 'east'];

    private const VENUE_TYPES = ['hall', 'marquee', 'banquet', 'ballroom', 'lawn', 'pavilion', 'arena', 'palace', 'lounge', 'club',
        'courtyard', 'terrace', 'garden', 'farmhouse', 'rooftop', 'hotel', 'restaurant', 'auditorium', 'resort', 'dome', 'boardroom'];

    private const URDU = [
        'کیا' => ' kya ', 'قیمت' => ' qeemat ', 'کتنا' => ' kitna ', 'کتنے' => ' kitne ', 'دستیاب' => ' available ',
        'خالی' => ' khali ', 'کھانا' => ' khana ', 'سستا' => ' sasta ', 'سستے' => ' saste ', 'مزید' => ' more ',
        'بک' => ' book ', 'پہلا' => ' pehla ', 'پہلے' => ' pehla ', 'دوسرا' => ' dusra ', 'تیسرا' => ' teesra ',
        'شروع سے' => ' start over ', 'سروس' => ' service ', 'پارکنگ' => ' parking ', 'ریٹنگ' => ' rating ',
        'کہاں' => ' kahan ', 'شکریہ' => ' shukriya ', 'سلام' => ' salam ', 'مدد' => ' help ',
    ];

    private const SORT_PHRASES = [
        'price_asc' => 'cheapest first|lowest price first|low to high|sort by price|price ascending|sasta pehle',
        'price_desc' => 'most expensive first|highest price first|high to low|price descending',
        'rating' => 'highest rated first|best rated first|sort by rating|by rating|top rated first',
        'newest' => 'newest first|latest first|sort by newest|newly added',
        'best_match' => 'best match|best matches|recommended|default order|sort by relevance',
    ];

    /** Messages that refine the list rather than ask about one venue. */
    private const REFINE = 'cheaper|less expensive|more affordable|more premium|fancier|bigger|larger|smaller|more options|show more'
        . '|more venues|next page|only top rated|top rated only|only the best|highly rated|different city|another city|any city'
        . '|no budget|any budget|remove (the )?budget|keep looking|other venues';

    private Catalog $catalog;
    private ConversationStore $store;
    private DateParser $dates;
    private CarbonImmutable $today;
    private ?SearchQueryParser $parser = null;
    private array $facts = [];
    private array $conv = [];
    private ?User $user = null;
    private ?array $nameFreq = null;
    private string $raw = '';
    private ?int $outOfRange = null;

    public function __construct(Catalog $catalog, ConversationStore $store, ?CarbonImmutable $today = null)
    {
        $this->catalog = $catalog;
        $this->store = $store;
        $this->today = ($today ?? CarbonImmutable::now())->startOfDay();
        $this->dates = new DateParser($this->today);
    }

    // ═══ Public API ══════════════════════════════════════════════════════════

    /** Greeting and starter suggestions for a new chat. */
    public function start(?User $user = null): array
    {
        $this->boot($user);
        $name = $user ? trim(explode(' ', (string) $user->name)[0]) : '';
        $hi = $name !== '' ? "Hi {$name}!" : 'Hi!';
        return $this->response(null, [
            'reply' => "{$hi} Tell me about your event and I'll find venues that fit. "
                . 'For example: the occasion, the city, how many guests and your budget per head.',
            'quick_replies' => $this->starterReplies(),
        ]);
    }

    public function handle(?string $conversationId, string $message, ?User $user = null): array
    {
        $this->boot($user);
        [$id, $this->conv] = $this->store->load($conversationId);
        $message = trim(mb_substr(preg_replace('/\s+/u', ' ', $message) ?? '', 0, 500));

        if ($this->conv['turns'] >= ConversationStore::MAX_TURNS) {
            return $this->response($id, [
                'reply' => "We've covered a lot in this chat! Tap **Start over** to begin a fresh one and I'll keep up.",
                'quick_replies' => ['Start over'],
            ]);
        }
        $this->conv['turns']++;

        $out = $this->respond($message);
        $this->store->save($id, $this->conv);
        return $this->response($id, $out);
    }

    // ═══ Routing ═════════════════════════════════════════════════════════════

    private function respond(string $message): array
    {
        $t = $this->normalize($message);
        $this->raw = $message;
        $pending = $this->conv['pending'] ?? null;
        $pendingQ = $this->conv['pending_q'] ?? null;
        $this->conv['pending'] = $this->conv['pending_q'] = null;
        if (trim($t) === '') {
            return $this->fallback();
        }

        // "yes" / "no" to the suggestion we just made.
        $offer = $this->conv['offer'] ?? null;
        $this->conv['offer'] = null;
        if ($offer && $this->has($t, '^ (yes|yeah|yep|yup|sure|ok|okay|ok sure|please|haan|han|ji|jee|ji haan|theek hai|thik hai|go ahead|do it|sounds good)( please)? $')) {
            $message = $offer;
            $t = $this->normalize($offer);
        } elseif ($offer && $this->has($t, '^ (no|nope|nah|nahi|nahin|no thanks|not now) $')) {
            return ['reply' => 'No problem. What would you like to change? You can tell me a different city, budget or guest count.',
                'quick_replies' => $this->refineReplies()];
        }

        if ($this->has($t, '\b(start over|start again|restart|reset|new search|new chat|clear (all|everything|filters|search)|from scratch|shuru se|dobara shuru|naye sire se)\b')) {
            return $this->reset();
        }

        $parsed = $this->parse($message);
        $date = $this->dates->parse($message, $this->today);
        $this->outOfRange = null;
        // "cheapest first" is a sort order, not a reference to the first venue.
        $refs = $this->references(preg_replace('/\b(' . implode('|', self::SORT_PHRASES) . ')\b/u', ' ', $t));
        $info = $this->searchSignals($parsed);
        if (!$refs && $this->outOfRange && $this->conv['shown']) {
            $n = count($this->conv['shown']);
            return ['reply' => "I've only shown " . $this->plural($n, 'venue') . ' in this list. Which one do you mean?',
                'quick_replies' => array_merge(array_column(array_slice($this->conv['shown'], 0, 4), 'name'), ['More options'])];
        }

        // Small talk only when the message carries nothing else.
        if (!$info && !$refs && !$date) {
            if ($this->has($t, '\b(help|what can you do|how does this work|how do you work|how to use|what do you do|who are you|madad)\b')) {
                return $this->help();
            }
            if ($this->has($t, '^ ((hi|hello|hey|hiya|salam|salaam|assalam|assalamualaikum|assalam o alaikum|aoa|asalam|good (morning|afternoon|evening))( there| everyone)?\W*)+ $')) {
                return $this->greet();
            }
            if ($this->has($t, '\b(thanks|thank you|thankyou|thx|shukriya|shukria|jazakallah|great|perfect|awesome|nice|cool)\b')
                && str_word_count(trim($t)) <= 5) {
                return ['reply' => "You're welcome! Anything else I can check for you?",
                    'quick_replies' => $this->conv['shown'] ? $this->refineReplies() : $this->starterReplies()];
            }
            if ($this->has($t, '\b(bye|goodbye|khuda hafiz|allah hafiz|see you|that s all|thats all)\b')) {
                return ['reply' => 'Goodbye, and good luck with your event! Come back any time.', 'quick_replies' => ['Start over']];
            }
        }

        // We asked "which venue?": an answer naming one resolves the stored question.
        if ($pending === 'venue' && $refs && is_array($pendingQ)
            && !$this->has($t, '\b(compare|vs|versus|book|reserve|price|cost|how much|review\w*|guests say|facilit\w*|amenit\w*)\b')) {
            $q = $pendingQ;
            if ($date && ($q['type'] ?? '') === 'availability') {
                $q['date'] = $date;
            }
            return $this->answer($q, $refs, $parsed);
        }

        if ($this->has($t, '\b(see all|show all|view all|all results|full list|venues page|open (the )?(search|list|results))\b') && !$refs) {
            return $this->openSearch();
        }

        if ($this->isBooking($t, $refs, $info)) {
            return $this->answer(['type' => 'book'], $refs, $parsed);
        }

        $q = $this->question($t, $refs, $date, $parsed, $info);
        if ($q !== null) {
            return $this->answer($q, $refs, $parsed);
        }

        // A bare date right after "which date?".
        if ($pending === 'date' && $date && $this->conv['focus']) {
            return $this->answer(['type' => 'availability', 'date' => $date], [], $parsed);
        }

        return $this->searchTurn($t, $message, $parsed, $date, $pending);
    }

    // ═══ Understanding ═══════════════════════════════════════════════════════

    private function normalize(string $message): string
    {
        $t = mb_strtolower(strtr($message, self::URDU));
        $t = preg_replace("/['’`]/u", '', $t);
        $t = preg_replace('/[^\p{L}\p{M}\p{N}#+\/.\s-]+/u', ' ', $t);
        $t = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $t);
        return ' ' . trim(preg_replace('/\s+/u', ' ', $t)) . ' ';
    }

    private function has(string $t, string $pattern): bool
    {
        return (bool) preg_match('/' . $pattern . '/u', $t);
    }

    /** Parser output with leftover terms kept only when they name a venue type or a known area. */
    private function parse(string $message): array
    {
        $p = $this->parser()->parse($message);
        $places = array_flip($this->facts['places'] ?? []);
        $p['place_terms'] = [];
        $p['type_terms'] = [];
        $p['unknown_terms'] = [];
        foreach ($p['terms'] as $term) {
            $term = (string) $term;
            if (in_array($term, self::VENUE_TYPES, true) || in_array(rtrim($term, 's'), self::VENUE_TYPES, true)) {
                $p['type_terms'][] = in_array($term, self::VENUE_TYPES, true) ? $term : rtrim($term, 's');
            } elseif (isset($places[$term])) {
                $p['place_terms'][] = $term;
            } else {
                $p['unknown_terms'][] = $term;
            }
        }
        // Intents we cannot act on in a chat (no location here).
        $p['intents'] = array_values(array_diff($p['intents'], ['near_me']));
        return $p;
    }

    private function searchSignals(array $p): bool
    {
        return $p['city'] || $p['event_type'] || $p['guests'] || $p['max_price'] || $p['min_price']
            || $p['amenities'] || $p['place_terms'] || $p['type_terms'] || $p['intents'];
    }

    /**
     * Venue ids the message points at, in mention order: ordinals ("the first one", "#2", "dusra"),
     * "last one", "both/them" and distinctive name words ("Shalimar").
     */
    private function references(string $t): array
    {
        $shown = $this->conv['shown'] ?? [];
        $found = []; // position in text => id
        $n = count($shown);

        if ($n) {
            $words = [
                1 => 'first|1st|pehla|pehli|pehle|pahla|pahli|number one|no 1|#1|option 1|venue 1|option one',
                2 => 'second|2nd|dusra|doosra|dusri|doosri|dusre|doosre|number two|no 2|#2|option 2|venue 2|option two',
                3 => 'third|3rd|teesra|tisra|teesri|tisri|teesre|number three|no 3|#3|option 3|venue 3|option three',
                4 => 'fourth|4th|chautha|chotha|chauthi|chothi|number four|no 4|#4|option 4|venue 4|option four',
            ];
            $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec';
            foreach ($words as $i => $re) {
                if ($i > $n) {
                    if (preg_match('/(?<![\p{L}\p{N}])(' . $re . ')(?![\p{L}\p{N}])(?!\s*(of\s+)?(' . $months . '))/u', $t)) {
                        $this->outOfRange = $i;
                    }
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{N}])(' . $re . ')(?![\p{L}\p{N}])(?!\s*(of\s+)?(' . $months . '))/u', $t, $m, PREG_OFFSET_CAPTURE)) {
                    $found[$m[0][1]] = (int) $shown[$i - 1]['id'];
                }
            }
            if (preg_match('/(?:#|\bnumber |\bno |\boption |\bvenue )(\d{1,2})\b|\b(\d{1,2})(?:st|nd|rd|th) (?:one|venue|option)\b/u', $t, $m)
                && (int) ($m[1] ?: $m[2]) > $n) {
                $this->outOfRange = (int) ($m[1] ?: $m[2]);
            }
            if (preg_match('/\b(last|aakhri|akhri|final)( one| venue| option)?\b/u', $t, $m, PREG_OFFSET_CAPTURE)) {
                $found[$m[0][1]] = (int) $shown[$n - 1]['id'];
            }
            if (!$found && $n >= 2 && preg_match('/\b(both|them|these two|the two|dono|donon|these)\b/u', $t)) {
                $found[0] = (int) $shown[0]['id'];
                $found[1] = (int) $shown[1]['id'];
            }
        }

        // Names: distinctive words of shown venues first, then of any venue.
        foreach ([$shown, $this->facts['venues'] ?? []] as $pool) {
            foreach ($this->nameMatches($t, $pool) as $pos => $id) {
                if (!in_array($id, $found, true)) {
                    $found[$pos] = $id;
                }
            }
            if ($found && $pool === $shown) {
                break;
            }
        }
        ksort($found);
        return array_values(array_unique($found));
    }

    /** @return array<int, int> text position => venue id */
    private function nameMatches(string $t, array $pool): array
    {
        if ($this->nameFreq === null) {
            $this->nameFreq = [];
            foreach ($this->facts['venues'] ?? [] as $v) {
                foreach (array_unique($this->nameWords((string) $v['name'])) as $w) {
                    $this->nameFreq[$w] = ($this->nameFreq[$w] ?? 0) + 1;
                }
            }
        }
        $freq = $this->nameFreq;
        $places = array_flip($this->facts['places'] ?? []);
        $cities = array_flip(array_map(fn($c) => mb_strtolower($c['name']), $this->facts['cities'] ?? []));

        $out = [];
        foreach ($pool as $v) {
            $name = trim($this->normalize((string) $v['name']));
            if (mb_strlen($name) >= 6 && ($pos = mb_strpos($t, ' ' . $name . ' ')) !== false) {
                $out[$pos] = (int) $v['id'];
                continue;
            }
            $hits = 0;
            $first = null;
            $distinctive = false;
            foreach (array_unique($this->nameWords($name)) as $w) {
                if (in_array($w, self::NAME_GENERIC, true) || mb_strlen($w) < 4) {
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $t, $m, PREG_OFFSET_CAPTURE)) {
                    $hits++;
                    $first ??= $m[0][1];
                    // Area or city words ("Clifton", "Lahore") only count alongside another name word.
                    if (($freq[$w] ?? 0) <= 2 && !isset($places[$w]) && !isset($cities[$w])) {
                        $distinctive = true;
                    }
                }
            }
            if ($first !== null && ($distinctive || $hits >= 2)) {
                $out[$first] = (int) $v['id'];
            }
        }
        // A word shared by several venues ("Mughal" twice) stays ambiguous only if it is the only clue.
        return count($out) > 3 ? [] : $out;
    }

    private function nameWords(string $name): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: []));
    }

    private function isBooking(string $t, array $refs, bool $info): bool
    {
        if (!$this->has($t, '\b(book|reserve|booking|book karna|book karni|book kar|i ll take|ill take|lets go with|go with|finali[sz]e)\b')) {
            return false;
        }
        // "book a wedding hall in Lahore" is a search, "book it" / "book the second one" is not.
        return $refs !== [] || !$info && ($this->conv['focus'] || $this->conv['shown']);
    }

    /** What the message asks about a venue (or the result list), if anything. */
    private function question(string $t, array $refs, ?array $date, array $p, bool $info): ?array
    {
        $shown = $this->conv['shown'] ?? [];
        $target = $refs || $this->conv['focus'] || $this->has($t, '\b(it|its|this|that|this one|that one|is ka|is ki|us ka|us ki|uska|iska|wahan|wo|woh)\b');
        $aspect = $this->aspect($t);
        $asking = str_contains($this->raw, '?') || str_contains($this->raw, '؟');
        $state = $this->conv['state'];
        // A new city, occasion or area means a new search, unless a venue is named.
        $newSearch = !$refs && (($p['city'] && $p['city'] !== $state['city'])
            || ($p['event_type'] && $p['event_type'] !== $state['event_type']) || $p['place_terms']);
        $which = $this->has($t, '\b(which|konsa|kaunsa|kon sa|kaun sa|konsi|kaunsi|who has|any of them|of these|of them|in mein se)\b');

        if ($this->has($t, '\b(compare|comparison|vs|versus|difference|differences|muqabla|which is better|better one)\b')
            && ($refs || count($shown) >= 2)) {
            return ['type' => 'compare'];
        }

        if (!$refs && ($this->sortPhrase($t) || $this->has($t, '\b(' . self::REFINE . ')\b'))) {
            return null;
        }
        $metric = $this->superlative($t, $aspect);
        if ($metric && $shown && !$refs && !$newSearch && ($which || !$this->changesSearch($p))) {
            return ['type' => 'superlative', 'metric' => $metric];
        }
        if ($newSearch || ($metric && $which && !$shown)) {
            return null; // search first; searchTurn answers a "which … best" right after the list
        }
        // "which ones have parking?" filters the list rather than asking about one venue.
        if ($which && $p['amenities'] && !$refs) {
            return null;
        }

        if ($this->has($t, '\b(free|available|availability|vacant|khali|booked|dastiyab|open on|book on|date)\b')
            || ($date && $target && !$this->changesSearch($p))) {
            if ($target || count($shown) > 1) {
                $other = $this->has($t, '\b(another|other|different|new|dusri|doosri)\s+(date|day)\b');
                return ['type' => 'availability', 'date' => $date, 'ask' => $other && !$date];
            }
            return null;
        }

        if (!$target && !($shown && $asking)) {
            return null;
        }
        // "what about for 250 guests?" right after a price answer is still about that price.
        if (($this->conv['last'] ?? null) === 'price' && $this->conv['focus'] && $p['guests'] && !$newSearch
            && !$p['max_price'] && $this->has($t, '\b(what about|how about|and|for|if|agar)\b')) {
            return ['type' => 'price'];
        }
        if (!$refs && $this->changesSearch($p) && !$this->has($t, '\b(price|cost|how much|kitna|total)\b')) {
            return null;
        }

        if ($this->has($t, '\b(price|prices|pricing|cost|costs|how much|kitna|kitne ka|kitne ki|kitne mein|total|charges?|rates?|per head|qeemat|kharcha|bill|quote|estimate|expensive|budget for)\b')) {
            return ['type' => 'price'];
        }
        if ($aspect && ($this->has($t, '\b(how|good|bad|say|said|reviews?|feedback|kaisa|kaisi|kesa|kesi|quality|like|rate|rated|any complaints?|complain\w*|is the|are the)\b')
            || ($this->conv['focus'] && !$this->has($t, '\b(with|without|only|show|need|have|has|got|there|offer|provide|milti|milegi)\b') && str_word_count(trim($t)) <= 4))) {
            return ['type' => 'reviews', 'aspect' => $aspect];
        }
        if ($this->has($t, '\b(guests? say|people say|customers? say|reviews?|feedback|what do (people|guests|customers) (say|think)|log kya kehte|kaisa hai|kaisi hai|kesa hai|kesi hai|is it good|any good|worth it|recommend|experience|opinion|complaints?|pros|cons)\b')) {
            return ['type' => 'reviews', 'aspect' => null];
        }
        if ($this->has($t, '\b(amenit\w*|facilit\w*|included|extras?|add ons?|addons|services|what do they offer|what does it offer)\b')
            || ($p['amenities'] && $this->has($t, '\b(have|has|got|offer|offers|provide|provides|there|available|milti|milegi|hai)\b'))) {
            return ['type' => 'amenities', 'amenities' => $p['amenities']];
        }
        if ($this->has($t, '\b(capacity|how many (guests|people|persons)|fit|fits|accommodate|hold|seat|seats|seating|kitne log|kitne mehman|gunjaish|how big|size)\b')) {
            return ['type' => 'capacity'];
        }
        if ($this->has($t, '\b(where|location|address|located|kahan|kidhar|directions|map|area is)\b')) {
            return ['type' => 'location'];
        }
        if ($this->has($t, '\b(rating|ratings|stars?)\b') && !$this->has($t, '\b(top|best|highest|high|only)\b')) {
            return ['type' => 'rating'];
        }
        if ($refs && ($this->has($t, '\b(tell me|more about|details?|info|about|show|open|see|dekhao|dikhao|batao|what about|how about)\b')
            || !$info)) {
            return ['type' => 'details', 'open' => $this->has($t, '\b(open|go to|view page|visit page)\b')];
        }
        return null;
    }

    private function sortPhrase(string $t): ?string
    {
        foreach (self::SORT_PHRASES as $sort => $re) {
            if ($this->has($t, '\b(' . $re . ')\b')) {
                return $sort;
            }
        }
        return null;
    }

    /** Does the message change the search itself (city, occasion, guests, budget …)? */
    private function changesSearch(array $p): bool
    {
        return $p['city'] || $p['event_type'] || $p['guests'] || $p['max_price'] || $p['min_price'] || $p['place_terms'];
    }

    private function aspect(string $t): ?string
    {
        foreach (self::ASPECTS as $name => $words) {
            if ($this->has($t, '\b(' . implode('|', $words) . ')\b')) {
                return $name;
            }
        }
        return null;
    }

    private function superlative(string $t, ?string $aspect): ?string
    {
        if ($aspect && $this->has($t, '\b(best|better|good|great|nicest|tastiest|top|highest|most praised|acha|achi|behtar|behtareen)\b')) {
            return 'aspect:' . $aspect;
        }
        return match (true) {
            $this->has($t, '\b(cheapest|most affordable|lowest price|least expensive|sab se sasta|sabse sasta|lowest cost)\b') => 'cheapest',
            $this->has($t, '\b(most expensive|priciest|costliest|most premium|most luxurious|sab se mehnga|sabse mehnga)\b') => 'priciest',
            $this->has($t, '\b(biggest|largest|most capacity|most guests|sab se bara|sabse bara|sab se bada)\b') => 'biggest',
            $this->has($t, '\b(smallest|most intimate|sab se chota|sabse chota)\b') => 'smallest',
            // "only top rated" is a filter; "which is the highest rated?" / "the best one?" is a question.
            !$this->has($t, '\b(only|just|show)\b') && $this->has($t, '\b(highest rated|best rated|top rated|best reviewed|best reviews|most reviews|best one|the best)\b') => 'top_rated',
            default => null,
        };
    }

    // ═══ Answers about venues ════════════════════════════════════════════════

    private function answer(array $q, array $refs, array $p): array
    {
        $type = $q['type'];
        if ($type === 'superlative') {
            return $this->answerSuperlative($q['metric']);
        }
        if ($type === 'compare') {
            // Named venues first, then the one we were talking about, then the list in order.
            $ids = $refs;
            $fill = array_merge($this->conv['focus'] ? [(int) $this->conv['focus']] : [], array_map(fn($v) => (int) $v['id'], $this->conv['shown']));
            foreach ($fill as $id) {
                if (count($ids) < 2 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (count($ids) < 2) {
                return ['reply' => 'I need two venues to compare, and this list has only one. Want me to widen the search?',
                    'quick_replies' => $this->refineReplies(false)];
            }
            return $this->answerCompare($ids[0], $ids[1]);
        }

        $id = $refs[0] ?? null;
        if ($id === null) {
            $id = $this->conv['focus'];
            if (!$id && count($this->conv['shown']) === 1) {
                $id = (int) $this->conv['shown'][0]['id'];
            }
        }
        if (!$id) {
            if (!$this->conv['shown']) {
                return ['reply' => "Let's find some venues first. What's the occasion and which city?",
                    'quick_replies' => $this->starterReplies()];
            }
            return $this->askWhich($q, 'Which venue do you mean?');
        }

        $venue = $this->catalog->venue((int) $id);
        if (!$venue) {
            $this->conv['focus'] = null;
            return ['reply' => "Sorry, that venue isn't available any more. Want me to show other options?",
                'quick_replies' => $this->refineReplies()];
        }
        $this->conv['focus'] = $venue['id'];
        $this->conv['last'] = $type;
        if ($p['guests'] && $type !== 'book') {
            $this->conv['state']['guests'] = (int) $p['guests'];
        }

        return match ($type) {
            'book' => $this->answerBook($venue),
            'availability' => $this->answerAvailability($venue, $q['date'] ?? null, !empty($q['ask'])),
            'price' => $this->answerPrice($venue),
            'reviews' => $this->answerReviews($venue, $q['aspect'] ?? null),
            'amenities' => $this->answerAmenities($venue, $q['amenities'] ?? []),
            'capacity' => $this->answerCapacity($venue),
            'location' => $this->answerLocation($venue),
            'rating' => $this->answerRating($venue),
            default => $this->answerDetails($venue, !empty($q['open'])),
        };
    }

    private function askWhich(array $q, string $text): array
    {
        $this->conv['pending'] = 'venue';
        $this->conv['pending_q'] = $q;
        $replies = [];
        foreach (array_slice($this->conv['shown'], 0, 4) as $v) {
            $replies[] = $v['name'];
        }
        return ['reply' => $text, 'quick_replies' => $replies ?: $this->starterReplies()];
    }

    private function answerDetails(array $v, bool $open): array
    {
        $parts = ["**{$v['name']}** is in " . $this->place($v) . '.'];
        $cap = $this->capacityText($v);
        $price = $v['price_per_head'] ? '**' . $this->money($v['price_per_head']) . ' per head**' : null;
        if ($cap && $price) {
            $parts[] = "It hosts {$cap} guests at {$price}.";
        } elseif ($cap) {
            $parts[] = "It hosts {$cap} guests.";
        }
        $parts[] = $this->ratingSentence($v);
        $summary = $this->catalog->reviewSummary($v['id']);
        $praised = $this->praised($summary, 1);
        if ($praised) {
            $parts[] = 'Guests especially like the ' . $praised[0] . '.';
        }
        $out = [
            'reply' => implode(' ', array_filter($parts)),
            'venues' => [$v],
            'quick_replies' => $this->venueReplies($v, 'details'),
        ];
        if ($open) {
            $out['action'] = ['type' => 'open_venue', 'url' => '/venues/' . $v['id']];
        }
        return $out;
    }

    private function answerReviews(array $v, ?string $aspect): array
    {
        $s = $this->catalog->reviewSummary($v['id']);
        $name = "**{$v['name']}**";
        if ((int) ($s['analysed_count'] ?? 0) === 0) {
            $rating = $v['total_reviews'] > 0
                ? " It's rated {$this->stars($v)} from " . $this->plural($v['total_reviews'], 'rating') . '.'
                : '';
            return ['reply' => "{$name} doesn't have enough written reviews yet for me to summarise.{$rating}",
                'quick_replies' => $this->venueReplies($v, 'reviews')];
        }

        if ($aspect) {
            $label = self::ASPECT_NAMES[$aspect] ?? $aspect;
            $a = null;
            foreach ($s['aspects'] as $row) {
                if ($row['name'] === $aspect) {
                    $a = $row;
                }
            }
            if (!$a) {
                return ['reply' => "Guests haven't said much about the {$label} at {$name} yet. "
                    . "Overall, reviews are **{$this->sentimentWord($s)}**" . $this->countSuffix($s) . '.',
                    'quick_replies' => $this->venueReplies($v, 'reviews')];
            }
            $verdict = $a['positive_pct'] >= 75 ? 'Guests love the ' . $label
                : ($a['positive_pct'] >= 50 ? 'Mostly good things about the ' . $label
                : ($a['negative_pct'] >= 40 ? 'The ' . $label . ' gets mixed-to-poor feedback' : 'Feedback on the ' . $label . ' is mixed'));
            $reply = "{$verdict} at {$name}: **{$a['positive_pct']}% positive** from {$a['mentions']} guests who mentioned it"
                . ($a['negative_pct'] > 0 ? ", {$a['negative_pct']}% negative" : '') . '.';
            $quote = $this->quoteFor($aspect, $a['positive_pct'] >= 50 ? ($s['highlights'] ?? []) : ($s['concerns'] ?? []));
            if ($quote) {
                $reply .= ' One guest said: “' . $quote . '”';
            }
            return ['reply' => $reply, 'quick_replies' => $this->venueReplies($v, 'reviews')];
        }

        $reply = "Guests are **{$this->sentimentWord($s)}** about {$name}" . $this->countSuffix($s) . '.';
        $praised = $this->praised($s, 2);
        if ($praised) {
            $reply .= ' Most praised: ' . implode(', ', $praised) . '.';
        }
        $concerns = $this->concernAspects($s, 1);
        if ($concerns) {
            $reply .= ' Some mention issues with ' . $concerns[0] . '.';
        }
        if (!empty($s['highlights'][0])) {
            $reply .= "\nLiked: “" . $s['highlights'][0] . '”';
        }
        if (!empty($s['concerns'][0])) {
            $reply .= "\nCould be better: “" . $s['concerns'][0] . '”';
        }
        return ['reply' => $reply, 'quick_replies' => $this->venueReplies($v, 'reviews')];
    }

    private function answerPrice(array $v): array
    {
        $name = "**{$v['name']}**";
        $pph = $v['price_per_head'];
        if (!$pph) {
            return ['reply' => "{$name} doesn't list a per-head price. The venue page shows its pricing, or you can message the owner.",
                'quick_replies' => $this->venueReplies($v, 'price'),
                'action' => null];
        }
        $guests = $this->conv['state']['guests'];
        $paid = array_values(array_filter($v['amenities'] ?? [], fn($a) => $a['is_paid'] && $a['price']));
        $extras = '';
        if ($paid) {
            $list = array_map(fn($a) => $a['name'] . ' (' . $this->money($a['price']) . ')', array_slice($paid, 0, 3));
            $extras = ' Optional paid extras: ' . implode(', ', $list) . (count($paid) > 3 ? ' and more' : '') . '.';
        }
        if (!$guests) {
            $capMax = (int) ($v['capacity_max'] ?? 0);
            $example = max((int) ($v['capacity_min'] ?? 0), $capMax ? min(300, $capMax) : 300);
            $base = $pph * $example;
            return ['reply' => "{$name} charges **" . $this->money($pph) . ' per head**. For example, ' . number_format($example)
                . ' guests come to ' . $this->money($base) . ' + 5% tax = **' . $this->money($base * (1 + self::TAX_RATE)) . '**.'
                . $extras . ' How many guests are you expecting?',
                'quick_replies' => array_merge(array_map(fn($g) => "Price for {$g} guests", $this->guestBands($v, 3)), ['Is it free on ' . $this->suggestDate() . '?'])];
        }
        $base = $pph * $guests;
        $tax = $base * self::TAX_RATE;
        $reply = "For **" . number_format($guests) . " guests** at {$name}: " . number_format($guests) . ' × ' . $this->money($pph)
            . ' = ' . $this->money($base) . ', plus 5% tax (' . $this->money($tax) . ') = **' . $this->money($base + $tax) . '**.';
        $fit = $this->fitNote($v, $guests);
        if ($fit) {
            $reply .= ' ' . $fit;
        }
        return ['reply' => $reply . $extras, 'quick_replies' => $this->venueReplies($v, 'price')];
    }

    private function answerCapacity(array $v): array
    {
        $cap = $this->capacityText($v);
        $reply = $cap ? "**{$v['name']}** hosts **{$cap} guests**." : "**{$v['name']}** doesn't list its capacity.";
        $guests = $this->conv['state']['guests'];
        if ($guests && ($fit = $this->fitNote($v, $guests, true))) {
            $reply .= ' ' . $fit;
        }
        return ['reply' => $reply, 'quick_replies' => $this->venueReplies($v, 'capacity')];
    }

    private function answerAmenities(array $v, array $asked): array
    {
        $all = $v['amenities'] ?? [];
        $name = "**{$v['name']}**";
        if ($asked) {
            $lines = [];
            foreach ($asked as $want) {
                $hit = null;
                foreach ($all as $a) {
                    if (mb_strtolower($a['name']) === mb_strtolower($want)) {
                        $hit = $a;
                    }
                }
                $lines[] = $hit
                    ? "Yes, {$name} has **{$hit['name']}**" . ($hit['is_paid'] ? ($hit['price'] ? ' as a paid extra (' . $this->money($hit['price']) . ')' : ' as a paid extra') : ' (included)') . '.'
                    : "{$name} doesn't list **{$want}**.";
            }
            return ['reply' => implode(' ', $lines), 'quick_replies' => $this->venueReplies($v, 'amenities')];
        }
        if (!$all) {
            return ['reply' => "{$name} hasn't listed its facilities yet. You can message the owner from the venue page.",
                'quick_replies' => $this->venueReplies($v, 'amenities')];
        }
        $free = array_map(fn($a) => $a['name'], array_filter($all, fn($a) => !$a['is_paid']));
        $paid = array_map(fn($a) => $a['name'] . ($a['price'] ? ' (' . $this->money($a['price']) . ')' : ''), array_filter($all, fn($a) => $a['is_paid']));
        $reply = "At {$name}:";
        if ($free) {
            $reply .= "\nIncluded: " . implode(', ', $free) . '.';
        }
        if ($paid) {
            $reply .= "\nPaid extras: " . implode(', ', $paid) . '.';
        }
        return ['reply' => $reply, 'quick_replies' => $this->venueReplies($v, 'amenities')];
    }

    private function answerLocation(array $v): array
    {
        $address = trim((string) ($v['address'] ?? ''));
        $where = $address !== '' ? $address : $v['city'];
        if ($address !== '' && $v['city'] && mb_stripos($address, $v['city']) === false) {
            $where .= ', ' . $v['city'];
        }
        return ['reply' => "**{$v['name']}** is at {$where}. The venue page has a map and directions.",
            'quick_replies' => $this->venueReplies($v, 'location')];
    }

    private function answerRating(array $v): array
    {
        return ['reply' => $this->ratingSentence($v, true), 'quick_replies' => $this->venueReplies($v, 'rating')];
    }

    private function answerAvailability(array $v, ?array $date, bool $askNew = false): array
    {
        $date ??= !$askNew && $this->conv['state']['date'] ? ['date' => $this->conv['state']['date'], 'label' => CarbonImmutable::parse($this->conv['state']['date'])->format('l j F Y')] : null;
        if (!$date) {
            $this->conv['pending'] = 'date';
            $replies = [];
            foreach ($this->upcomingDates(3) as $d) {
                $replies[] = $d->format('D j M');
            }
            return ['reply' => "Which date are you thinking of for **{$v['name']}**? You can say something like \"12 March\" or \"next Saturday\".",
                'quick_replies' => $replies];
        }
        $day = CarbonImmutable::parse($date['date']);
        if ($day->lte($this->today)) {
            return ['reply' => 'Bookings need at least a day\'s notice, so please pick a date from tomorrow onwards.',
                'quick_replies' => array_map(fn($d) => 'Is it free on ' . $d->format('j M') . '?', $this->upcomingDates(2))];
        }
        $this->conv['state']['date'] = $date['date'];
        $booked = $this->catalog->bookingsOn([$v['id']], $date['date'])[$v['id']] ?? [];
        $open = $this->hours($v);
        $label = "**{$date['label']}**";
        if (!$booked) {
            $reply = "Good news: **{$v['name']}** has no bookings on {$label} yet"
                . ($open ? ", and it's open {$open}." : '.') . ' Dates can go quickly, so book soon to hold it.';
        } else {
            $slots = implode(', ', array_map(fn($s) => $this->clock($s['start']) . '–' . $this->clock($s['end']), $booked));
            $reply = "**{$v['name']}** already has " . $this->plural(count($booked), 'booking') . " on {$label} ({$slots})."
                . ($open ? " Other times within its hours ({$open}) may still be free; the booking page only lets you pick open slots." : '');
        }
        $short = $this->shortName($v['name']);
        $this->conv['offer'] = "Book {$short}";
        return ['reply' => $reply . ' Shall I open the booking page?', 'quick_replies' => [
            "Book {$short}",
            'Check another date',
            'What do guests say?',
            'Show other venues',
        ]];
    }

    private function answerBook(array $v): array
    {
        $guests = $this->conv['state']['guests'];
        $fits = $guests && (!$v['capacity_max'] || $guests <= $v['capacity_max']) && $guests >= (int) ($v['capacity_min'] ?: 1);
        $url = '/venues/' . $v['id'] . '/book' . ($fits ? '?guests=' . $guests : '');
        $reply = "Great choice! Opening the booking page for **{$v['name']}**"
            . ($fits ? ' with ' . number_format($guests) . ' guests filled in' : '')
            . '. Pick your date and time there; you\'ll be asked to sign in first if you aren\'t already.';
        if ($guests && !$fits) {
            $reply .= ' ' . $this->fitNote($v, $guests);
        }
        return ['reply' => $reply, 'venues' => [$v], 'action' => ['type' => 'open_venue', 'url' => $url],
            'quick_replies' => ['Keep looking', 'Start over']];
    }

    private function answerCompare(int $a, int $b): array
    {
        $va = $this->catalog->venue($a);
        $vb = $this->catalog->venue($b);
        if (!$va || !$vb) {
            return ['reply' => "Sorry, I couldn't load both venues to compare.", 'quick_replies' => $this->refineReplies()];
        }
        $sa = $this->catalog->reviewSummary($a);
        $sb = $this->catalog->reviewSummary($b);
        $line = fn(string $label, string $x, string $y) => "\n• {$label}: {$x} vs {$y}";
        $reply = "**{$va['name']}** vs **{$vb['name']}**";
        $reply .= $line('Price', $va['price_per_head'] ? $this->money($va['price_per_head']) . '/head' : 'n/a',
            $vb['price_per_head'] ? $this->money($vb['price_per_head']) . '/head' : 'n/a');
        $reply .= $line('Guests', $this->capacityText($va) ?: 'n/a', $this->capacityText($vb) ?: 'n/a');
        $reply .= $line('Rating', $va['total_reviews'] ? $this->stars($va) . ' (' . $va['total_reviews'] . ')' : 'no reviews',
            $vb['total_reviews'] ? $this->stars($vb) . ' (' . $vb['total_reviews'] . ')' : 'no reviews');
        $reply .= $line('Area', $this->place($va), $this->place($vb));
        $pa = $this->praised($sa, 1)[0] ?? null;
        $pb = $this->praised($sb, 1)[0] ?? null;
        if ($pa || $pb) {
            $reply .= $line('Guests praise', $pa ?? '—', $pb ?? '—');
        }

        $verdict = [];
        if ($va['price_per_head'] && $vb['price_per_head'] && $va['price_per_head'] != $vb['price_per_head']) {
            $cheap = $va['price_per_head'] < $vb['price_per_head'] ? $va : $vb;
            $verdict[] = "**{$cheap['name']}** is cheaper";
        }
        if ($va['total_reviews'] && $vb['total_reviews'] && abs($va['avg_rating'] - $vb['avg_rating']) >= 0.05) {
            $better = $va['avg_rating'] > $vb['avg_rating'] ? $va : $vb;
            $verdict[] = "**{$better['name']}** is rated higher";
        }
        if ($verdict) {
            $reply .= "\n" . ucfirst(implode('; ', $verdict)) . '.';
        }
        $this->conv['focus'] = null;
        return ['reply' => $reply, 'venues' => [$va, $vb], 'quick_replies' => [
            'Book ' . $this->shortName($va['name']),
            'Book ' . $this->shortName($vb['name']),
            'What do guests say about ' . $this->shortName($va['name']) . '?',
            'Show other venues',
        ]];
    }

    private function answerSuperlative(string $metric): array
    {
        $shown = $this->conv['shown'];
        $best = null;
        $why = '';
        if (str_starts_with($metric, 'aspect:')) {
            $aspect = substr($metric, 7);
            $label = self::ASPECT_NAMES[$aspect] ?? $aspect;
            $bestScore = -1;
            foreach ($shown as $v) {
                foreach ($this->catalog->reviewSummary($v['id'])['aspects'] ?? [] as $a) {
                    if ($a['name'] === $aspect && $a['mentions'] >= 3) {
                        $score = $a['positive_pct'] - 0.5 * $a['negative_pct'] + min(5, $a['mentions'] / 4);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = $v;
                            $why = "{$a['positive_pct']}% of the {$a['mentions']} guests who mentioned the {$label} were positive";
                        }
                    }
                }
            }
            if (!$best) {
                return ['reply' => "None of these venues have enough reviews about the {$label} yet to say which is best.",
                    'quick_replies' => $this->refineReplies()];
            }
            $reply = "Of these, **{$best['name']}** has the best feedback on the {$label}: {$why}.";
        } else {
            $priced = array_values(array_filter($shown, fn($v) => $v['price_per_head']));
            $rated = array_values(array_filter($shown, fn($v) => $v['total_reviews'] > 0));
            $pick = function (array $list, callable $key, bool $max) {
                usort($list, fn($x, $y) => $max ? $key($y) <=> $key($x) : $key($x) <=> $key($y));
                return $list[0] ?? null;
            };
            [$best, $why] = match ($metric) {
                'cheapest' => [$b = $pick($priced, fn($v) => $v['price_per_head'], false), $b ? 'at ' . $this->money($b['price_per_head']) . ' per head' : ''],
                'priciest' => [$b = $pick($priced, fn($v) => $v['price_per_head'], true), $b ? 'at ' . $this->money($b['price_per_head']) . ' per head' : ''],
                'biggest' => [$b = $pick($shown, fn($v) => (int) $v['capacity_max'], true), $b ? 'with room for up to ' . number_format($b['capacity_max']) . ' guests' : ''],
                'smallest' => [$b = $pick($shown, fn($v) => (int) $v['capacity_max'], false), $b ? 'for up to ' . number_format($b['capacity_max']) . ' guests' : ''],
                default => [$b = $pick($rated, fn($v) => $v['avg_rating'] + min(0.2, $v['total_reviews'] / 500), true),
                    $b ? 'rated ' . $this->stars($b) . ' from ' . $this->plural($b['total_reviews'], 'review') : ''],
            };
            if (!$best) {
                return ['reply' => $metric === 'top_rated'
                    ? "None of these venues have reviews yet, so I can't rank them by rating."
                    : "I can't tell from these results, as none of them list that yet.",
                    'quick_replies' => $this->refineReplies()];
            }
            $word = ['cheapest' => 'the most affordable', 'priciest' => 'the most premium', 'biggest' => 'the largest',
                'smallest' => 'the smallest', 'top_rated' => 'the highest rated'][$metric];
            $reply = "Of these, **{$best['name']}** is {$word}, {$why}.";
        }
        $this->conv['focus'] = (int) $best['id'];
        $card = $this->catalog->venue((int) $best['id']) ?? $best;
        return ['reply' => $reply, 'venues' => [$card], 'quick_replies' => $this->venueReplies($best, 'superlative')];
    }

    // ═══ Search turns ════════════════════════════════════════════════════════

    private function searchTurn(string $t, string $message, array $p, ?array $date, ?string $pending): array
    {
        $this->conv['last'] = 'search';
        $s = $this->conv['state'];
        $before = $s;
        $notes = [];
        $recognized = false;
        $page = 1;
        $relative = null;

        // ── Direct answers to the question we just asked ──
        if (preg_match('/^\s*(\d{1,6})\s*(guests?|people|log|afrad|persons?|pax)?\s*$/u', trim($t), $m) && in_array($pending, ['guests', 'budget'], true)) {
            $n = (int) $m[1];
            if ($pending === 'guests') {
                $p['guests'] = $n;
                $p['max_price'] = null;
            } else {
                $p['max_price'] = (float) $n;
                $p['guests'] = null;
            }
        }
        $skip = $this->has($t, '\b(not sure|skip|no idea|dont know|pata nahi|koi bhi|whatever|no preference|doesnt matter|does not matter|later|any|anything|abhi nahi|something else|other)\b');
        if ($pending && $skip && !$this->changesSearch($p)) {
            $this->conv['asked'][$pending] = true;
            $recognized = true;
            if ($pending === 'budget') {
                $s['max_price'] = $s['min_price'] = null;
            }
        }

        // ── Clearing things ──
        if ($this->has($t, '\b(any city|all cities|every city|anywhere|any where|kahin bhi|all over pakistan|whole country|across pakistan)\b')) {
            $s['city'] = null;
            $s['terms'] = array_values(array_diff($s['terms'], $this->facts['places'] ?? []));
            $this->conv['asked']['city'] = true;
            $recognized = true;
            $notes[] = 'Looking in every city.';
        }
        if ($this->has($t, '\b(different city|another city|other city|other cities|change (the )?city|dusra shehar|doosra shehar)\b') && !$p['city']) {
            $exclude = $s['city'];
            $s['city'] = null;
            $this->conv['state'] = $s;
            $this->conv['pending'] = 'city';
            return ['reply' => 'Sure. Which city should I look in instead?', 'quick_replies' => $this->cityReplies($s['event_type'], $exclude)];
        }
        if ($this->has($t, '\b(no budget|any budget|remove (the )?budget|budget (doesnt|does not|dont|wont) matter|no (price )?limit|without (a )?budget|ignore (the )?budget|any price|forget (the )?budget|clear (the )?budget)\b')) {
            $s['max_price'] = $s['min_price'] = null;
            $s['intents'] = array_values(array_diff($s['intents'], ['cheap', 'luxury']));
            $p['intents'] = array_values(array_diff($p['intents'], ['cheap'])); // the parser reads "budget" as cheap
            $this->conv['asked']['budget'] = true;
            $recognized = true;
            $notes[] = 'No budget limit now.';
        }
        if ($this->has($t, '\b(any (occasion|event|event type|type)|all (occasions|events|event types))\b')) {
            $s['event_type'] = null;
            $this->conv['asked']['event_type'] = true;
            $recognized = true;
        }
        if ($this->has($t, '\b(any (size|number of guests|guest count)|remove (the )?guests?( count)?)\b')) {
            $s['guests'] = $s['min_capacity'] = $s['max_capacity'] = null;
            $recognized = true;
        }
        if ($this->has($t, '\b(any area|anywhere in|any part of|remove (the )?area)\b')) {
            $s['terms'] = array_values(array_diff($s['terms'], $this->facts['places'] ?? []));
            $recognized = true;
        }
        if ($this->has($t, '\b(any rating|remove (the )?rating|not just top rated|include all)\b')) {
            $s['min_rating'] = null;
            $s['intents'] = array_values(array_diff($s['intents'], ['top_rated']));
            $recognized = true;
        }
        if (preg_match('/\b(?:without|no need (?:of|for)|remove|dont need|do not need|bina)\b(?<rest>.*)$/u', $t, $m) && $s['amenities']) {
            $drop = $this->parser()->parse($m['rest'])['amenities'];
            if ($drop) {
                $s['amenities'] = array_values(array_diff($s['amenities'], $drop));
                $p['amenities'] = array_values(array_diff($p['amenities'], $drop));
                $recognized = true;
            }
        }

        // ── Merge what the message says ──
        $cityChanged = $p['city'] && $p['city'] !== $s['city'];
        $eventChanged = $p['event_type'] && $p['event_type'] !== $s['event_type'];
        if ($p['city']) {
            if ($cityChanged) {
                $s['terms'] = array_values(array_diff($s['terms'], $this->facts['places'] ?? []));
            }
            $s['city'] = $p['city'];
        }
        if ($p['event_type']) {
            $s['event_type'] = $p['event_type'];
        }
        if ($p['guests']) {
            $s['guests'] = (int) $p['guests'];
            $s['min_capacity'] = $s['max_capacity'] = null;
        }
        if ($p['max_price']) {
            $s['max_price'] = (float) $p['max_price'];
            if ($s['min_price'] && $s['min_price'] > $s['max_price']) {
                $s['min_price'] = null;
            }
            $s['intents'] = array_values(array_diff($s['intents'], ['luxury']));
        }
        if ($p['min_price']) {
            $s['min_price'] = (float) $p['min_price'];
        }
        if ($p['amenities']) {
            $s['amenities'] = array_values(array_unique(array_merge($s['amenities'], $p['amenities'])));
        }
        if ($p['place_terms']) {
            $s['terms'] = array_values(array_unique(array_merge(array_diff($s['terms'], $this->facts['places'] ?? []), $p['place_terms'])));
        }
        if ($p['type_terms']) {
            $s['terms'] = array_values(array_unique(array_merge(array_intersect($s['terms'], $this->facts['places'] ?? []), $p['type_terms'])));
        }
        if ($date) {
            if (CarbonImmutable::parse($date['date'])->gt($this->today)) {
                $s['date'] = $date['date'];
            } else {
                $notes[] = 'Bookings need a day\'s notice, so I can\'t check that date.';
            }
            $recognized = true;
        }

        // ── Relative refinements ("cheaper", "bigger", "more") ──
        $shown = $this->conv['shown'];
        $sameSearch = !$cityChanged && !$eventChanged;
        if ($this->has($t, '\b(more options|show more|more venues|see more|more results|load more|next( page| ones)?|any more|anything else|aur dikhao|aur dikhayen|or dikhao|more please|others|other options|other venues|show other venues|keep looking)\b')
            || trim($t) === 'more') {
            $recognized = true;
            if ($this->has($t, '\b(other venues|show other venues|keep looking)\b') && !$shown) {
                // fall through to a normal search
            } elseif ($s === $before && $shown) {
                $page = (int) $this->conv['page'] + 1;
                $relative = 'more';
            }
        }
        if ($this->has($t, '\b(cheaper|less expensive|more affordable|lower (price|budget|cost)|too expensive|too costly|bohat mehnga|mehnga hai|mehngi hai|saste|sasta|sasti|kam qeemat|kam price|less costly|budget friendly)\b')
            && $shown && $sameSearch && !$p['max_price']) {
            $prices = $this->prices($shown);
            if ($prices) {
                $median = $prices[(int) floor((count($prices) - 1) / 2)];
                $cap = floor(($median / 1.15) / 100) * 100;
                $s['max_price'] = $s['max_price'] ? min($s['max_price'], $cap) : $cap;
                $s['min_price'] = null;
                $s['intents'] = array_values(array_unique(array_merge(array_diff($s['intents'], ['luxury']), ['cheap'])));
                $relative = 'cheaper';
                $recognized = true;
            }
        }
        if ($this->has($t, '\b(more premium|premium|luxur\w*|fancier|more expensive|upscale|high end|classier|posh|more upmarket|better quality)\b')
            && $shown && $sameSearch && !$p['min_price']) {
            $prices = $this->prices($shown);
            if ($prices) {
                $s['min_price'] = $prices[(int) floor((count($prices) - 1) / 2)];
                $s['max_price'] = null;
                $s['intents'] = array_values(array_unique(array_merge(array_diff($s['intents'], ['cheap']), ['luxury'])));
                $relative = 'premium';
                $recognized = true;
            }
        }
        if ($this->has($t, '\b(bigger|larger|more capacity|more space|bara|bari|bada|badi|zyada gunjaish)\b') && $shown && $sameSearch && !$p['guests']) {
            $caps = array_values(array_filter(array_map(fn($v) => (int) $v['capacity_max'], $shown)));
            sort($caps);
            if ($caps) {
                $s['min_capacity'] = $caps[(int) floor((count($caps) - 1) / 2)] + 1;
                $s['max_capacity'] = null;
                $relative = 'bigger';
                $recognized = true;
            }
        }
        if ($this->has($t, '\b(smaller|more intimate|cozier|cosier|chota|choti|chhota|chhoti|less capacity)\b') && $shown && $sameSearch && !$p['guests']) {
            $caps = array_values(array_filter(array_map(fn($v) => (int) $v['capacity_max'], $shown)));
            sort($caps);
            if ($caps) {
                $limit = $caps[(int) floor((count($caps) - 1) / 2)] - 1;
                if ($s['guests'] && $limit < $s['guests']) {
                    $limit = $caps[0] > $s['guests'] ? $caps[0] - 1 : null;
                }
                if ($limit) {
                    $s['max_capacity'] = $limit;
                    $s['min_capacity'] = null;
                    $relative = 'smaller';
                } else {
                    $notes[] = 'These are already the smallest venues that fit ' . number_format($s['guests']) . ' guests.';
                }
                $recognized = true;
            }
        }
        $sort = $this->sortPhrase($t);
        if (!$sort && ($this->has($t, '\b(only (the )?(best|top)|top rated only|good reviews|well reviewed|highly rated|4\+|above 4|4 stars?)\b')
            || ($this->has($t, '\b(top rated|highest rated|best rated)\b') && $shown && $sameSearch && !$this->changesSearch($p)))) {
            $s['min_rating'] = 4.0;
            $relative ??= 'top';
            $recognized = true;
        }

        // ── Sort ──
        if ($sort) {
            $s['sort'] = $sort;
            $relative ??= $sort !== $before['sort'] ? 'sort' : null;
            $recognized = true;
        }

        // Remaining intents (fresh searches: "cheap", "luxury", "popular", "new").
        foreach ($p['intents'] as $intent) {
            if ($intent === 'cheap' && $relative === null) {
                $s['intents'] = array_values(array_diff($s['intents'], ['luxury']));
            }
            if ($intent === 'luxury') {
                $s['intents'] = array_values(array_diff($s['intents'], ['cheap']));
            }
            if (!in_array($intent, $s['intents'], true)) {
                $s['intents'][] = $intent;
            }
        }

        $changed = $s !== $before;
        $recognized = $recognized || $changed || $this->searchSignals($p)
            || $this->has($t, '\b(venue|venues|hall|halls|place|places|find|search|looking|need|chahiye|show|dikhao|options|suggest|recommend)\b');

        if (!$recognized) {
            if ($pending === 'city' && preg_match('/^\s*[\p{L}]{3,}(\s+[\p{L}]{3,})?\s*$/u', trim($t))) {
                $this->conv['pending'] = 'city';
                return ['reply' => "I don't have venues in **" . ucwords(trim($t)) . "** yet. These cities have the most venues:",
                    'quick_replies' => $this->cityReplies($s['event_type'])];
            }
            return $this->fallback($p['unknown_terms']);
        }

        $this->conv['state'] = $s;

        // ── Slot filling: one question at a time, only while the request is vague ──
        $whichBest = $this->superlative($t, $this->aspect($t)) && $this->has($t, '\b(which|konsa|kaunsa|kon sa|kaun sa|konsi|kaunsi)\b');
        $need = $whichBest ? null : $this->nextSlot($s);
        if ($need) {
            $this->conv['asked'][$need] = true;
            $this->conv['pending'] = $need;
            return $this->askSlot($need, $s, $notes);
        }

        if ($pending && !$changed && $relative === null && $skip && !$notes) {
            $notes[] = 'No problem.';
        }

        $result = $this->runSearch($page, $page > 1 ? $this->conv['search_id'] : null, $message);

        // Relative refinement that found nothing new: say so and keep the old list.
        $sameIds = $shown && array_column($shown, 'id') == array_column($result['items'], 'id');
        if (in_array($relative, ['cheaper', 'premium', 'bigger', 'smaller', 'top'], true) && ($result['total'] === 0 || $sameIds)) {
            $this->conv['state'] = $before;
            $msg = match ($relative) {
                'cheaper' => 'These are already the most affordable options for this search. The lowest price here is '
                    . ($this->prices($shown) ? $this->money($this->prices($shown)[0]) . ' per head.' : 'shown above.'),
                'premium' => "I don't have pricier venues for this search.",
                'bigger' => 'These are the largest venues I have for this search.',
                'smaller' => 'These are the smallest venues I have for this search.',
                default => $result['total'] === 0 ? 'None of these venues have a 4-star rating or better yet.'
                    : 'All of these are already rated 4 stars or more.',
            };
            return ['reply' => $msg . ' Want to try another city or remove a filter?', 'quick_replies' => $this->refineReplies(false)];
        }
        if ($relative === 'more' && !$result['items']) {
            $this->conv['page'] = max(1, $page - 1);
            return ['reply' => 'That\'s everything I have for this search (' . $this->plural((int) $this->conv['total'], 'venue') . '). '
                . 'Try another city, a different budget or fewer filters.',
                'quick_replies' => $this->refineReplies(false)];
        }

        $relaxed = [];
        if ($result['total'] === 0) {
            [$result, $relaxed] = $this->relax($message);
        }
        if ($result['total'] === 0) {
            return ['reply' => "I couldn't find any venues for that yet. Try another city or occasion.",
                'quick_replies' => $this->starterReplies()];
        }

        $this->remember($result, $page);
        $items = $this->annotateDate($result['items']);
        $this->conv['shown'] = array_map(fn($v) => $this->memo($v), $items);

        $out = $this->composeResults($result, $page, $relative, $relaxed, $notes);
        $out['venues'] = $items;

        // "Which hall in Lahore has the best food?" — answer it right after showing the list.
        $metric = $this->superlative($t, $this->aspect($t));
        if ($metric && $page === 1 && $this->has($t, '\b(which|konsa|kaunsa|kon sa|kaun sa|konsi|kaunsi)\b')) {
            $out['reply'] .= "\n" . $this->answerSuperlative($metric)['reply'];
        }
        if ($out['question']) {
            $out['reply'] .= "\n" . $out['question'];
        }
        unset($out['question']);
        return $out;
    }

    private function nextSlot(array $s): ?string
    {
        if ($this->conv['total'] !== null) {
            return null; // once results are on screen, refine instead of interrogating
        }
        $asked = $this->conv['asked'] ?? [];
        $signals = (int) (bool) $s['city'] + (int) (bool) $s['guests'] + (int) (bool) ($s['max_price'] || $s['min_price'])
            + (int) (bool) ($s['amenities'] || array_intersect($s['terms'], $this->facts['places'] ?? []))
            + (int) (bool) $s['date'];
        if (!$s['event_type'] && empty($asked['event_type']) && $signals < 2) {
            return 'event_type';
        }
        $placeTerm = (bool) array_intersect($s['terms'], $this->facts['places'] ?? []);
        if (!$s['city'] && empty($asked['city']) && !$placeTerm) {
            return 'city';
        }
        return null;
    }

    private function askSlot(string $slot, array $s, array $notes): array
    {
        $ack = $this->ack($s);
        $prefix = trim(implode(' ', $notes) . ' ' . ($ack ? "Got it: {$ack}." : ''));
        $prefix = $prefix !== '' ? $prefix . ' ' : '';
        if ($slot === 'event_type') {
            return ['reply' => $prefix . "What's the occasion?", 'quick_replies' => $this->eventReplies($s['city'])];
        }
        $what = $s['event_type'] ? 'the ' . mb_strtolower(self::EVENT_LABELS[$s['event_type']] ?? $this->categoryName($s['event_type'])) : 'your event';
        return ['reply' => $prefix . "Which city is {$what} in?", 'quick_replies' => $this->cityReplies($s['event_type'])];
    }

    /** Search with the current state (logged like any other search). */
    private function runSearch(int $page, ?string $searchId, string $message, bool $log = true, int $perPage = self::PER_PAGE): array
    {
        return $this->catalog->search($this->conv['state'], $this->user, [
            'page' => $page,
            'per_page' => $perPage,
            'log' => $log,
            'search_id' => $searchId,
            'query' => $this->describeQuery($this->conv['state']),
        ]);
    }

    /** Nothing matched: drop the least important constraint until something does. */
    private function relax(string $message): array
    {
        $steps = [
            ['min_rating', 'the rating filter'], ['max_capacity', 'the size limit'], ['min_capacity', 'the size limit'],
            ['amenities', 'some of the facilities'], ['terms', 'the area or venue type'], ['max_price', 'the budget'],
            ['guests', 'the guest count'], ['event_type', 'the occasion'], ['city', 'the city'],
        ];
        $dropped = [];
        foreach ($steps as [$key, $label]) {
            $s = $this->conv['state'];
            if (empty($s[$key])) {
                continue;
            }
            $s[$key] = in_array($key, ['amenities', 'terms'], true) ? [] : null;
            $this->conv['asked'][['max_price' => 'budget'][$key] ?? $key] = true;
            if ($key === 'max_price') {
                $s['min_price'] = null;
                $s['intents'] = array_values(array_diff($s['intents'], ['cheap']));
            }
            $this->conv['state'] = $s;
            if (!in_array($label, $dropped, true)) {
                $dropped[] = $label;
            }
            $probe = $this->runSearch(1, null, $message, false, 1);
            if ($probe['total'] > 0) {
                return [$this->runSearch(1, null, $message), $dropped];
            }
        }
        return [['items' => [], 'total' => 0, 'search_id' => null], $dropped];
    }

    private function remember(array $result, int $page): void
    {
        $this->conv['page'] = $page;
        $this->conv['total'] = $result['total'];
        $this->conv['search_id'] = $result['search_id'] ?? $this->conv['search_id'];
        $ids = array_map(fn($v) => (int) $v['id'], $result['items']);
        if (!in_array((int) $this->conv['focus'], $ids, true)) {
            $this->conv['focus'] = null;
        }
    }

    /** With a date in the state, show whether each venue already has bookings that day. */
    private function annotateDate(array $items): array
    {
        $date = $this->conv['state']['date'];
        if (!$date || !$items) {
            return $items;
        }
        $booked = $this->catalog->bookingsOn(array_map(fn($v) => (int) $v['id'], $items), $date);
        $label = CarbonImmutable::parse($date)->format('j M');
        foreach ($items as &$v) {
            $n = count($booked[$v['id']] ?? []);
            $reason = $n === 0 ? "No bookings yet on {$label}" : "Partly booked on {$label}";
            $v['match']['reasons'] = array_values(array_unique(array_merge([$reason], $v['match']['reasons'] ?? [])));
            $v['availability'] = ['date' => $date, 'bookings' => $n];
        }
        return $items;
    }

    private function composeResults(array $result, int $page, ?string $relative, array $relaxed, array $notes): array
    {
        $s = $this->conv['state'];
        $total = $result['total'];
        $count = count($result['items']);
        $what = $this->describe($s);
        $from = ($page - 1) * self::PER_PAGE + 1;
        $to = $from + $count - 1;

        $lines = [];
        if ($notes) {
            $lines[] = implode(' ', $notes);
        }
        if ($relaxed) {
            $lines[] = "Nothing matched everything, so I've set aside " . $this->listJoin($relaxed) . '.';
        }
        $lead = match ($relative) {
            'more' => "Here are more {$what} ({$from}–{$to} of {$total}):",
            'cheaper' => 'Here are more affordable ' . $this->describe($s, false) . ', up to about ' . $this->money($s['max_price']) . ' per head:',
            'premium' => "Here are more premium {$what}:",
            'bigger' => 'Here are bigger ' . $this->describe($s, true, false) . ', for more than ' . number_format($s['min_capacity'] - 1) . ' guests:',
            'smaller' => 'Here are smaller ' . $this->describe($s, true, false) . ', for up to ' . number_format($s['max_capacity']) . ' guests:',
            'top' => "Here are the top rated {$what} (4 stars and up):",
            'sort' => 'Here are the ' . $what . ', ' . (['price_asc' => 'cheapest first', 'price_desc' => 'most expensive first',
                'rating' => 'highest rated first', 'newest' => 'newest first'][$s['sort']] ?? 'best matches first') . ':',
            default => $total === 1 ? "I found **1** {$this->singular($what)}:"
                : ($total <= self::PER_PAGE ? "I found **{$total}** {$what}:" : "I found **{$total}** {$what}. Here are the best matches:"),
        };
        $lines[] = $lead;

        // One follow-up question, only for what still matters.
        $asked = $this->conv['asked'];
        $replies = [];
        $question = null;
        if (!$s['guests'] && empty($asked['guests'])) {
            $question = 'How many guests are you expecting? I\'ll check capacity and work out the total.';
            $this->conv['pending'] = 'guests';
            $this->conv['asked']['guests'] = true;
            $replies = array_map(fn($g) => "{$g} guests", $this->guestBands(null, 4));
            $replies[] = 'Not sure yet';
        } elseif (!$s['max_price'] && !$s['min_price'] && empty($asked['budget']) && $total > 1 && $relative !== 'premium') {
            $question = 'Do you have a budget per head?';
            $this->conv['pending'] = 'budget';
            $this->conv['asked']['budget'] = true;
            $replies = $this->budgetReplies($s['city']);
        } else {
            $replies = $this->refineReplies(true, $total > $page * self::PER_PAGE);
        }

        return ['reply' => implode(' ', $lines), 'question' => $question, 'quick_replies' => $replies];
    }

    // ═══ Other turns ═════════════════════════════════════════════════════════

    private function reset(): array
    {
        $turns = $this->conv['turns'];
        $this->conv = ConversationStore::blank();
        $this->conv['turns'] = $turns;
        return ['reply' => "Fresh start! What are you planning? Tell me the occasion, city and roughly how many guests.",
            'quick_replies' => $this->starterReplies()];
    }

    private function greet(): array
    {
        $name = $this->user ? trim(explode(' ', (string) $this->user->name)[0]) : '';
        return ['reply' => ($name !== '' ? "Hi {$name}! " : 'Hi there! ') . "What are you planning? Tell me the occasion and city and I'll find venues.",
            'quick_replies' => $this->starterReplies()];
    }

    private function help(): array
    {
        return ['reply' => "I can find and compare venues for you. Tell me the occasion, city, guest count and budget, "
            . "for example \"wedding hall in Lahore for 300 guests under 4,000 per head\".\n"
            . "Then ask things like \"cheaper ones\", \"what do guests say about the first one?\", "
            . "\"is it free on 12 March?\", \"compare the first two\" or \"book it\".",
            'quick_replies' => $this->starterReplies()];
    }

    private function fallback(array $unknown = []): array
    {
        $shown = (bool) $this->conv['shown'];
        $start = $unknown ? 'Sorry, I didn\'t catch that.' : 'Sorry, I didn\'t quite get that.';
        $reply = $shown
            ? "{$start} You can ask about the venues above (\"is the first one free on 12 March?\", \"what do guests say?\"), "
              . 'or change the search: a city, budget or guest count.'
            : "{$start} I can help you find a venue: tell me the occasion, city and how many guests.";
        return ['reply' => $reply, 'quick_replies' => $shown ? $this->refineReplies() : $this->starterReplies()];
    }

    private function openSearch(): array
    {
        $s = $this->conv['state'];
        $query = array_filter([
            'q' => $this->describeQuery(array_merge($s, ['city' => null, 'event_type' => null])) ?: null,
            'city' => $s['city'],
            'event_type' => $s['event_type'],
            'sort' => ['price_asc' => 'price_per_head', 'rating' => 'avg_rating', 'newest' => 'created_at'][$s['sort']] ?? null,
        ]);
        return ['reply' => 'Opening the full list on the venues page, where you can filter and sort further.',
            'action' => ['type' => 'open_search', 'url' => '/venues' . ($query ? '?' . http_build_query($query) : '')],
            'quick_replies' => $this->refineReplies()];
    }

    // ═══ Quick replies ═══════════════════════════════════════════════════════

    private function starterReplies(): array
    {
        $cities = array_column($this->facts['cities'] ?? [], 'name');
        $cats = array_column($this->facts['categories'] ?? [], 'slug');
        $out = [];
        if ($cities) {
            $out[] = 'Wedding hall in ' . $cities[0] . ' for 300 guests';
        }
        if (count($cities) > 1 && in_array('birthday_parties', $cats, true)) {
            $out[] = 'Birthday party in ' . $cities[1] . ' under 3,000 per head';
        }
        if (count($cities) > 2 && (in_array('conferences', $cats, true) || in_array('corporate_events', $cats, true))) {
            $out[] = 'Conference venue in ' . $cities[2] . ' for 150 people';
        }
        $out[] = 'How does this work?';
        return $out;
    }

    private function eventReplies(?string $city): array
    {
        $cats = $this->facts['categories'] ?? [];
        if ($city) {
            $byCat = $this->facts['city_by_category'] ?? [];
            $cats = array_values(array_filter($cats, fn($c) => !empty($byCat[$c['slug']][$city])));
        }
        // Most common occasions first; only ones that real venues offer (in that city) are listed.
        $order = array_flip(array_keys(self::EVENT_LABELS));
        usort($cats, fn($a, $b) => ($order[$a['slug']] ?? 99) <=> ($order[$b['slug']] ?? 99));
        $out = [];
        foreach ($cats as $c) {
            if (count($out) >= 4) {
                break;
            }
            $out[] = self::EVENT_LABELS[$c['slug']] ?? $c['name'];
        }
        $out[] = 'Something else';
        return $out;
    }

    private function cityReplies(?string $eventType, ?string $exclude = null): array
    {
        $cities = $eventType && !empty($this->facts['city_by_category'][$eventType])
            ? array_keys($this->facts['city_by_category'][$eventType])
            : array_column($this->facts['cities'] ?? [], 'name');
        $cities = array_values(array_filter($cities, fn($c) => $c !== $exclude));
        $out = array_slice($cities, 0, 4);
        $out[] = 'Any city';
        return $out;
    }

    /** Typical guest counts that some venue (or this venue) can actually host. */
    private function guestBands(?array $venue, int $n): array
    {
        $max = $venue ? (int) ($venue['capacity_max'] ?: 0) : (int) ($this->facts['max_capacity'] ?? 0);
        $min = $venue ? (int) ($venue['capacity_min'] ?: 0) : 0;
        $bands = array_values(array_filter([100, 200, 300, 500, 800, 1000], fn($g) => (!$max || $g <= $max) && $g >= $min));
        if (!$bands && $max) {
            $bands = [$max];
        }
        return array_slice($bands, 0, $n);
    }

    private function budgetReplies(?string $city): array
    {
        $p = $city && !empty($this->facts['prices_by_city'][$city]) ? $this->facts['prices_by_city'][$city] : ($this->facts['prices'] ?? []);
        $bands = [];
        foreach (['p25', 'p50', 'p75'] as $k) {
            if (!empty($p[$k])) {
                $bands[] = (int) (ceil($p[$k] / 500) * 500);
            }
        }
        $bands = array_values(array_unique($bands));
        $out = array_map(fn($b) => 'Under ' . number_format($b) . ' per head', $bands);
        $out[] = 'Any budget';
        return $out;
    }

    private function refineReplies(bool $withVenues = true, bool $more = false): array
    {
        $out = [];
        if ($withVenues && $this->conv['shown']) {
            $out[] = 'What do guests say about the first one?';
            $out[] = 'Is the first one free on ' . $this->suggestDate() . '?';
            $out[] = 'Cheaper ones';
        }
        if ($more) {
            $out[] = 'More options';
        }
        if ($this->conv['state']['city']) {
            $out[] = 'Different city';
        }
        if ($withVenues && count($out) < 5 && !$this->conv['state']['min_rating'] && $this->conv['shown']) {
            $out[] = 'Only top rated';
        }
        if (!$withVenues && $this->conv['state']['max_price']) {
            $out[] = 'Any budget';
        }
        if (!$withVenues && $this->conv['state']['city']) {
            $out[] = 'Any city';
        }
        if (count($out) < 3) {
            $out[] = 'Start over';
        }
        return array_slice($out, 0, 5);
    }

    private function venueReplies(array $v, string $after): array
    {
        $short = $this->shortName($v['name']);
        $guests = $this->conv['state']['guests'];
        $all = [
            'reviews' => 'What do guests say?',
            'price' => $guests ? 'Total for ' . number_format($guests) . ' guests' : 'How much for 300 guests?',
            'availability' => 'Is it free on ' . ($this->conv['state']['date']
                ? CarbonImmutable::parse($this->conv['state']['date'])->format('j M') : $this->suggestDate()) . '?',
            'amenities' => 'What facilities does it have?',
            'book' => "Book {$short}",
            'compare' => count($this->conv['shown']) >= 2 ? 'Compare the first two' : null,
            'back' => 'Show other venues',
        ];
        unset($all[$after]);
        if ($after === 'superlative' || $after === 'details') {
            unset($all['amenities']);
        } else {
            unset($all['compare']);
        }
        return array_slice(array_values(array_filter($all)), 0, 5);
    }

    // ═══ Text helpers ════════════════════════════════════════════════════════

    private function describe(array $s, bool $withBudget = true, bool $withGuests = true): string
    {
        $kind = $s['event_type'] ? mb_strtolower(self::EVENT_LABELS[$s['event_type']] ?? $this->categoryName($s['event_type'])) . ' venues' : 'venues';
        $types = array_values(array_intersect($s['terms'], self::VENUE_TYPES));
        if ($types) {
            $kind = ($s['event_type'] ? mb_strtolower(self::EVENT_LABELS[$s['event_type']] ?? '') . ' ' : '') . $types[0] . 's';
        }
        $parts = [trim($kind)];
        $places = array_values(array_intersect($s['terms'], $this->facts['places'] ?? []));
        if ($places) {
            $parts[] = 'near ' . $this->placeLabel($places[0]);
        }
        if ($s['city']) {
            $parts[] = 'in ' . $s['city'];
        }
        if ($s['guests'] && $withGuests) {
            $parts[] = 'for ' . number_format($s['guests']) . ' guests';
        }
        if ($s['max_price'] && $withBudget) {
            $parts[] = 'under ' . $this->money($s['max_price']) . '/head';
        }
        if ($s['amenities']) {
            $parts[] = 'with ' . $this->listJoin($s['amenities']);
        }
        return implode(' ', $parts);
    }

    /** "wedding venues in Lahore" → "wedding venue in Lahore" */
    private function singular(string $what): string
    {
        return preg_replace('/\b(' . implode('|', array_merge(['venue'], self::VENUE_TYPES)) . ')s\b/', '$1', $what, 1);
    }

    /** Plain search text for search_logs (no personal details, just the understood request). */
    private function describeQuery(array $s): string
    {
        $parts = [];
        if ($s['event_type']) {
            $parts[] = mb_strtolower(self::EVENT_LABELS[$s['event_type']] ?? $s['event_type']);
        }
        $parts = array_merge($parts, $s['terms']);
        if (!empty($s['city'])) {
            $parts[] = $s['city'];
        }
        if ($s['guests']) {
            $parts[] = $s['guests'] . ' guests';
        }
        if ($s['max_price']) {
            $parts[] = 'under ' . (int) $s['max_price'];
        }
        foreach ($s['intents'] as $i) {
            $parts[] = ['top_rated' => 'top rated', 'cheap' => 'cheap', 'luxury' => 'luxury', 'popular' => 'popular', 'new' => 'new'][$i] ?? '';
        }
        if ($s['amenities']) {
            $parts[] = 'with ' . implode(', ', $s['amenities']);
        }
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts))));
    }

    private function ack(array $s): string
    {
        $bits = [];
        if ($s['event_type']) {
            $bits[] = mb_strtolower(self::EVENT_LABELS[$s['event_type']] ?? $this->categoryName($s['event_type']));
        }
        if ($s['city']) {
            $bits[] = $s['city'];
        }
        if ($s['guests']) {
            $bits[] = number_format($s['guests']) . ' guests';
        }
        if ($s['max_price']) {
            $bits[] = 'under ' . $this->money($s['max_price']) . '/head';
        }
        if ($s['date']) {
            $bits[] = CarbonImmutable::parse($s['date'])->format('j M Y');
        }
        return implode(', ', $bits);
    }

    private function categoryName(string $slug): string
    {
        foreach ($this->facts['categories'] ?? [] as $c) {
            if ($c['slug'] === $slug) {
                return $c['name'];
            }
        }
        return ucwords(str_replace('_', ' ', $slug));
    }

    private function placeLabel(string $word): string
    {
        return mb_strlen($word) <= 3 ? mb_strtoupper($word) : ucwords($word);
    }

    private function place(array $v): string
    {
        return ($v['area'] ?? null) ? $v['area'] . ', ' . $v['city'] : (string) $v['city'];
    }

    private function capacityText(array $v): ?string
    {
        $min = (int) ($v['capacity_min'] ?? 0);
        $max = (int) ($v['capacity_max'] ?? 0);
        if ($min && $max) {
            return number_format($min) . '–' . number_format($max);
        }
        return $max ? 'up to ' . number_format($max) : null;
    }

    private function fitNote(array $v, int $guests, bool $positive = false): ?string
    {
        $min = (int) ($v['capacity_min'] ?? 0);
        $max = (int) ($v['capacity_max'] ?? 0);
        if ($max && $guests > $max) {
            return 'Note: ' . number_format($guests) . ' guests is more than its maximum of ' . number_format($max) . '.';
        }
        if ($min && $guests < $min) {
            return 'Note: it needs at least ' . number_format($min) . ' guests.';
        }
        return $positive ? 'Your ' . number_format($guests) . ' guests fit comfortably.' : null;
    }

    private function ratingSentence(array $v, bool $full = false): string
    {
        if ($v['total_reviews'] <= 0) {
            return $full ? "**{$v['name']}** has no reviews yet." : 'It has no reviews yet.';
        }
        return ($full ? "**{$v['name']}** is" : "It's") . ' rated **' . $this->stars($v) . '** from ' . $this->plural($v['total_reviews'], 'review') . '.';
    }

    private function stars(array $v): string
    {
        return number_format((float) $v['avg_rating'], 1) . '/5';
    }

    private function sentimentWord(array $s): string
    {
        $score = $s['sentiment'] ?? null;
        return match (true) {
            $score === null => 'mixed',
            $score >= 80 => 'very positive',
            $score >= 62 => 'mostly positive',
            $score >= 45 => 'mixed',
            default => 'mostly negative',
        };
    }

    private function countSuffix(array $s): string
    {
        return ' (' . $this->plural((int) $s['analysed_count'], 'review') . ' analysed)';
    }

    /** Aspect names guests praise most (≥ 70 % positive, ≥ 3 mentions). */
    private function praised(array $s, int $n): array
    {
        $rows = array_filter($s['aspects'] ?? [], fn($a) => $a['positive_pct'] >= 70 && $a['mentions'] >= 3);
        usort($rows, fn($a, $b) => [$b['positive_pct'], $b['mentions']] <=> [$a['positive_pct'], $a['mentions']]);
        return array_map(fn($a) => self::ASPECT_NAMES[$a['name']] ?? $a['name'], array_slice(array_values($rows), 0, $n));
    }

    private function concernAspects(array $s, int $n): array
    {
        $rows = array_filter($s['aspects'] ?? [], fn($a) => $a['negative_pct'] >= 35 && $a['mentions'] >= 3);
        usort($rows, fn($a, $b) => [$b['negative_pct'], $b['mentions']] <=> [$a['negative_pct'], $a['mentions']]);
        return array_map(fn($a) => self::ASPECT_NAMES[$a['name']] ?? $a['name'], array_slice(array_values($rows), 0, $n));
    }

    private function quoteFor(string $aspect, array $quotes): ?string
    {
        $words = self::ASPECTS[$aspect] ?? [];
        foreach ($quotes as $q) {
            if (preg_match('/\b(' . implode('|', $words) . ')\b/iu', (string) $q)) {
                return (string) $q;
            }
        }
        return null;
    }

    private function prices(array $venues): array
    {
        $p = array_values(array_filter(array_map(fn($v) => (float) ($v['price_per_head'] ?? 0), $venues)));
        sort($p);
        return $p;
    }

    private function memo(array $v): array
    {
        return array_intersect_key($v, array_flip(['id', 'name', 'city', 'area', 'price_per_head', 'capacity_min', 'capacity_max', 'avg_rating', 'total_reviews']));
    }

    private function hours(array $v): ?string
    {
        $a = $v['operating_start'] ?? null;
        $b = $v['operating_end'] ?? null;
        return $a && $b ? $this->clock($a) . '–' . $this->clock($b) : null;
    }

    private function clock(string $hm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hm) + [1 => 0]);
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return $h12 . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ' ' . $suffix;
    }

    private function shortName(string $name): string
    {
        // Full names keep quick replies like "Book <name>" unambiguous.
        return trim($name);
    }

    /** Next Saturday at least two days away (for example questions). */
    private function suggestDate(): string
    {
        return $this->upcomingDates(1)[0]->format('j M');
    }

    /** @return CarbonImmutable[] upcoming Saturdays/Sundays from two days ahead */
    private function upcomingDates(int $n): array
    {
        $out = [];
        $d = $this->today->addDays(2);
        while (count($out) < $n) {
            if ($d->dayOfWeekIso >= 6) {
                $out[] = $d;
            }
            $d = $d->addDay();
        }
        return $out;
    }

    private function money(float|int|null $n): string
    {
        return 'PKR ' . number_format((float) $n);
    }

    private function plural(int $n, string $word): string
    {
        return number_format($n) . ' ' . $word . ($n === 1 ? '' : 's');
    }

    private function listJoin(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }

    // ═══ Plumbing ════════════════════════════════════════════════════════════

    private function boot(?User $user): void
    {
        $this->user = $user;
        $this->facts = $this->catalog->facts();
        $this->nameFreq = null;
        $this->conv = ConversationStore::blank();
    }

    private function parser(): SearchQueryParser
    {
        return $this->parser ??= new SearchQueryParser($this->facts['vocabulary'] ?? ['cities' => [], 'categories' => [], 'amenities' => []]);
    }

    private function response(?string $id, array $out): array
    {
        $s = $this->conv['state'] ?? ConversationStore::blankState();
        $replies = array_values(array_unique(array_filter($out['quick_replies'] ?? [], fn($r) => is_string($r) && $r !== '')));
        return [
            'conversation_id' => $id,
            'reply' => $out['reply'],
            'quick_replies' => array_slice($replies, 0, 5),
            'venues' => array_values(array_map(fn($v) => array_intersect_key($v, array_flip([
                'id', 'name', 'city', 'area', 'address', 'thumbnail', 'price_per_head', 'capacity_min', 'capacity_max',
                'avg_rating', 'total_reviews', 'is_featured', 'match', 'availability',
            ])) + ['url' => '/venues/' . $v['id'], 'match' => ['score' => null, 'reasons' => []]], $out['venues'] ?? [])),
            'state' => [
                'city' => $s['city'], 'event_type' => $s['event_type'],
                'event_label' => $s['event_type'] ? (self::EVENT_LABELS[$s['event_type']] ?? $this->categoryName($s['event_type'])) : null,
                'guests' => $s['guests'], 'max_price' => $s['max_price'], 'min_price' => $s['min_price'], 'date' => $s['date'],
                'amenities' => $s['amenities'], 'terms' => $s['terms'], 'intents' => $s['intents'], 'sort' => $s['sort'],
                'min_rating' => $s['min_rating'],
            ],
            'action' => $out['action'] ?? null,
            'search_id' => $this->conv['search_id'] ?? null,
        ];
    }
}
