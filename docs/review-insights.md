# AI review insights

Every customer review is analysed offline on this server: sentiment (−1..1), the aspects it talks
about (food, service, decor …), and quality flags (spam, duplicates, shouting, rating/text mismatch).
Per-venue results feed the public venue page, the store-owner and admin dashboards, and the
`sentiment` signal of the smart venue ranking (see `docs/smart-search.md`).

## Pipeline

```
POST /client/bookings/{id}/review ─► AnalyzeReviews job (queued, afterCommit)
demo:grow / any DB insert ────────► reviews:analyze (scheduler, every 5 min, --limit=200)
                                          │
            ReviewInsightService ─► ReviewAnalyzer ─► python analyze.py (one process per batch of 50)
                  │  + possible_duplicate flag (cross-review, in PHP)
                  ▼
            review_insights (one row per review)
                  │
            VenueReviewAggregator::recompute(venue ids) ─► venue_review_insights (one row per venue)
                  │
                  ├─► VenueRanker `sentiment` signal + reasons (next search sees it; no cache)
                  ├─► GET /venues/{id} review_summary
                  └─► store / admin insight endpoints
```

* Analyser: `ai/analyze.py` (source in this repo) installed under `/opt/eventgenius-ai`
  (`REVIEW_AI_PATH`), see `ai/README.md`. English → DistilBERT SST-2 (8-bit ONNX) blended with a lexicon;
  Roman Urdu and Urdu → lexicon with intensifiers and negation. No network, 1 thread, ~13 ms per sentence.
* If Python/the script is missing or fails, a warning is logged and nothing else happens: review
  submission never depends on it, and `reviews:analyze` catches up later.
* Moderation (`toggle-approval`, delete) refreshes the venue aggregate immediately.
* The venue aggregate is also fully rebuilt every 6 hours (recency weights decay).

### What counts

Only approved, non-deleted reviews whose insight has **no** `spam` or `possible_duplicate` flag.
`very_short` reviews count in the label distribution but not in sentiment or aspects.

### Venue sentiment

* recency weight `w = 0.5 ^ (age_days / 90)`
* `smoothed = (k·global + Σ w·s) / (k + Σ w)` with `k = 2` and `global` = recency-weighted platform mean
* public `sentiment` = `round(50 · (smoothed + 1))` (0..100); `label` positive (> 0.25) / mixed / negative (< −0.25)
* aspect `positive_pct` = share of reviews mentioning the aspect whose aspect sentiment > 0.25

### Ranking signal

`sentiment = clamp((smoothed + 0.2) / 1.1)` (−0.2 → 0, 0.9 → 1), left out when the venue has fewer than
`review_ai.min_reviews` (3) counted reviews with text. Default weight 5 (presets: quality_first 8, others 4);
the `top_rated` intent doubles it like `quality`. Reasons (at most one, only for venues with smoothed ≥ 0.3):
the most-praised aspect with ≥ 3 mentions and ≥ 75 % positive — "Guests love the food", "Praised for service",
"Guests love the decor", "Great value, say guests", "Praised for cleanliness", "Easy parking, say guests",
"Well air-conditioned, say guests", "Praised for sound & AV", "Convenient location, say guests",
"Well organised and on time", "Reliable power backup, say guests" — otherwise "Glowing guest reviews"
when smoothed ≥ 0.6 with ≥ 5 reviews.

### Duplicate rule (`possible_duplicate`)

Same comment text (≥ 20 chars after normalising) as an **earlier** review by the same customer on the same venue,
by the same customer within 7 days (copy-paste across venues), or by anyone on the same venue within 48 hours.

## Database (migration `2026_11_26_000001_create_review_insights_tables`)

* `review_insights(id, review_id unique FK, sentiment decimal(5,4), label, confidence, language, aspects JSON, flags JSON, phrases JSON, model_version, analyzed_at, timestamps)`
* `venue_review_insights(venue_id PK FK, analysed_count, text_count, weight_sum, weighted_sentiment, positive_count, neutral_count, negative_count, aspects JSON, highlights JSON, concerns JSON, computed_at, timestamps)`
* `bookings.review_requested_at`, `bookings.review_reminded_at` (nullable timestamps)

## Commands and schedule (`routes/console.php`, run by `schedule:work`)

| Command | Schedule | What |
|---|---|---|
| `reviews:analyze {--limit=200} {--all} {--refresh}` | every 5 min, withoutOverlapping | analyse reviews without an insight (`--all`: everything, e.g. after a model update; `--refresh`: only rebuild venue aggregates) |
| `reviews:request {--limit=100} {--dry-run}` | hourly 10:00–21:00 | review requests + one reminder (below) |

### Review requests

* **Request**: booking `completed`, event ended ≥ 6 h ago, `event_date` within the last 30 days, no review
  (even a hidden/deleted one), `review_requested_at` null, customer active → notification, sets `review_requested_at`.
* **Reminder**: requested ≥ 3 days ago, still no review, `review_reminded_at` null, event within 30 days → one more
  notification, sets `review_reminded_at`.
* Never more than one review notification per customer per 24 h; at most `--limit` per run; the booking row
  is claimed with a conditional update before sending, so a booking can never be asked twice.

Notification (`NotificationService::notifyClient`, broadcast like the others):
```json
{ "type": "review_request", "title": "How was your event?",
  "body": "Tell others about your event at Saddar Royal Hall. Your review takes a minute and helps other families choose.",
  "data": { "booking_id": 3781, "booking_number": "BK-83B05997AAB63", "venue_id": 99, "venue_name": "Saddar Royal Hall",
            "link": "/customer/bookings/3781", "reminder": false } }
```
Reminders use the same type with title "Reminder: how was your event?" and `"reminder": true`.

## HTTP API

### Public — `GET /api/venues/{id}` adds `data.review_summary`

```json
"review_summary": {
  "analysed_count": 35,
  "sentiment": 84,
  "label": "positive",
  "distribution": { "positive": 32, "neutral": 2, "negative": 1 },
  "aspects": [
    { "name": "service", "label": "Service & staff", "positive_pct": 84, "negative_pct": 12, "mentions": 25 },
    { "name": "decor", "label": "Decor & ambience", "positive_pct": 95, "negative_pct": 0, "mentions": 21 },
    { "name": "food", "label": "Food", "positive_pct": 100, "negative_pct": 0, "mentions": 9 },
    { "name": "cooling", "label": "AC & cooling", "positive_pct": 0, "negative_pct": 100, "mentions": 5 }
  ],
  "highlights": ["The hall looked stunning and the food was delicious", "Staff were polite and everything ran on time",
                 "Beautiful decor, great sound and excellent service"],
  "concerns": ["Air conditioning could have been a little stronger", "Parking got a bit crowded", "Dinner service was a bit slow"],
  "updated_at": "2026-11-25T02:01:05+00:00"
}
```
Aspects with fewer than 2 mentions are omitted. With nothing analysed: `{"analysed_count":0,"sentiment":null,"label":null,"aspects":[],"highlights":[],"concerns":[]}`.
Highlights/concerns are short clauses quoted from the guests' own (public) reviews; concerns older than ~6 months are dropped.

### Store owner — `GET /api/store/reviews/insights` (`jwt`, `role:store_owner`)

Query: `venue_id` (optional, one of the owner's venues), `months` (trend length, 1–24, default 12).
```json
{ "success": true, "data": {
  "overall": { "analysed_count": 105, "sentiment": 78, "label": "positive",
               "distribution": { "positive": 87, "neutral": 10, "negative": 8 },
               "aspects": [ { "name": "service", "label": "Service & staff", "positive_pct": 91, "negative_pct": 6, "mentions": 78 } ],
               "updated_at": "…" },
  "venues": [ {
    "venue_id": 91, "name": "Harbour Arena", "city": "Karachi", "avg_rating": 4.21, "total_reviews": 43,
    "summary": { "…same shape as review_summary…": "" },
    "trend": [ { "month": "2026-10", "reviews": 7, "sentiment": 80, "positive": 6, "negative": 0, "avg_rating": 4.43 } ],
    "needs_reply": [ { "review_id": 1766, "rating": 3, "title": "Average", "comment": "Okay for the price. Generator took a minute…",
                       "client_name": "…", "sentiment": 37, "label": "negative", "negative_aspects": ["power_backup"],
                       "created_at": "2026-10-02T21:52:00+00:00" } ]
  } ] } }
```
`needs_reply`: approved reviews of the last 90 days without an owner reply whose text is negative or rating ≤ 2 (most negative first, 5 per venue).

### Admin — `GET /api/admin/reviews/insights` (`jwt`, `role:admin`)

Query: `flag` (only that flag in `flagged`), `limit` (flagged list, 1–100, default 50), `refresh_status=1` (re-probe the model now; otherwise cached 10 min).
```json
{ "success": true, "data": {
  "model": { "enabled": true, "available": true, "model_loaded": true, "model_version": "distilbert-sst2-int8+lex3",
             "path": "/opt/eventgenius-ai", "error": null, "checked_at": "…",
             "versions": [ { "model_version": "distilbert-sst2-int8+lex3", "reviews": 734, "last_analyzed_at": "…" } ] },
  "pending_analysis": 0,
  "totals": { "reviews": 734, "analysed": 734, "counted": 627 },
  "average_sentiment": 80,
  "sentiment_distribution": { "positive": 530, "neutral": 57, "negative": 40 },
  "languages": { "en": 627 },
  "aspects": [ { "name": "service", "label": "Service & staff", "mentions": 476, "positive_pct": 90, "negative_pct": 8 } ],
  "flag_counts": { "spam": 0, "possible_duplicate": 66, "all_caps": 0, "very_short": 0, "rating_text_mismatch": 0 },
  "flagged": [ { "review_id": 1848, "venue": { "id": 91, "name": "Harbour Arena" }, "client": { "id": 402, "name": "…" },
                 "rating": 5, "title": "Exceeded expectations", "comment": "…", "is_approved": true,
                 "sentiment": 97, "label": "positive", "flags": ["possible_duplicate"],
                 "reasons": ["Same text as an earlier review"], "created_at": "…" } ],
  "venues_lowest_sentiment": [ { "venue_id": 97, "name": "Sultan Palace Banquet", "city": "Multan", "analysed_count": 34, "sentiment": 74, "negative_count": 1 } ],
  "venues_highest_sentiment": [ "…" ]
} }
```
Flag reasons: `spam` "Contains a link, phone number, e-mail or promotional text", `possible_duplicate` "Same text as an earlier review",
`all_caps` "Written in capital letters (shouting)", `very_short` "Very short — too little text to analyse",
`rating_text_mismatch` "Rated 5★ but the text reads negative" / "Rated 1★ but the text reads positive".
The default `flagged` list hides reviews whose only flag is `very_short`.

### Admin — `GET /api/admin/reviews` (existing) now includes `insight` on each item

`insight`: `{id, review_id, sentiment, label, confidence, language, aspects, flags, model_version, analyzed_at, …}` or `null` if not analysed yet.
New optional filters: `sentiment=positive|neutral|negative`, `flag=<flag>`, `flagged=1` (any flag).

### Ranking (`GET /api/venues`, admin preview)

`match.signals.sentiment` / `match.contributions.sentiment` appear when the venue has enough analysed text;
`weights.sentiment` is part of the search settings (`GET/PUT /api/admin/search/settings`).

## Configuration (`config/review_ai.php`)

| env | default | |
|---|---|---|
| `REVIEW_AI_ENABLED` | `true` | master switch |
| `REVIEW_AI_PATH` | `/opt/eventgenius-ai` | install folder (`venv/`, `model/`, `analyze.py`) |
| `REVIEW_AI_PYTHON` / `REVIEW_AI_SCRIPT` / `REVIEW_AI_MODEL_DIR` | derived from the path | overrides |
| `REVIEW_AI_TIMEOUT` | `120` | seconds per analyser call |
| `REVIEW_AI_BATCH` | `50` | reviews per analyser call |
| `REVIEW_AI_QUEUE` | default queue | queue for `AnalyzeReviews` |

## Deploying

1. `php artisan migrate --path=database/migrations/2026_11_26_000001_create_review_insights_tables.php --force`
2. `sudo ai/install.sh` (once; already done on this server)
3. `php artisan config:cache && php artisan route:cache` (the live app caches both), `php artisan queue:restart`
   (the worker must load the new job class), and restart `schedule:work` so it reads the new schedule.
4. `php artisan reviews:analyze --all` (backfill; ~30 s for ~730 reviews).
