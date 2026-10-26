# HostelEase — Hostel Room Booking System

An online hostel accommodation system for students and hostel administrators, built with
**plain PHP (procedural), MySQL and the `mysqli` extension** — no framework, no Composer —
and styled as a full **Material You (Material Design 3)** product.

> Students browse hostels, search and filter rooms, submit accommodation applications with
> supporting documents, track status and receive notifications.
> Administrators review applications (approve / reject), convert approved applications into
> room-assignment records, manage the room inventory, and generate vacancy, occupancy,
> booking and accommodation reports with CSV export.

---

## 1. Requirements

| Component | Version |
|-----------|---------|
| PHP       | 8.1+ with the `mysqli` and `fileinfo` extensions (8.4 tested) |
| MySQL / MariaDB | 8.0 / 10.6+ (MariaDB 11 tested) |
| Web server | PHP built-in server, Apache (XAMPP/LAMP) or nginx + php-fpm |

No external PHP libraries. Roboto is embedded as base64 WOFF2 (`assets/css/fonts.css`),
icons are inline SVG, charts are pure CSS — the UI works fully offline.

---

## 2. Manual database setup (3 imports, no other steps)

`schema.sql` now contains `CREATE DATABASE IF NOT EXISTS hostel_booking …; USE hostel_booking;`,
so a bare import builds the database itself — you never have to create it by hand.

```bash
# 1. schema: creates the DATABASE + all tables, FKs and views
mysql -u root -p < database/schema.sql

# 2. app account used by includes/config.php (optional — skip it and put your
#    own credentials in includes/config.php instead)
mysql -u root -p < database/create_user.sql

# 3. demo data: users, hostels, 94 rooms, bookings, notifications (optional)
mysql -u root -p < database/seed_data.sql
```

Equivalent one-liner if you prefer a single command:

```bash
mysql -u root -p -e "SOURCE database/schema.sql; SOURCE database/create_user.sql; SOURCE database/seed_data.sql;"
```

In **phpMyAdmin**: *Import* → choose `database/schema.sql` → Go (the database appears by
itself), then import `create_user.sql` and, if wanted, `seed_data.sql` the same way.
On shared hosting where the database and user already exist, delete the first two
`CREATE DATABASE`/`USE` lines from `schema.sql` (or keep them — `IF NOT EXISTS` + `USE`
are harmless when the names match) and skip `create_user.sql`.

### Serve the app

```bash
php -S 0.0.0.0:8080 router.php      # or: bash setup.sh (does all of the above + serves)
```

Open <http://localhost:8080>.

Regenerate the demo data any time with `php database/seed.php` (rewrites *and* applies
`seed_data.sql`). Remove the seed step entirely for a production install.

### XAMPP / LAMP

1. Copy the project folder into `htdocs/` (e.g. `htdocs/hostel`).
2. Create the database/user above in phpMyAdmin (or `mysql` CLI) and import the two SQL files.
3. If your DB credentials differ, edit `includes/config.php`.
4. Browse to `http://localhost/hostel/` — all links and assets resolve correctly from a
   subfolder (they are generated relative to the current script).

The bundled `.htaccess` blocks direct HTTP access to `includes/`, `uploads/` and `database/`
on Apache. With the built-in server the same protection is enforced by `router.php`.

---

## 3. Demo accounts

| Role      | Email                        | Password      |
|-----------|------------------------------|---------------|
| Admin     | `admin@hostel.edu`           | `Admin@123`   |
| Admin 2   | `lourdes.v@hostel.edu`       | `Admin@123`   |
| Student   | `maria.santos@university.edu`| `Student@123` |
| Student   | `jose.reyes@university.edu`  | `Student@123` |
| …6 more seeded students (see `database/seed.php`) | | |

New students can self-register on `register.php`.

---

## 4. Feature map

### Student portal (`/student`)
- **Dashboard** – greeting hero, live vacancy counters, application summary, occupancy bars.
- **Browse Hostels** – per-block facilities, warden, price/vacancy matrix, occupancy donut.
- **Hostel detail** – room list with bed maps, filters (type / vacant only), rate cards.
- **Find a Room** – search by text plus filters: hostel block, room type, price range,
  capacity, floor, availability, and five sort orders; paginated results.
- **New Application** – 4-step form (block + type + preferred room, intake + check-in +
  roommates, document uploads, declaration). Gender-policy eligibility is enforced,
  duplicates are blocked, vacancy is validated.
- **My Applications** – status chips, filters, cancel / re-apply, office remarks.
- **Application record** – progress timeline, document upload & delete, print view.
- **My Room** – confirmed assignment: room, bed, roommates, facilities, checklist, history.
- **Notifications** (shared, `/notifications.php`) – inbox with filters, mark-all, delete.
- **Profile** (shared, `/profile.php`) – personal + emergency details, password change.

### Admin console (`/admin`)
- **Dashboard** – KPIs, occupancy donut & bars, status mix, monthly applications chart,
  review queue, recent activity timeline.
- **Applications** – filter/search, bulk approve & reject (with mandatory rejection reason),
  one-click **assign room** (auto-pick lowest vacant bed or choose a room from a modal),
  check-out, CSV export.
- **Application view** – full record, applicant profile, documents viewer, eligibility
  warnings, approve / reject / cancel / assign / release, audit trail.
- **Room Assignments** – active/transferred/checked-out records, **transfer** a student to
  another vacant room (single transaction), check-out, CSV export.
- **Rooms & Blocks** – inventory CRUD, bulk status changes, table **and floor-plan views**,
  duplicate-room guard, delete guard while occupied.
- **Hostels** – block CRUD, gender policy, warden, activate/deactivate, delete guard.
- **Room Types** – capacity, semester price, facilities CRUD with usage guards.
- **Students** – directory with application/room counts, filters, suspend/reactivate,
  admin password reset (temporary password issued + notified), CSV export.
- **Reports** – Vacancy, Occupancy, Booking (date range + status), Accommodation;
  each with summary tiles, charts, printable layout and CSV download.

### Platform behaviour
- Role-based access on every page; suspended accounts cannot sign in.
- Every write is CSRF-protected; every query is a **prepared `mysqli` statement**.
- Passwords hashed with `password_hash()` (bcrypt) + transparent rehash on login.
- Uploads validated by extension **and** MIME sniffing, stored with random names outside
  the web tree, streamed through `document.php` (owner / uploader / admin only).
- Notifications on every state change (submitted, approved, rejected, assigned,
  transferred, checked out, cancelled, document uploaded, password reset, suspension…).
- Bed occupancy is maintained transactionally with row locks (`FOR UPDATE`), so two admins
  can never double-book a bed.
- Print-friendly stylesheets (`@media print`) and `prefers-reduced-motion` support.

---

## 5. Project layout

```
├── index.php                 public landing page
├── login.php  register.php  logout.php
├── profile.php  notifications.php   shared (both roles)
├── document.php              authorised document download
├── router.php                dev-server router + path protection
├── .htaccess                 Apache hardening (subfolder-safe)
├── includes/
│   ├── config.php            DB credentials, constants, session bootstrap
│   ├── functions.php         helpers: auth, csrf, flash, vacancy maths,
│   │                         assign_room()/release_assignment() transactions,
│   │                         uploads, csv export, app_url(), inline SVG icons
│   ├── header.php            app chrome (rail, topbar, notification sheet)
│   └── public_header.php     landing/auth chrome
├── student/                  student portal pages
├── admin/                    admin console pages
├── assets/css/material-you.css   MD3 design tokens + components
├── assets/css/fonts.css          embedded Roboto (offline-safe)
├── assets/js/app.js              micro-interactions (vanilla)
├── database/
│   ├── schema.sql            tables, FKs, views (v_room_vacancy, v_hostel_occupancy)
│   ├── seed.php              generates + applies demo data
│   └── seed_data.sql         generated demo SQL (bcrypt hashes included)
└── uploads/documents/        uploaded supporting files (never served directly)
```

---

## 6. Database schema (summary)

`users` · `hostels` · `room_types` · `rooms` · `bookings` · `assignments` · `documents` ·
`notifications` + views `v_room_vacancy`, `v_hostel_occupancy`.

- `bookings.status`: `pending → approved → assigned → checked_out`, or `rejected` / `cancelled`.
- `assignments` links an approved booking to a concrete `rooms.id` + `bed_number`; the room's
  `occupied` counter and `status` (`available` / `full` / `maintenance` / `blocked`) are kept in
  sync inside the same transaction.

---

## 7. Security notes

- Prepared statements everywhere (no string-built SQL with user input).
- `htmlspecialchars()` (`e()`) on all output; CSRF tokens on every POST form.
- Session cookies `HttpOnly` + `SameSite=Lax`; `session_regenerate_id()` on login.
- Uploads: 3 MB cap, whitelist (pdf/jpg/png/doc/docx), MIME verification, `0640` perms.
- Direct access to `includes/`, `uploads/`, `database/` returns 403.

---

## 8. Licence & credits

Course/project demonstration code — free to use and adapt.
Design system: Material You / Material Design 3 (Google), seed colour `#6750A4`.
