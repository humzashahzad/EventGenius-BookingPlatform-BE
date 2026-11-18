# EventGenius Booking Platform — Backend

RESTful API for the EventGenius venue booking platform (final-year project). Built with **Laravel 12**, custom JWT authentication, Laravel Reverb for real-time events and a PayFast **sandbox** integration.

---

## Tech Stack

| Layer              | Technology                                                        |
| ------------------ | ----------------------------------------------------------------- |
| Framework          | Laravel 12 (PHP 8.2+)                                             |
| Authentication     | Custom JWT (HMAC-SHA256, `JwtMiddleware` + `RoleMiddleware`)      |
| Real-Time          | Laravel Reverb (Pusher protocol; frontend uses Laravel Echo)      |
| Payments           | PayFast gateway — sandbox only                                    |
| Database           | MySQL (single shared database)                                    |
| Testing            | PHPUnit 11.5                                                      |

Notes on packages that are installed but **not** part of the running architecture:

- `stancl/tenancy` is installed and configured, but the `Store` model does not dispatch tenancy events and no request initialises a tenant. **All stores share one database**; isolation is done in code by scoping queries to `store_id`.
- `laravel/sanctum` is installed but API auth uses the custom JWT guard, not Sanctum tokens.
- `socket-server/` contains an experimental Node.js Socket.io server. It is **not** used by the frontend, which connects to Reverb.

---

## Features

### Venue Management (store owners)
- Venue CRUD with image gallery (upload, delete, set primary, reorder)
- Pricing is **per head** (`price_per_head x expected_guests`). The `price_per_hour/day/event` and `pricing_type` columns exist but are not used in booking pricing.
- Capacity, area, floors, operating hours, event types (admin-managed categories)
- **Amenities** per venue (free or paid with a price) — see endpoints below
- Availability checks for a date and for a whole month

### Booking System
- Lifecycle: **Pending → Confirmed / Rejected**, **Pending/Confirmed → Cancelled**, `completed` status supported
- Clients may select venue amenities when booking (`amenity_ids`)
- Pricing: `base = price_per_head x guests`, `amenities = sum of selected paid amenities`, `tax = 5% x (base + amenities)`, `total = base + amenities + tax`
- `discount_amount` is stored but **discounts are not implemented** (always 0)

### Reviews
- Clients can review a booking once it is `completed`, or `confirmed` with an event date in the past — one review per booking
- Overall rating (1–5) plus optional title, comment and cleanliness/value/service/location sub-ratings
- Reviews are published immediately; admins can hide/unhide or delete them
- Venue `avg_rating` / `total_reviews` are recalculated from approved reviews on every change
- Store owners can see reviews on their venues and post a public reply; the store owner is notified of new reviews and the client of replies

### Public Store Profiles
- Each **approved** store has a public profile (store info, landing page content edited by the owner, active venues, stats). Non-approved stores return 404.

### Payments
- PayFast sandbox: signed form data generation, ITN callback, manual confirmation fallback (ITN cannot reach a local/dev server)

### Real-Time Chat (NexusChat)
- Private and group conversations, message edit/delete, emoji reactions, read receipts
- Broadcast via Laravel Reverb private channels (`/api/broadcasting/auth` with JWT)

### Notifications
- In-app notifications with read/unread tracking, broadcast in real time over Reverb (`NotificationCreated`)
- There is **no** Server-Sent Events (SSE) stream endpoint

### Role-Based Access Control

| Role            | Capabilities                                                                  |
| --------------- | ----------------------------------------------------------------------------- |
| **Admin**       | Users, store approval/suspension, venues, bookings, sessions, categories, review moderation |
| **Store Owner** | Venue + amenity CRUD, gallery, booking confirm/reject, landing page, review replies |
| **Client**      | Browse venues, book (with amenities), pay (sandbox), cancel, review           |

---

## API Overview

All paths are prefixed with `/api`.

| Group           | Base Path            | Key Endpoints                                          |
| --------------- | -------------------- | ------------------------------------------------------ |
| Auth            | `/auth`              | register, login, logout, logout-all, me, password reset |
| Public          | `/venues`, `/stores`, `/categories` | venue list/detail, availability, reviews, store profile |
| Client          | `/client`            | dashboard, bookings, reviews, profile, PayFast payment data |
| Store Owner     | `/store`             | dashboard, venues, images, amenities, bookings, reviews, profile, landing |
| Admin           | `/admin`             | users, stores, venues, bookings, sessions, categories, reviews |
| NexusChat       | `/nexus`             | chats, messages, reactions, read receipts               |
| Notifications   | `/notifications`     | list, unread count, mark read, delete                   |
| Payments        | `/payfast/itn`       | PayFast ITN callback                                    |

### Reviews

| Method | Path                                   | Auth        | Description |
| ------ | -------------------------------------- | ----------- | ----------- |
| GET    | `/venues/{id}/reviews`                 | public      | Approved reviews for a venue, paginated (`per_page`, max 50), with client name/avatar |
| GET    | `/client/bookings/{id}/review`         | client      | `{eligible, has_review, review}` for the client's own booking |
| POST   | `/client/bookings/{id}/review`         | client      | Create review. Body: `rating` (1–5, required), `title`, `comment`, `cleanliness_rating`, `value_rating`, `service_rating`, `location_rating` (1–5, optional). 422 if not eligible or already reviewed |
| GET    | `/store/reviews`                       | store_owner | Reviews on the owner's venues (filters: `venue_id`, `replied=yes|no`) |
| POST   | `/store/reviews/{id}/reply`            | store_owner | Body: `reply` (required). 404 for other stores' reviews |
| GET    | `/admin/reviews`                       | admin       | All reviews (filters: `approved`, `venue_id`, `rating`, `search`) |
| PATCH  | `/admin/reviews/{id}/toggle-approval`  | admin       | Hide/unhide; recalculates the venue rating |
| DELETE | `/admin/reviews/{id}`                  | admin       | Soft delete; recalculates the venue rating |

### Amenities

| Method | Path                                             | Auth        | Description |
| ------ | ------------------------------------------------ | ----------- | ----------- |
| GET    | `/store/venues/{id}/amenities`                   | store_owner | List amenities of an owned venue |
| POST   | `/store/venues/{id}/amenities`                   | store_owner | Body: `name` (required), `icon`, `is_paid`, `price` (required when paid) |
| PUT    | `/store/venues/{venueId}/amenities/{amenityId}`  | store_owner | Update |
| DELETE | `/store/venues/{venueId}/amenities/{amenityId}`  | store_owner | Soft delete |

Venues of other stores return 404. Public `GET /venues/{id}` includes the venue's `amenities`.
`POST /client/bookings` accepts `amenity_ids: number[]`; every id must belong to the booked venue (422 otherwise).

### Public store profile

`GET /stores/{id}/profile` → `{ store: {...store, user: {id,name,avatar}, landing_page, venues[]}, stats: {total_venues, total_bookings, total_reviews, avg_rating, member_since} }`. 404 unless the store is approved.

---

## Project Structure

```
app/
├── Events/                  # NotificationCreated, Chat/* broadcast events
├── Http/
│   ├── Controllers/Api/
│   │   ├── Admin/           # Admin panel controllers
│   │   ├── Auth/            # Register / login / password reset
│   │   ├── Client/          # Client portal controllers
│   │   └── Store/           # Store owner controllers
│   └── Middleware/          # JwtMiddleware, RoleMiddleware
├── Models/                  # Eloquent models
└── Services/                # JwtService, PayFastService, NotificationService,
                             # BookingPricing, VenueRatingService

config/
├── jwt.php                  # JWT secret, TTL
└── payfast.php              # PayFast sandbox credentials

database/migrations/
socket-server/               # Experimental Socket.io server (unused by the frontend)
```

---

## Getting Started

### Prerequisites
- PHP 8.2+, Composer, MySQL

### Installation

```bash
git clone https://github.com/humzashahzad/EventGenius-BookingPlatform-BE.git
cd EventGenius-BookingPlatform-BE
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # optional
php artisan storage:link
```

### Running the Application

```bash
php artisan serve                # API at http://localhost:8000/api
php artisan reverb:start         # real-time chat/notifications (separate terminal)
php artisan queue:work           # if QUEUE_CONNECTION is not sync
```

---

## Environment Variables

| Variable                | Description                           |
| ----------------------- | ------------------------------------- |
| `JWT_SECRET`            | Secret key for JWT signing            |
| `JWT_TTL`               | Token time-to-live in minutes         |
| `PAYFAST_MERCHANT_ID`   | PayFast merchant ID (sandbox)         |
| `PAYFAST_MERCHANT_KEY`  | PayFast merchant key (sandbox)        |
| `PAYFAST_PASSPHRASE`    | PayFast passphrase                    |
| `PAYFAST_SANDBOX`       | Sandbox mode (keep `true`)            |
| `REVERB_APP_ID`         | Reverb app ID                         |
| `REVERB_APP_KEY`        | Reverb app key                        |
| `REVERB_APP_SECRET`     | Reverb app secret                     |
| `FRONTEND_URL`          | Frontend URL                          |

---

## License

This project is developed as part of an academic programme.
