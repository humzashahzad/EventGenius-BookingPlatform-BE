# Smart venue search & ranking

How venue search understands free-text queries, ranks results and exposes its controls.
Backend: Laravel 12 (`app/Services/Search`); frontend: Vue 3 (`src/components/search`, admin page `/support/search`).

## Database

- `app_settings(key PK, value JSON, timestamps)`
- `search_logs(id, search_id uuid, user_id?, query?, understood JSON?, filters JSON?, sort?, result_count, venue_ids JSON?, page, created_at)`
- `search_clicks(id, search_id?, venue_id, position?, user_id?, created_at)`
- `venue_search_stats(venue_id PK, impressions, clicks, timestamps)`
- `venues.search_boost smallint default 0` — admin manual control, −100 (bury) … +100 (boost)

Existing data: `venues` (name, description, address, city, state, latitude/longitude, capacity_min/max,
pricing_type, price_per_head/hour/day/event, event_types JSON of category slugs, is_featured,
avg_rating, total_reviews, total_bookings, status, created_at), `stores(name,status,user_id)`,
`bookings(venue_id, client_id, status pending|confirmed|completed|cancelled|rejected, event_date, created_at, …)`,
`reviews`, `categories(slug,name,is_active,deleted_at)`, `venue_amenities(venue_id,name,…)`.
Public venues = `venues.status='active'` and store `status='approved'`.

## Backend classes (namespace `App\Services\Search`)

### `SearchQueryParser`
```php
public function __construct(?array $vocabulary = null) // null => load from DB (cached 10 min)
public function parse(?string $q): array
public static function vocabulary(): array // ['cities'=>string[], 'categories'=>[['slug'=>..,'name'=>..]], 'amenities'=>string[]]
```
`parse()` returns (all keys always present):
```php
[
  'raw'        => string,          // trimmed input ('' if none)
  'terms'      => string[],        // leftover meaningful keywords (lowercase), e.g. ['rooftop','garden']
  'city'       => ?string,         // canonical city exactly as stored in venues.city
  'event_type' => ?string,         // category slug, e.g. 'weddings'
  'guests'     => ?int,
  'max_price'  => ?float,          // per-head budget ceiling (PKR)
  'min_price'  => ?float,
  'intents'    => string[],        // subset of: top_rated, popular, cheap, luxury, near_me, new
  'amenities'  => string[],        // amenity names matched from vocabulary
  'chips'      => [ ['type'=>'city|event_type|guests|max_price|min_price|intent|term|amenity',
                     'label'=>'Lahore', 'param'=>'city', 'value'=>'Lahore'], ... ],
]
```
Must understand English plus common Pakistani/Urdu terms: shadi/shaadi/wedding/marriage/barat/baraat/walima/valima/nikah/nikkah/mehndi/mehendi → weddings;
mangni/engagement/ring ceremony → engagement_parties; birthday/bday/salgirah → birthday_parties; conference; seminar/workshop/training; corporate/office/meeting/company;
concert/music/gig/qawwali; graduation/convocation; baby shower/aqiqa; bridal shower; anniversary; festival/mela; religious/milad/majlis/iftar/dars → religious_events.
Only map to slugs present in the active categories vocabulary.
City aliases: lhr→Lahore, khi→Karachi, isb/isl→Islamabad, pindi/rwp→Rawalpindi, fsd/lyallpur→Faisalabad, mul→Multan, pesh/psh→Peshawar (only if in vocabulary);
fuzzy-match typos with Levenshtein ≤ 2 for words ≥ 5 chars.
Guests: "300 guests/people/persons/pax/log/afrad", "for 300". Budget: "under/below/less than/max/upto/within/budget 3000", "3k", "3,000", "Rs 3000", "pkr 3000 per head";
"between 2000 and 4000" → min/max.
Intents: best/top/top rated/highest rated → top_rated; popular/trending → popular; cheap/affordable/budget/sasta → cheap; luxury/premium/5 star → luxury;
near me/nearby/close to me → near_me; new/newest/latest → new.
Stopwords removed from terms (in, for, a, the, venue(s), place, with, near, me, under, per, head, guests …). Keep venue-type words
(hall, marquee, lawn, banquet, rooftop, garden, ballroom …) as terms.

### `SearchSettings`
```php
public static function defaults(): array
public static function presets(): array   // ['balanced'=>[weights], 'quality_first'=>…, 'popularity'=>…, 'budget'=>…, 'nearby'=>…]
public static function get(): array       // stored ('search.ranking' row in app_settings) merged over defaults, cached
public static function save(array $partial): array // validates/clamps, stores, clears cache, returns full settings
```
Settings shape:
```php
[
  'weights' => ['relevance'=>8,'quality'=>7,'popularity'=>5,'reliability'=>3,'price_fit'=>5,'capacity_fit'=>5,
                'distance'=>5,'engagement'=>3,'freshness'=>2,'personal'=>4],   // ints 0..10
  'featured_boost' => 5,          // 0..20 score points added to featured venues
  'personalization' => true,      // use logged-in customer's history
  'learning' => true,             // use click-through (engagement) signal
  'exploration' => true,          // freshness boost for new venues
  'rating_prior_reviews' => 5,    // Bayesian prior strength
  'popularity_window_days' => 180,
  'distance_half_km' => 15,       // distance at which distance score = 0.5
]
```

### `VenueRanker`
```php
public function rank(\Illuminate\Support\Collection $venues, array $parsed, array $context, ?array $settings = null): \Illuminate\Support\Collection
```
`$context = ['user'=>?User, 'lat'=>?float, 'lng'=>?float, 'guests'=>?int, 'max_price'=>?float, 'min_price'=>?float, 'city'=>?string, 'event_type'=>?string]`.
Returns items sorted by score desc: `['venue'=>Venue, 'score'=>float 0..100, 'signals'=>[name=>0..1], 'contributions'=>[name=>points], 'reasons'=>string[] (max 3)]`.
Signals (each 0..1; a signal with no information for this request is left out of the weighted average, not counted as 0):
relevance (terms/amenities vs name, store name, address/city, event types, amenities, description), quality (Bayesian rating),
popularity (confirmed+completed bookings within window, log-scaled), reliability (owner acceptance, low cancellation, smoothed),
price_fit (vs max/min price, else mild cheaper-is-better by percentile; luxury intent flips it), capacity_fit (guests in range, penalise far oversized),
distance (lat/lng → exponential decay using distance_half_km; else city match), engagement (CTR from venue_search_stats, smoothed; only if learning on),
freshness (venues < 45 days old; only if exploration on), personal (logged-in client: affinity to cities/event types/price band of past bookings; only if personalization on).
Intents adjust weights: top_rated → quality ×2, popular → popularity ×2, cheap → price_fit ×2, near_me → distance ×2, new → freshness ×3.
score = 100 × Σ(w·s)/Σ(w) + featured_boost (if featured) + search_boost × 0.2, clamped 0..100.
Reasons are short and human, e.g. "Top rated in Lahore", "Fits 300 guests", "Within your budget", "2.4 km away",
"Popular — 42 bookings recently", "Matches “rooftop”", "New on EventGenius", "Like venues you booked before".

### `VenueSearch`
```php
public function run(array $params, ?User $user, array $opts = []): array
// $opts: ['log'=>bool (default true), 'explain'=>bool (default false), 'settings'=>?array (override, for admin preview)]
// returns ['paginator'=>LengthAwarePaginator, 'understood'=>array (parser output), 'search_id'=>?string, 'sort'=>string]
public static function resolveUser(\Illuminate\Http\Request $request): ?User // optional Bearer JWT via JwtService::validate; never throws
```
Params: `q` (natural language; legacy `search` is an alias), `city`, `event_type`, `guests`, `max_price`, `min_price`, `min_capacity`, `pricing_type`, `featured`,
`lat`, `lng`, `search_id`, `sort_by` (`best_match` default when absent | created_at | avg_rating | price_per_head | price_per_hour | price_per_day | price_per_event), `sort_dir`, `page`, `per_page` (1..100, default 12).
Explicit params override values parsed from `q`. Hard filters: public venues, city, event_type, guests (capacity_max ≥ guests),
max_price (price_per_head ≤ max_price × 1.15 so near-budget stays visible), min_capacity, pricing_type, featured.
Text terms: if any candidate has relevance > 0, drop candidates with relevance = 0; otherwise keep all (never return zero just because of a vague word).
For non-best_match sorts keep the current ordering behaviour of `Client\VenueController@index` (prices nulls last, id tiebreak).
Each paginator item = the venue array (same fields as today incl. `store`, primary `images`) plus `"match": {"score": 87.4, "reasons": [...]}`;
with `explain` also `signals` and `contributions` inside `match`.
Logging (opts.log): insert a `search_logs` row (reuse client-passed `search_id` for later pages, else new uuid) and increment
`venue_search_stats.impressions` for venues on the returned page.

## HTTP API

### `GET /api/venues` (modify `Client\VenueController@index` to delegate to VenueSearch)
Keeps today's response shape and adds a top-level `search` key:
```json
{ "success": true,
  "data": { "current_page":1, "data":[ {"…venue fields":"…", "match":{"score":87.4,"reasons":["Top rated in Lahore","Fits 300 guests"]}} ], "last_page":2, "per_page":12, "total":14 },
  "search": { "search_id":"uuid", "sort":"best_match", "understood": { "…parser output incl. chips": "…" } } }
```
Existing callers (home page `featured=1`, `sort_by=avg_rating`, `per_page=100` city lists) must keep working.

### `POST /api/venues/{id}/click` (`App\Http\Controllers\Api\SearchClickController@store`)
Body `{search_id?: uuid, position?: int}`; optional auth (resolveUser). Inserts `search_clicks`, upserts `venue_search_stats.clicks`. Returns `{success:true}`; 404 for unknown/non-public venue.

### `GET /api/search/suggest?q=` (`App\Http\Controllers\Api\SearchSuggestController@index`)
`{success:true, data:{ understood: parser output, suggestions:[{type:'venue'|'city'|'category'|'query', label, value, venue_id?, city?}] (max 8) }}`.
q shorter than 2 chars → example queries + top categories + cities.

### Admin (`jwt` + `role:admin`) (`App\Http\Controllers\Api\Admin\SearchRankingController`)
- `GET /api/admin/search/settings` → `{success, data:{settings, defaults, presets}}`
- `PUT /api/admin/search/settings` body partial settings, or `{preset:"balanced"}` → `{success, data:{settings}}`
- `POST /api/admin/search/preview` body `{q?, city?, event_type?, guests?, max_price?, lat?, lng?, as_user_id?, settings?}` → VenueSearch with `log:false, explain:true`
  (settings override lets admins try unsaved weights) → `{success, data:{understood, results:[{venue:{id,name,city,thumbnail,price_per_head,avg_rating,total_reviews,is_featured,search_boost}, score, signals, contributions, reasons}]}}` (top 20)
- `GET /api/admin/search/analytics?days=30` → `{success, data:{totals:{searches, unique_queries, clicks, ctr, zero_result_rate}, per_day:[{date,searches,clicks}], top_queries:[{query,count,avg_results,ctr}], zero_result_queries:[{query,count,last_at}], top_clicked_venues:[{venue_id,name,clicks,impressions,ctr}], understood_breakdown:{city,event_type,guests,budget} (percent of searches)}}`
- `GET /api/admin/search/venues` → `{success, data:[{id,name,city,store_name,status,is_featured,search_boost,avg_rating,total_reviews,impressions,clicks,ctr}]}`
- `PATCH /api/admin/search/venues/{id}/boost` body `{search_boost:int −100..100}` → `{success, data: updated row}`

## Frontend

- Admin page: `src/views/admin/SearchRankingView.vue` and `src/components/admin/search/*`, route `/support/search`
  (support portal, admin only), linked from the admin sidebar as "Search & Ranking".
- Public search: `src/components/search/*` (smart search box, understood chips, match reasons), used by
  `src/views/public/VenuesView.vue` and the home page search box.
