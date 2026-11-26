<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\ConversationStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\FakeAssistantCatalog;

if (!class_exists(FakeAssistantCatalog::class)) {
    require_once __DIR__ . '/Support/FakeAssistantCatalog.php';
}

/** Multi-turn conversations against in-memory venues (today = Wed 25 Nov 2026). */
class AssistantEngineTest extends TestCase
{
    private FakeAssistantCatalog $catalog;
    private ConversationStore $store;
    private ?string $cid = null;
    private array $last = [];

    protected function setUp(): void
    {
        $this->catalog = new FakeAssistantCatalog();
        $this->store = new ConversationStore(new Repository(new ArrayStore()));
        $this->cid = null;
    }

    /** Send messages in one conversation (a fresh engine per message, like HTTP); returns the last response. */
    private function say(string ...$messages): array
    {
        foreach ($messages as $m) {
            $engine = new AssistantEngine($this->catalog, $this->store, CarbonImmutable::create(2026, 11, 25));
            $this->last = $engine->handle($this->cid, $m);
            $this->cid = $this->last['conversation_id'];
        }
        return $this->last;
    }

    private function names(array $r): array
    {
        return array_column($r['venues'], 'name');
    }

    private function assertShape(array $r): void
    {
        foreach (['conversation_id', 'reply', 'quick_replies', 'venues', 'state', 'action'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertNotSame('', trim($r['reply']));
        $this->assertLessThanOrEqual(5, count($r['quick_replies']));
        foreach (['city', 'event_type', 'guests', 'max_price', 'min_price', 'date', 'amenities', 'terms', 'sort'] as $k) {
            $this->assertArrayHasKey($k, $r['state']);
        }
    }

    // ── Greeting, help, gibberish ───────────────────────────────────────────

    public function test_start_greets_with_starters_from_real_cities(): void
    {
        $r = (new AssistantEngine($this->catalog, $this->store))->start();
        $this->assertStringContainsString('Hi', $r['reply']);
        $this->assertContains('Wedding hall in Lahore for 300 guests', $r['quick_replies']);
        $this->assertGreaterThanOrEqual(2, count($r['quick_replies']));
    }

    public function test_hello_gets_a_greeting_not_a_search(): void
    {
        $r = $this->say('hello');
        $this->assertShape($r);
        $this->assertStringContainsString('What are you planning', $r['reply']);
        $this->assertSame([], $r['venues']);
        $this->assertSame([], $this->catalog->searches);
    }

    public function test_gibberish_falls_back_politely(): void
    {
        $r = $this->say('asdfgh qwerty zxcv');
        $this->assertStringContainsString("didn't catch", $r['reply']);
        $this->assertSame([], $r['venues']);
        $this->assertNotEmpty($r['quick_replies']);
    }

    public function test_help_explains_what_it_can_do(): void
    {
        $r = $this->say('what can you do?');
        $this->assertStringContainsString('cheaper ones', $r['reply']);
        $this->assertStringContainsString('book it', $r['reply']);
    }

    // ── Slot filling ────────────────────────────────────────────────────────

    public function test_vague_request_asks_occasion_then_city_then_shows_results(): void
    {
        $r = $this->say('I need a venue');
        $this->assertStringContainsString("What's the occasion?", $r['reply']);
        $this->assertSame('Wedding', $r['quick_replies'][0]);
        $this->assertSame([], $this->catalog->searches);

        $r = $this->say('Wedding');
        $this->assertSame('weddings', $r['state']['event_type']);
        $this->assertStringContainsString('Which city', $r['reply']);
        $this->assertContains('Lahore', $r['quick_replies']);

        $r = $this->say('Lahore');
        $this->assertSame('Lahore', $r['state']['city']);
        $this->assertCount(4, $r['venues']);
        $this->assertStringContainsString('**4** wedding venues in Lahore', $r['reply']);
        $this->assertStringContainsString('How many guests', $r['reply']);
        $this->assertContains('300 guests', $r['quick_replies']);

        // A bare number answers the guest question, even a large one.
        $r = $this->say('1000');
        $this->assertSame(1000, $r['state']['guests']);
        $this->assertNull($r['state']['max_price']);
        $this->assertSame(['Shalimar Grand Ballroom', 'Golden Pearl Ballroom'], $this->names($r));
    }

    public function test_specific_request_searches_immediately_and_asks_budget_once(): void
    {
        $r = $this->say('I need a wedding hall in Lahore for about 300 people');
        $this->assertShape($r);
        $this->assertSame(['Lahore', 'weddings', 300], [$r['state']['city'], $r['state']['event_type'], $r['state']['guests']]);
        $this->assertNotEmpty($r['venues']);
        $this->assertStringContainsString('budget per head', $r['reply']);
        $this->assertContains('Any budget', $r['quick_replies']);
        $this->assertTrue($this->catalog->searches[0]['opts']['log'], 'assistant searches are logged like normal searches');

        $r = $this->say('Any budget');
        $this->assertStringNotContainsString('budget per head', $r['reply']);
        $this->assertContains('Cheaper ones', $r['quick_replies']);
    }

    public function test_skipping_the_occasion_moves_on_to_city(): void
    {
        $this->say('show me venues');
        $r = $this->say('Something else');
        $this->assertStringContainsString('Which city', $r['reply']);
        $r = $this->say('Karachi');
        $this->assertSame('Karachi', $r['state']['city']);
        $this->assertCount(3, $r['venues']);
    }

    public function test_unknown_city_is_explained_with_real_city_options(): void
    {
        $this->say('conference');
        $r = $this->say('Gwadar');
        $this->assertStringContainsString("don't have venues in **Gwadar**", $r['reply']);
        $this->assertContains('Islamabad', $r['quick_replies']);
        $r = $this->say('Islamabad');
        $this->assertSame(['Capital Conference Centre'], $this->names($r));
    }

    // ── Refinements ─────────────────────────────────────────────────────────

    public function test_budget_then_cheaper_lowers_the_ceiling(): void
    {
        $this->say('wedding in Lahore for 300 guests');
        $r = $this->say('under 4,000 per head');
        $this->assertSame(4000.0, $r['state']['max_price']);
        // 1.15 × budget tolerance keeps Shalimar (4,500) visible; Royal Crystal (5,200) drops out.
        $this->assertSame(['Shalimar Grand Ballroom', 'Mughal Garden Marquee', 'Golden Pearl Ballroom'], $this->names($r));

        $r = $this->say('cheaper ones?');
        $this->assertLessThan(4000, $r['state']['max_price']);
        foreach ($r['venues'] as $v) {
            $this->assertLessThan(3800, $v['price_per_head']);
        }
        $this->assertStringContainsString('more affordable', $r['reply']);
        $this->assertContains('cheap', $r['state']['intents']);
    }

    public function test_cheaper_when_already_cheapest_keeps_the_list(): void
    {
        $this->say('wedding in Lahore under 2700');
        $before = $this->last['state'];
        $r = $this->say('cheaper');
        $this->assertStringContainsString('already the most affordable', $r['reply']);
        $this->assertSame($before['max_price'], $r['state']['max_price']);
    }

    public function test_bigger_and_smaller(): void
    {
        $this->say('wedding venues in Lahore', 'not sure');
        $r = $this->say('bigger');
        foreach ($r['venues'] as $v) {
            $this->assertGreaterThan(700, $v['capacity_max']);
        }
        $this->say('start over', 'wedding venues in Lahore', 'skip');
        $r = $this->say('smaller ones');
        $this->assertNotEmpty($r['venues']);
        foreach ($r['venues'] as $v) {
            $this->assertLessThan(1000, $v['capacity_max']);
        }
    }

    public function test_more_options_pages_and_stops_at_the_end(): void
    {
        $this->say('venues in Lahore for 100 guests', 'any');
        $first = $this->names($this->last);
        $this->assertCount(4, $first);
        $r = $this->say('more options');
        $this->assertSame(['Garden Town Business Hub'], $this->names($r));
        $this->assertStringContainsString('5–5 of 5', $r['reply']);
        $r = $this->say('show more');
        $this->assertStringContainsString("That's everything", $r['reply']);
    }

    public function test_only_top_rated_and_amenity_filter(): void
    {
        $this->say('wedding venues in Lahore', 'skip');
        $r = $this->say('only top rated');
        $this->assertSame(4.0, $r['state']['min_rating']);
        $this->assertNotContains('Golden Pearl Ballroom', $this->names($r));

        $r = $this->say('with parking');
        $this->assertSame(['Valet Parking'], $r['state']['amenities']);
        $this->assertSame(['Royal Crystal Hall', 'Shalimar Grand Ballroom'], $this->names($r));

        $r = $this->say('without parking');
        $this->assertSame([], $r['state']['amenities']);
    }

    public function test_different_city_any_city_and_remove_budget(): void
    {
        $this->say('wedding in Lahore under 3000 for 200 guests');
        $r = $this->say('different city');
        $this->assertNull($r['state']['city']);
        $this->assertNotContains('Lahore', $r['quick_replies']);
        $r = $this->say('Karachi');
        $this->assertSame('Karachi', $r['state']['city']);
        $this->assertSame(3000.0, $r['state']['max_price'], 'other filters survive a city change');

        $r = $this->say('remove budget');
        $this->assertNull($r['state']['max_price']);
        $r = $this->say('any city');
        $this->assertNull($r['state']['city']);
        $this->assertGreaterThan(3, count($r['venues']));
    }

    public function test_new_values_override_old_ones(): void
    {
        $this->say('wedding in Lahore for 300 guests under 5000');
        $r = $this->say('for 600 guests under 3k');
        $this->assertSame(600, $r['state']['guests']);
        $this->assertSame(3000.0, $r['state']['max_price']);
        $r = $this->say('what about Karachi?');
        $this->assertSame('Karachi', $r['state']['city']);
        $this->assertSame(600, $r['state']['guests']);
    }

    public function test_sort_phrase_is_not_an_ordinal_reference(): void
    {
        $this->say('wedding venues in Lahore', 'skip');
        $r = $this->say('cheapest first');
        $this->assertSame('price_asc', $r['state']['sort']);
        $this->assertSame('Golden Pearl Ballroom', $r['venues'][0]['name']);
        $this->assertNull($r['action']);
        $this->assertStringContainsString('cheapest first', $r['reply']);
    }

    public function test_start_over_clears_everything(): void
    {
        $this->say('wedding in Lahore for 300 guests under 4000');
        $r = $this->say('start over');
        $this->assertStringContainsString('Fresh start', $r['reply']);
        $this->assertNull($r['state']['city']);
        $this->assertNull($r['state']['guests']);
        $this->assertNull($r['state']['max_price']);
        $r = $this->say('the first one');
        $this->assertSame([], $r['venues']);
    }

    public function test_nothing_matches_then_constraints_are_relaxed_and_explained(): void
    {
        $r = $this->say('wedding venue in Lahore for 5000 guests');
        $this->assertStringContainsString("set aside the guest count", $r['reply']);
        $this->assertNull($r['state']['guests']);
        $this->assertNotEmpty($r['venues']);
        $this->assertStringNotContainsString('How many guests', $r['reply']);
    }

    // ── Questions about results ─────────────────────────────────────────────

    public function test_what_guests_say_about_the_first_one(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $this->assertSame('Royal Crystal Hall', $this->last['venues'][0]['name']);
        $r = $this->say('what do guests say about the first one?');
        $this->assertStringContainsString('**Royal Crystal Hall**', $r['reply']);
        $this->assertStringContainsString('very positive', $r['reply']);
        $this->assertStringContainsString('decor', $r['reply']);
        $this->assertStringContainsString('Beautiful decor', $r['reply']);

        $r = $this->say('#2');
        $this->assertSame(['Shalimar Grand Ballroom'], $this->names($r));
        $this->assertStringContainsString('300–1,000 guests', $r['reply']);
        $this->assertStringContainsString('PKR 4,500 per head', $r['reply']);
    }

    public function test_venue_without_reviews_is_said_honestly(): void
    {
        $this->say('wedding in Lahore under 2700');
        $r = $this->say('what do guests say about it?');
        $this->assertStringContainsString("doesn't have enough written reviews", $r['reply']);
    }

    public function test_aspect_question_uses_review_insight_numbers(): void
    {
        $this->say('tell me about Shalimar');
        $r = $this->say('how is the food?');
        $this->assertStringContainsString('**100% positive** from 19 guests', $r['reply']);
        $this->assertStringContainsString('food was delicious', $r['reply']);
        $r = $this->say('and the AC?');
        $this->assertStringContainsString('53% negative', $r['reply']);
    }

    public function test_which_has_the_best_food_compares_shown_venues(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $r = $this->say('which has the best food?');
        $this->assertSame(['Shalimar Grand Ballroom'], $this->names($r));
        $this->assertStringContainsString('100% of the 19 guests', $r['reply']);
        $r = $this->say('which one is the cheapest?');
        $this->assertSame(['Golden Pearl Ballroom'], $this->names($r));
        $this->assertStringContainsString('PKR 2,600 per head', $r['reply']);
    }

    public function test_which_hall_has_best_food_on_a_fresh_chat_searches_first(): void
    {
        $r = $this->say('which venue in Lahore has the best food?');
        $this->assertNotEmpty($r['venues']);
        $this->assertStringContainsString('**Shalimar Grand Ballroom** has the best feedback on the food', $r['reply']);
    }

    public function test_price_for_n_guests_includes_tax_and_paid_extras(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $r = $this->say('how much would the second one cost?');
        // 300 × 4,500 = 1,350,000; 5% = 67,500; total 1,417,500
        $this->assertStringContainsString('PKR 1,350,000', $r['reply']);
        $this->assertStringContainsString('(PKR 67,500)', $r['reply']);
        $this->assertStringContainsString('**PKR 1,417,500**', $r['reply']);
        $this->assertStringContainsString('Photography (PKR 45,000)', $r['reply']);

        $r = $this->say('what about for 250 guests?');
        $this->assertSame(250, $r['state']['guests']);
        $this->assertStringContainsString('**PKR 1,181,250**', $r['reply']); // 250 × 4500 × 1.05
        $this->assertStringContainsString('at least 300 guests', $r['reply']);
    }

    public function test_capacity_amenities_location_and_rating_questions(): void
    {
        $this->say('wedding in Lahore for 1200 guests');
        // nothing fits 1,200 in Lahore → guest count relaxed
        $this->say('start over', 'wedding in Lahore', 'skip');
        $this->say('tell me about Royal Crystal Hall');
        $r = $this->say('how many guests can it hold?');
        $this->assertStringContainsString('**100–400 guests**', $r['reply']);

        $this->say('tell me about Shalimar Grand Ballroom');
        $r = $this->say('what facilities does it have?');
        $this->assertStringContainsString('Included: Air Conditioning, Valet Parking', $r['reply']);
        $this->assertStringContainsString('Paid extras: Photography (PKR 45,000)', $r['reply']);
        $r = $this->say('does it have parking?');
        $this->assertStringContainsString('has **Valet Parking** (included)', $r['reply']);
        $r = $this->say('does it have a generator?');
        $this->assertStringContainsString("doesn't list **Generator Backup**", $r['reply']);
        $r = $this->say('where is it?');
        $this->assertStringContainsString('Gulberg III, Lahore', $r['reply']);
        $r = $this->say('what is its rating?');
        $this->assertStringContainsString('**4.2/5** from 78 reviews', $r['reply']);
    }

    public function test_availability_with_bookings_and_free_dates(): void
    {
        $this->say('tell me about Shalimar');
        $r = $this->say('is it free on 12 March?');
        $this->assertStringContainsString('Friday 12 March 2027', $r['reply']);
        $this->assertStringContainsString('1 booking', $r['reply']);
        $this->assertStringContainsString('6:00 PM–10:00 PM', $r['reply']);
        $this->assertSame('2027-03-12', $r['state']['date']);

        $r = $this->say('is it available next saturday?');
        $this->assertStringContainsString('no bookings on **Saturday 28 November 2026**', $r['reply']);
    }

    public function test_availability_needs_a_future_date(): void
    {
        $this->say('tell me about Shalimar');
        $r = $this->say('is it free today?');
        $this->assertStringContainsString("a day's notice", $r['reply']);
        $r = $this->say('is it free?');
        $this->assertStringContainsString('Which date', $r['reply']);
        $r = $this->say('5 December');
        $this->assertStringContainsString('Saturday 5 December 2026', $r['reply']);
    }

    public function test_question_without_a_clear_venue_asks_which_one(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $r = $this->say('is it free on 12 March?');
        $this->assertStringContainsString('Which venue', $r['reply']);
        $this->assertContains('Shalimar Grand Ballroom', $r['quick_replies']);
        $r = $this->say('Shalimar Grand Ballroom');
        $this->assertStringContainsString('**Shalimar Grand Ballroom** already has 1 booking', $r['reply']);
    }

    public function test_date_in_search_annotates_cards(): void
    {
        $r = $this->say('wedding venues in Lahore free on 12 March');
        $byName = array_column($r['venues'], null, 'name');
        $this->assertSame('Partly booked on 12 Mar', $byName['Shalimar Grand Ballroom']['match']['reasons'][0]);
        $this->assertSame('No bookings yet on 12 Mar', $byName['Royal Crystal Hall']['match']['reasons'][0]);
    }

    public function test_compare_two_venues(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $r = $this->say('compare the first two');
        $this->assertSame(['Royal Crystal Hall', 'Shalimar Grand Ballroom'], $this->names($r));
        $this->assertStringContainsString('PKR 5,200/head vs PKR 4,500/head', $r['reply']);
        $this->assertStringContainsString('**Shalimar Grand Ballroom** is cheaper', $r['reply']);
        $this->assertStringContainsString('**Royal Crystal Hall** is rated higher', $r['reply']);
    }

    public function test_book_it_opens_the_booking_page_with_guests(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget', 'tell me about the second one');
        $r = $this->say('book it');
        $this->assertSame(['type' => 'open_venue', 'url' => '/venues/1/book?guests=300'], $r['action']);

        $this->say('start over', 'tell me about Daman Terrace');
        $r = $this->say('book it');
        $this->assertSame('/venues/9/book', $r['action']['url']);
    }

    public function test_yes_after_availability_books(): void
    {
        $this->say('tell me about Royal Crystal Hall', 'is it free on 6 December?');
        $r = $this->say('yes');
        $this->assertSame('/venues/3/book', $r['action']['url']);
    }

    public function test_out_of_range_ordinal(): void
    {
        $this->say('birthday party in Islamabad');
        $r = $this->say('what about the third one?');
        $this->assertStringContainsString("only shown 1 venue", $r['reply']);
    }

    public function test_book_a_hall_is_a_search_not_a_booking(): void
    {
        $r = $this->say('I want to book a wedding hall in Karachi');
        $this->assertNull($r['action']);
        $this->assertSame('Karachi', $r['state']['city']);
        $this->assertNotEmpty($r['venues']);
    }

    // ── Languages ───────────────────────────────────────────────────────────

    public function test_roman_urdu_request_and_follow_ups(): void
    {
        $r = $this->say('shadi hall chahiye lahore mein 300 mehman');
        $this->assertSame(['Lahore', 'weddings', 300], [$r['state']['city'], $r['state']['event_type'], $r['state']['guests']]);
        $r = $this->say('3500 tak');
        $this->assertSame(3500.0, $r['state']['max_price']);
        $r = $this->say('pehla wala kaisa hai?');
        $this->assertStringContainsString('Guests are', $r['reply']);
    }

    public function test_urdu_script_request(): void
    {
        $r = $this->say('مجھے کراچی میں شادی ہال چاہیے 400 مہمان');
        $this->assertSame('Karachi', $r['state']['city']);
        $this->assertSame('weddings', $r['state']['event_type']);
        $this->assertSame(400, $r['state']['guests']);
    }

    // ── Memory and limits ───────────────────────────────────────────────────

    public function test_conversation_survives_new_engine_instances_and_unknown_ids_start_fresh(): void
    {
        $this->say('wedding in Karachi');
        $id = $this->cid;
        $r = $this->say('for 600 guests');
        $this->assertSame($id, $r['conversation_id']);
        $this->assertSame('Karachi', $r['state']['city']);

        $this->cid = '8a3c1f52-0000-4000-8000-000000000000';
        $r = $this->say('for 600 guests');
        $this->assertNotSame($id, $r['conversation_id']);
        $this->assertNull($r['state']['city']);
    }

    public function test_questions_do_not_run_searches(): void
    {
        $this->say('wedding in Lahore for 300 guests', 'any budget');
        $n = count($this->catalog->searches);
        $this->say('what do guests say about the first one?', 'how much is it?', 'is it free on 12 March?');
        $this->assertCount($n, $this->catalog->searches);
    }

    public function test_turn_limit_asks_to_start_over(): void
    {
        for ($i = 0; $i < ConversationStore::MAX_TURNS; $i++) {
            $this->say('hi');
        }
        $r = $this->say('wedding in Lahore');
        $this->assertSame(['Start over'], $r['quick_replies']);
        $this->assertStringContainsString('Start over', $r['reply']);
    }

    public function test_every_reply_has_two_to_five_quick_replies(): void
    {
        $turns = ['hi', 'wedding', 'Lahore', '300', 'Under 4,000 per head', 'cheaper', 'what do guests say about the first one?',
            'is it free on 12 March?', 'compare the first two', 'thanks', 'zzzz', 'more options', 'book it'];
        foreach ($turns as $t) {
            $r = $this->say($t);
            $this->assertShape($r);
            $this->assertGreaterThanOrEqual(1, count($r['quick_replies']), $t);
            if ($r['action'] === null) {
                $this->assertGreaterThanOrEqual(2, count($r['quick_replies']), "too few quick replies after: {$t}");
            }
        }
    }
}
