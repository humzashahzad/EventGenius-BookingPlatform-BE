# EventGenius Booking Platform — Backend

A comprehensive RESTful API powering the EventGenius venue booking ecosystem. Built with **Laravel 12**, featuring multi-tenancy, real-time communication, JWT authentication, and integrated payment processing.

---

## Tech Stack

| Layer              | Technology                                     |
| ------------------ | ---------------------------------------------- |
| Framework          | Laravel 12 (PHP 8.2+)                          |
| Authentication     | Custom JWT (HMAC-SHA256, 24h TTL)              |
| Multi-Tenancy      | Stancl/Tenancy 3.9 (per-store DB isolation)    |
| Real-Time          | Laravel Reverb + Socket.io (Node.js)           |
| Payments           | PayFast Gateway (Sandbox)                      |
| Database           | SQLite (default) / MySQL / PostgreSQL          |
| API Auth           | Laravel Sanctum 4.3                            |
| Testing            | PHPUnit 11.5                                   |

---

## Features

### Venue Management
- Full CRUD for venues with image galleries, amenities, and dynamic pricing
- Hourly, daily, and event-based pricing tiers
- Capacity, area, and geographic coordinates
- Availability calendar with date-range checking

### Booking System
- End-to-end booking lifecycle: **Pending → Confirmed / Rejected → Cancelled**
- Pricing breakdown (base + amenities + tax - discount)
- Month-view and date-specific availability queries

### Payment Processing
- PayFast integration with ITN (Instant Transaction Notification) callbacks
- MD5 signature validation for secure transactions
- Manual confirmation fallback

### Real-Time Chat (NexusChat)
- Private and group conversations
- Message editing, deletion, and reply-to
- Emoji reactions and read receipts
- Typing indicators and online presence
- Socket.io server with Redis pub/sub for horizontal scaling

### Notifications
- In-app notification system with read/unread tracking
- Server-Sent Events (SSE) streaming endpoint
- Socket.io broadcast integration

### Multi-Tenancy
- Each store operates in an isolated database via Stancl/Tenancy
- Domain-to-tenant mapping
- Store approval/suspension workflow

### Role-Based Access Control
Three distinct user roles with dedicated API surfaces:

| Role           | Capabilities                                                      |
| -------------- | ----------------------------------------------------------------- |
| **Admin**      | User management, store approval, session oversight, global stats  |
| **Store Owner**| Venue CRUD, booking management, gallery uploads, store profile    |
| **Client**     | Browse venues, create bookings, make payments, leave reviews      |

---

## API Overview

| Group               | Base Path                | Key Endpoints                              |
| -------------------- | ----------------------- | ------------------------------------------ |
| Auth                 | `/api/auth`             | register, login, logout, password reset    |
| Public Venues        | `/api/venues`           | list, detail, availability                 |
| Client               | `/api/client`           | dashboard, bookings, profile, payments     |
| Store Owner          | `/api/store`            | dashboard, venues, bookings, profile       |
| Admin                | `/api/admin`            | users, stores, venues, bookings, sessions  |
| NexusChat            | `/api/nexus`            | chats, messages, reactions, typing         |
| Notifications        | `/api/notifications`    | list, unread count, mark read, SSE stream  |
| Payments             | `/api/payfast`          | ITN callback                               |

---

## Project Structure

```
app/
├── Events/Chat/          # Broadcasting events (MessageSent, UserTyping, etc.)
├── Http/
│   ├── Controllers/
│   │   ├── Admin/        # Admin panel controllers
│   │   ├── Client/       # Client portal controllers
│   │   └── Store/        # Store owner controllers
│   └── Middleware/        # JwtMiddleware, RoleMiddleware
├── Models/               # 16+ Eloquent models
└── Services/             # JwtService, PayFastService, NotificationService, SocketService

config/
├── jwt.php               # JWT secret, TTL, refresh settings
├── payfast.php            # PayFast merchant credentials
└── tenancy.php            # Multi-tenancy configuration

database/migrations/       # 25+ migration files
socket-server/             # Node.js Socket.io real-time server
```

---

## Getting Started

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+ (for Socket.io server)

### Installation

```bash
# Clone the repository
git clone https://github.com/humzashahzad/EventGenius-BookingPlatform-BE.git
cd EventGeniusBookingPlatform-BE

# Install PHP dependencies
composer install

# Install Node dependencies (for socket server)
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Seed the database (optional)
php artisan db:seed
```

### Running the Application

```bash
# Start the Laravel dev server
php artisan serve

# Start with a custom host and port
php artisan serve --host=192.168.1.100 --port=8080

# Start the Socket.io server (separate terminal)
node socket-server/server.js

# Start Laravel Reverb (optional, separate terminal)
php artisan reverb:start
```

By default the API will be available at `http://localhost:8000/api`.
With custom host/port it will be at `http://<host>:<port>/api`.

---

## Environment Variables

| Variable                | Description                          |
| ----------------------- | ------------------------------------ |
| `JWT_SECRET`            | Secret key for JWT signing           |
| `JWT_TTL`               | Token time-to-live (default: 1440)   |
| `PAYFAST_MERCHANT_ID`   | PayFast merchant ID                  |
| `PAYFAST_MERCHANT_KEY`  | PayFast merchant key                 |
| `PAYFAST_PASSPHRASE`    | PayFast passphrase                   |
| `PAYFAST_SANDBOX`       | Enable sandbox mode (true/false)     |
| `REVERB_APP_ID`         | Reverb WebSocket app ID              |
| `REVERB_APP_KEY`        | Reverb WebSocket app key             |
| `REVERB_APP_SECRET`     | Reverb WebSocket app secret          |
| `FRONTEND_URL`          | Frontend URL (default: localhost:5173)|

---

## License

This project is developed as part of an academic programme.
