# Data change events

Every change to the shared app records is pushed to the people currently viewing it, so their open
pages refresh quietly without a reload (a customer's booking page updates when the shop owner confirms,
a venue page picks up a new rating, the admin lists stay current, and so on).

## Event

One broadcast event, `App\Events\EntityChanged`, sent as `entity.changed` (Echo: `.listen('.entity.changed', …)`).

```json
{ "entity": "booking", "id": 123, "action": "updated",
  "venue_id": 90, "store_id": 42, "booking_id": 123, "client_id": 404,
  "fields": ["status", "payment_status"], "at": "2026-11-26T10:00:00Z" }
```

- `entity`: `booking | payment | venue | venue_image | venue_amenity | store | landing_page | review | user | category | session`
- `action`: `created | updated | deleted` (a restore arrives as `updated` with `deleted_at` in `fields`)
- Only the related ids that apply are present. `fields` (updates only) lists changed attribute names,
  without timestamps or secrets, max 10. The payload carries ids only, never record contents: clients refetch.
- Public channels never carry `client_id`.

## Channels

| Channel | Type | Auth |
|---|---|---|
| `user.{id}` | private | that user (existing channel, `routes/channels.php`) |
| `admins` | private | `role = admin` |
| `venue.{id}` | public | none |
| `catalog` | public | none |

## Who receives what

| Entity | `user.{id}` | `admins` | `venue.{id}` | `catalog` |
|---|---|---|---|---|
| booking | client, store owner | yes | its venue (availability) | — |
| payment | client, store owner | yes | — | — |
| review (incl. reply / approval) | reviewer, venue's store owner | yes | its venue | — |
| venue | store owner | yes | itself | created/deleted, or a listing field changed (name, slug, thumbnail, city/state/address, status, is_featured, avg_rating, total_reviews, prices, pricing_type, capacity, event_types, search_boost, deleted_at) |
| venue_image, venue_amenity | store owner | yes | its venue | — |
| store | owner | yes | all its venues, when status changes or it is deleted | yes |
| landing_page | store owner | yes | — | yes |
| user | that user | yes | — | — |
| session | its user | yes | — | — |
| category | — | yes | — | yes |

Rating refreshes (`VenueRatingService::recalculate`) send `venue` `updated` with
`fields: ["avg_rating","total_reviews"]`. Review analysis (`ReviewInsightService`) sends `venue` `updated`
with `fields: ["review_insights"]`.

## How it is delivered

1. `App\Observers\LiveDataObserver` is attached to the models listed in `LiveData::ENTITY_BY_CLASS`
   (`AppServiceProvider::registerLiveData`). It passes the model's own foreign keys, so it runs no queries.
   Writes that bypass Eloquent call `LiveData::changed()` directly (`JwtService` revocations,
   `BookingsHousekeeping`, `VenueRatingService`, `SearchRankingController::boost`).
2. `App\Services\LiveData` buffers changes per request / queued job / artisan command. Inside a DB
   transaction a change is only kept if the transaction commits. The same entity + id + action is kept once
   (fields merged); an update to a record created in the same request is dropped. Changes to bookkeeping
   columns only (`updated_at`, `last_used_at`, `last_seen_at`, `password`, `remember_token`) are ignored.
3. The buffer is flushed when the app terminates (after the response has been sent under php-fpm), after
   each queued job, and early once 100 changes are pending. Missing ids (store owner, venue's store, a
   payment's booking) are filled in with at most one small memoised query per change.
4. Each change becomes one private event (`user.*` + `admins`) and, when a public page is affected, one
   public event (`venue.*` / `catalog`). Both are `ShouldBroadcast` (queued, after commit). If the request
   sent `X-Socket-ID`, the sender's own connection is skipped.
5. Nothing here can fail a request: every step catches and logs (`Live data …` warnings).

Bulk writes through `DB::table()` (e.g. `demo:grow`) do not produce events.

## Configuration (`config/broadcasting.php` → `live_data`)

| Env | Default | Meaning |
|---|---|---|
| `LIVE_DATA_ENABLED` | `true` | turn the whole feature off |
| `LIVE_DATA_QUEUE` | default queue | queue name for the broadcast jobs |

Events are skipped when `BROADCAST_CONNECTION` is `null`/`log`, and in the one setup that would deadlock
(PHP's built-in dev server with the `sync` queue publishing to its own host:port).

Delivery latency is the queue worker's poll interval plus a few ms per job. With the database queue,
run the worker with `--sleep=1` (or give the broadcasts their own queue and worker, e.g.
`LIVE_DATA_QUEUE=live` and `queue:work --queue=live --sleep=1`) so changes arrive within about a second
and never wait behind long jobs.

## Adding a new entity

1. Add the model to `LiveData::ENTITY_BY_CLASS` with its entity name.
2. Return its foreign keys from `LiveDataObserver::related()` (`venue_id`, `store_id`, `booking_id`,
   `client_id` go into the payload; `owner_id` / `user_id` only pick recipients).
3. If it needs owner lookups or extra recipients, add a case to `LiveData::resolve()`; if it shows on a
   public page, add it to `EntityChanged::publicChannelNames()`.
4. Add a case to `tests/Unit/LiveDataTest.php`, and tell the frontend the entity name.
