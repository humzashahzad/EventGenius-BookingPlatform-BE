# Ask EventGenius (chat-style venue search)

Visitors describe their event in plain words and refine it by chatting: "wedding hall in Lahore for
300 guests", "cheaper ones?", "what do guests say about the first one?", "is it free on 12 March?",
"book it". It runs on top of the smart search (`docs/smart-search.md`) and review insights
(`docs/review-insights.md`). It is fully deterministic, with no language model, and every number in a
reply comes from the database.

## HTTP API (public, optional `Authorization: Bearer` for personalised ranking)

`GET /api/assistant/start` → `{success, data: {conversation_id: null, reply, quick_replies, venues: [], state, action: null}}`

`POST /api/assistant/message` body `{conversation_id?: uuid, message: string (1–500)}` →
```json
{ "success": true, "data": {
  "conversation_id": "uuid",
  "reply": "I found **4** wedding venues in Lahore for 300 guests:\nDo you have a budget per head?",
  "quick_replies": ["Under 3,500 per head", "Under 4,000 per head", "Any budget"],
  "venues": [ { "id": 86, "name": "Shalimar Grand Ballroom", "city": "Lahore", "area": "Gulberg III", "address": "…",
                "thumbnail": "demo/01.jpg", "price_per_head": 4500, "capacity_min": 300, "capacity_max": 1000,
                "avg_rating": 4.23, "total_reviews": 78, "is_featured": false, "url": "/venues/86",
                "match": { "score": 81.2, "reasons": ["Fits 300 guests", "Guests love the decor"] },
                "availability": { "date": "2027-03-12", "bookings": 0 } } ],
  "state": { "city": "Lahore", "event_type": "weddings", "event_label": "Wedding", "guests": 300, "max_price": null,
             "min_price": null, "date": null, "amenities": [], "terms": [], "intents": [], "sort": "best_match", "min_rating": null },
  "action": null,
  "search_id": "uuid" } }
```
* `reply` is short plain text; `**bold**` and `\n` line breaks are the only markup.
* `action` is `{type: "open_venue", url: "/venues/{id}"}` / `"/venues/{id}/book?guests=N"` or
  `{type: "open_search", url: "/venues?q=…&city=…"}` (public main-domain paths).
* `availability` is only present when the conversation has a date.
* `search_id` lets the client report clicks via `POST /api/venues/{id}/click`.
* 422 for a missing/too long message, 429 (`{success:false, message, retry_after}`) when throttled.

## Limits (`config/assistant.php`, `.env`)

| Key | Default | |
|---|---|---|
| `ASSISTANT_PER_MINUTE` | 20 | messages per IP per minute |
| `ASSISTANT_PER_DAY` | 400 | messages per IP per day |
| `ASSISTANT_GLOBAL_PER_MINUTE` | 600 | all visitors together per minute |

Both endpoints use the `assistant` rate limiter (`AppServiceProvider`). Conversations live in the cache
(`assistant:conv:{uuid}`, 2 h TTL, max 30 turns, then the visitor is asked to start over). Only the
search state, shown venue ids and counters are stored, never the typed text. Searches are logged to
`search_logs` through `VenueSearch` with a generated description (for example `wedding Lahore 300 guests`)
instead of the raw message, so analytics keep working without storing chat text.

## Code (`app/Services/Assistant`)

* `AssistantEngine`: the dialogue (understanding, slot filling, refinements, questions, replies).
* `Catalog` (interface) / `DbCatalog`: search via `VenueSearch`, venue details, `review_summary`,
  bookings on a date, cached facts (cities, categories, price percentiles, venue names, area words; 10 min).
* `ConversationStore`: cache-backed memory. `DateParser`: dates in English, Roman Urdu and Urdu.
* `VenueSearch` gained additive inputs used by the assistant: `max_capacity`, `min_rating`,
  `amenities[]` (all required) and `opts.understood` (pre-parsed query).

### How a message is handled

1. Reset / small talk ("start over", "hi", "help", "thanks") when nothing else is said.
2. Venue references: ordinals ("the first one", "#2", "dusra", "last one"), "it/this" (the venue being
   discussed), distinctive name words ("Shalimar").
3. Booking ("book it", "book the second one") → `open_venue` action with the guest count when it fits.
4. Questions about a venue: reviews (overall or one aspect: food, service, decor, value, cleanliness, parking,
   AC, sound, location, management, power backup), price for N guests with 5 % tax and paid extras,
   capacity, facilities (included vs paid), location, rating, availability on a date, compare two,
   "which has the best food / is cheapest / biggest / highest rated" across the shown list.
5. Otherwise a search turn: the message is parsed with `SearchQueryParser` and merged into the state
   (new values override), plus clears ("any city", "remove budget", "without parking"), relative
   refinements ("cheaper", "more premium", "bigger", "smaller", "only top rated", "more options"),
   sort ("cheapest first", "highest rated first"). Before the first results it asks one question at a time
   (occasion, then city) only while the request is vague; with results on screen it asks at most one
   follow-up (guests, then budget) next to the cards. If nothing matches it drops the least important
   filter and says which one.

## Plugging in a language model later

The endpoint contract does not need to change. A model could (a) rewrite free text into the same
state/intent structure before `AssistantEngine` runs, keeping the deterministic search and data answers,
or (b) only rephrase `reply` from the structured result (venues, numbers, state) so no number is invented.
Implement it behind the same controller, keep the rate limits, and fall back to the current engine when the
model is slow or unavailable.
