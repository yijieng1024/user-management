# User Management

## 1. Project overview

An admin-only user management system with a web UI and a REST API, built for the BestWeb Laravel assessment. Admins log in, then list, search, filter, create, edit, soft delete and export users. The same operations are available through a JWT-protected REST API.

**Tech stack:** Laravel 13, PHP 8.3+, MySQL, Livewire starter kit (Livewire 4 + Flux UI), Laravel Fortify (web login), JWT auth ([php-open-source-saver/jwt-auth](https://github.com/PHP-Open-Source-Saver/jwt-auth)), Laravel-Excel ([maatwebsite/excel](https://github.com/SpartnerNL/Laravel-Excel)), Scribe ([knuckleswtf/scribe](https://github.com/knuckleswtf/scribe)) for API docs, PHPUnit.

## 2. Features

**Web (Livewire page at `/users`)**

- Admin-only login. Public registration is disabled.
- User list with 10 users per page, newest first.
- Filter by status (all / active / inactive / suspended).
- **Extra:** search by name, email or phone number. It works together with the status filter, and both are kept in the URL.
- Create and edit users in a modal: name, email, phone number, password (optional when editing), status, and an "Is admin" checkbox.
- Soft delete a single user, with a confirmation dialog.
- Bulk delete: tick rows (or "select all on this page"), confirm, and delete in one query.
- Export all users to Excel (`users_YYYY-MM-DD_HHMMSS.xlsx`).
- Success messages (toasts) after create, update and delete.
- Self-protection: an admin cannot delete themselves, remove their own admin flag, or change their own status.

**REST API (`/api/...`)**

- JWT login (`POST /api/login`).
- List users with status filter, search and pagination; create, show and soft delete users; bulk delete.
- **Extra:** update endpoint (`PATCH /api/users/{user}`).
- **Extra:** token refresh (`POST /api/refresh`) and logout with token blacklisting (`POST /api/logout`).
- **Extra:** rate limiting (login 5/min, API 60/min).
- JSON errors with proper status codes.

## 3. Setup instructions

### Requirements

| Tool | Version |
|---|---|
| PHP | 8.3 or newer (`composer.json`: `^8.3`), with the usual Laravel extensions plus `pdo_mysql`, `zip` and `gd` (needed by Laravel-Excel) |
| Composer | 2.x |
| Node.js / npm | Node `^20.19.0`, `^22.18.0` or `>=24.11.0` (required by the build tools Vite 8 / Vite+) |
| MySQL | Any version supported by Laravel 13 (MySQL 8.0+ recommended). Not needed for running the tests. |

### Steps

```bash
# 1. Get the code and install dependencies
git clone https://github.com/yijieng1024/user-management
cd user-management
composer install
npm install && npm run build

# 2. Create the environment file and secrets
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

3. Create an empty MySQL database named `user_management`:

   ```sql
   CREATE DATABASE user_management;
   ```

   `.env` is already set up for MySQL on `127.0.0.1:3306` with that database name. Just fill in your MySQL username and password:

   ```dotenv
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. Create the tables and seed the data:

   ```bash
   php artisan migrate --seed
   ```

5. Start the app:

   ```bash
   php artisan serve
   ```

   Open http://localhost:8000. Alternatively, with [Laravel Herd](https://herd.laravel.com), put the project in your Herd folder and open `http://user-management.test`.

   During development you can use `composer run dev` instead, which also runs the Vite dev server.

### Default login

| Account | Email | Password |
|---|---|---|
| Admin | `admin@example.com` | `password` |
| 30 fake users (normal users, status `active`) | random | `password` |

The fake users are not admins, so they cannot log in. They are there to fill the list.

### Running the tests

```bash
php artisan test
```

The tests use an in-memory SQLite database (set in `phpunit.xml`), so you don't need MySQL or a `.env` JWT secret to run them.

## 4. API documentation

**Interactive API docs** (generated with [Scribe](https://scribe.knuckles.wtf), public, no login needed):

| What | URL |
|---|---|
| Docs page with "Try It Out" | http://localhost:8000/docs |
| OpenAPI 3.0 spec (Swagger-compatible, YAML) | http://localhost:8000/docs.openapi |
| Postman collection (v2.1) | http://localhost:8000/docs.postman |

(With Herd, replace `http://localhost:8000` with `http://user-management.test`.)

To use "Try It Out": call **Log in** first, copy the `access_token`, and paste it into the auth field of the other endpoints.

The generated docs are committed, so they work right after cloning. After changing the API, regenerate them with:

```bash
php artisan scribe:generate
```

Scribe never sends real requests to the API while generating (response calls are turned off), so it can't create, change or delete data. Example responses come from attributes on the controllers and from `UserResource` with in-memory example users (`UserFactory::apiDocsExample()`, never saved).

Base URL: `http://localhost:8000/api` (or `http://user-management.test/api` with Herd).
Send `Accept: application/json` with every request.

### Auth flow

1. `POST /api/login` with email and password. You get an `access_token`.
2. Send it on every request: `Authorization: Bearer <token>`.
3. The token lasts **60 minutes**.
4. `POST /api/refresh` with the current token (even an expired one) returns a new token and blacklists the old one. This works for up to **1 day** after the token was first issued. After that, log in again.
5. `POST /api/logout` blacklists the token, so it can't be used again.

Only **active admins** can get a token. Every API call checks again that the user is still an active admin. An admin who is suspended (or loses the admin flag) is blocked on their next request, even if their token hasn't expired.

### Endpoints

| Method | URL | Description | Auth required |
|---|---|---|---|
| POST | `/api/login` | Get a JWT | No |
| POST | `/api/refresh` | Swap the current token for a new one | Bearer token (may be expired, up to 1 day old) |
| POST | `/api/logout` | Blacklist the current token | Yes |
| GET | `/api/users` | List users (10 per page) | Yes (active admin) |
| POST | `/api/users` | Create a user | Yes (active admin) |
| GET | `/api/users/{id}` | Show one user | Yes (active admin) |
| PATCH | `/api/users/{id}` | Update a user (`PUT` also works) | Yes (active admin) |
| DELETE | `/api/users/{id}` | Soft delete a user | Yes (active admin) |
| POST | `/api/users/bulk-delete` | Soft delete many users | Yes (active admin) |

### Query parameters for `GET /api/users`

| Parameter | Example | Description |
|---|---|---|
| `status` | `active` | `active`, `inactive` or `suspended`. Any other value is ignored (all users are returned). |
| `search` | `ali` | Matches part of the name, email or phone number. Combined with `status` using AND. |
| `page` | `2` | Page number. 10 users per page. |

Example: `GET /api/users?status=active&search=ali&page=2`

### Request body examples

**Login** `POST /api/login`

```json
{
    "email": "admin@example.com",
    "password": "password"
}
```

**Create** `POST /api/users`

```json
{
    "name": "Ali Bin Abu",
    "email": "ali@example.com",
    "phone_number": "012-3456789",
    "password": "password",
    "password_confirmation": "password",
    "status": "active"
}
```

Rules: all fields are required; `email` must be unique; `phone_number` must be unique, max 20 characters; `password` must be confirmed and at least 8 characters (in production: at least 12, with upper and lower case, a number, a symbol, and not found in known data leaks; set in `AppServiceProvider`); `status` must be `active`, `inactive` or `suspended`. `is_admin` is ignored if sent: users created through the API are never admins.

**Update** `PATCH /api/users/{id}`

```json
{
    "name": "Ali Bin Abu",
    "email": "ali@example.com",
    "phone_number": "012-3456789",
    "status": "suspended"
}
```

Same rules as create, except: `password` is optional (leave it out to keep the current password), and the email and phone number may stay the same as the user's current ones. `is_admin` is ignored. An admin cannot change their own status.

**Bulk delete** `POST /api/users/bulk-delete`

```json
{
    "ids": [12, 15, 18]
}
```

`ids` must be an array of 1–1000 distinct integers, and every ID must belong to an existing, non-deleted user. The logged-in admin's own ID is always skipped.

### Example responses

**200** `POST /api/login`

```json
{
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "bearer",
    "expires_in": 3600
}
```

**201** `POST /api/users` (show and update return the same shape with status 200)

```json
{
    "data": {
        "id": 32,
        "name": "Ali Bin Abu",
        "email": "ali@example.com",
        "phone_number": "012-3456789",
        "status": "active",
        "is_admin": false,
        "created_at": "2026-10-07T15:30:00+00:00",
        "updated_at": "2026-10-07T15:30:00+00:00"
    }
}
```

`GET /api/users` returns `data` (a list of users in this shape) plus Laravel's standard `links` and `meta` pagination objects (`current_page`, `last_page`, `per_page`, `total`, ...).

`DELETE /api/users/{id}` returns `{"message": "User Ali Bin Abu deleted."}`, and bulk delete returns `{"deleted": 3}`.

**422** validation error

```json
{
    "message": "The email has already been taken. (and 1 more error)",
    "errors": {
        "email": ["The email has already been taken."],
        "phone_number": ["The phone number has already been taken."]
    }
}
```

**401** missing, invalid, expired or blacklisted token

```json
{
    "message": "Unauthenticated."
}
```

### Status codes

| Code | When |
|---|---|
| 200 | Success (login, refresh, logout, list, show, update, delete, bulk delete) |
| 201 | User created |
| 401 | No token, or the token is invalid, expired, blacklisted (after logout or refresh), or older than the 1-day refresh window |
| 403 | The user is not an admin (`"You do not have admin access."`) or not active (`"Your account is not active."`), or an admin tries to delete themselves |
| 404 | User not found or soft deleted, or unknown API URL |
| 422 | Validation error. Login errors are also 422, with the message on the `email` field. |
| 429 | Too many requests (see rate limits). The response has a `Retry-After` header. |

### Rate limits

- **Login:** 5 requests per minute per email + IP address. This limit is shared with the web login form.
- **API:** 60 requests per minute per user (per IP address when there is no logged-in user). Applies to `/api/users*`, `/api/refresh` and `/api/logout`.

## 5. Assumptions and design choices

- **"API accessible by anyone" vs. authentication.** The spec says the API should be accessible by anyone, but authentication is also graded. My interpretation: the API documentation is public, but calling the API requires an authenticated admin.
- **`deleted_at` is not a form field.** The spec lists it among the create fields, but it is managed by Laravel's soft deletes. Users never enter it.
- **Status values.** `active`, `inactive`, `suspended`. Stored as a string (`VARCHAR(20)`, indexed), not a MySQL `ENUM`, and restricted by validation. Adding a new status only needs a code change, not a schema change.
- **Admins.** Admin is a separate `is_admin` flag, not a status. Only admins with status `active` can log in, on the web and the API. The check runs again on every request (middleware), so a suspended admin is blocked on their next request, even with a valid session or token.
- **No public registration.** Registration is disabled in Fortify. The first admin is created by the seeder; after that, admins create users from the web UI.
- **Soft-deleted users keep their email and phone number reserved.** The unique rules also count soft-deleted users. The intended way to bring someone back is to restore the record, not to create a duplicate.
- **Self-protection.** Admins cannot delete themselves, remove their own admin flag, or change their own status. This prevents the last admin from locking everyone out. It is enforced on the server (Form Request rules and checks in the delete code), and the UI hides or disables those controls.
- **`is_admin` only from the web UI.** The API ignores `is_admin` on create and update, so a leaked API token can't create or promote admins. `is_admin` is also kept out of `$fillable` and is always set explicitly in code.
- **JWT instead of Sanctum.** JWT is the token authentication I use regularly and am most comfortable with. Sanctum, Laravel's official option, would also have worked. The trade-off with JWT is that a token can't be revoked by deleting a database row, because the token itself proves who the user is. To handle that:
  - logout and refresh use the library's **blacklist**;
  - tokens are short-lived (**60 minutes**), with a **1-day refresh window**;
  - every request still loads the user from the database to check "admin + active". This gives up some of JWT's statelessness, but it means a suspension takes effect immediately rather than when the token expires.
  - Sanctum was never installed: `php artisan install:api` would install it, so `routes/api.php` was registered manually instead.
- **Phone number stored as a string.** So leading zeros (`012...`) and `+60` are kept. The Excel export also writes it as text, so Excel doesn't drop the zero.
- **Excel export.** Always exports all non-deleted users, ignoring the page's current search and filter. Columns: Name, Email, Phone Number, Status, Is Admin (Yes/No), Created At. The password and remember token are never selected from the database.
- **Validation in one place.** The rules live in `StoreUserRequest` and `UpdateUserRequest`. The Livewire form reads its rules and messages from them, and the API controllers use them directly. The API Form Requests (`app/Http/Requests/Api/`) extend the web ones and only drop `is_admin`.
- **Shared logic.** Creating, updating, bulk deleting users and checking login credentials are small action classes (`app/Actions/`) used by both the web UI and the API. The status filter and search are one query scope (`User::filter()`) used by both.
- **"Swagger or Laravel API resources".** API responses use Laravel API Resources (`UserResource`). The interactive documentation at `/docs` is generated with Scribe, which also produces an OpenAPI (Swagger-compatible) spec and a Postman collection.
- **Deployment.** The spec mentions deployment but gives no target, so the project is set up to run locally.

## 6. Security and performance

### Security

- Passwords are hashed (the model's `hashed` cast, bcrypt).
- Mass assignment protection: `is_admin` is not fillable; only the listed fields are.
- Admin + active check on every page behind the login, every Livewire request (registered as persistent Livewire middleware), and every `/api/users` call.
- Rate limiting on login (5/min per email + IP) and on the API (60/min).
- No password or remember token in any API response (fields are listed explicitly in `UserResource`) or in the Excel export.
- Generic login error ("These credentials do not match our records.") for a wrong email or password, so nobody can find out which emails exist. The specific "no admin access" / "not active" messages are only shown after the correct password is given.
- Logged-out and refreshed JWTs are blacklisted.
- In production, set `APP_DEBUG=false` (`.env.example` has `true` for local development). Otherwise error responses include stack traces.

### Performance

- Pagination (10 per page) on the web list and the API.
- Database indexes: unique indexes on `email` and `phone_number`, and an index on `status`.
- Bulk delete is a single `UPDATE ... WHERE id IN (...)` query, not a loop. The bulk-delete validation checks all IDs in one query too.
- The Excel export reads users in chunks of 500 and selects only the exported columns.

### Known limits

- Search uses `LIKE '%term%'`, which can't use an index. That's fine at this scale; for millions of rows, a full-text index or a search engine would be the next step.
- Laravel-Excel reads the database in chunks, but still builds the spreadsheet in memory. For very large datasets, a queued export that saves the file to storage would be the next step.

## 7. Testing

**147 tests, 596 assertions**, run with `php artisan test` (about 8 seconds).

They use an in-memory SQLite database (configured in `phpunit.xml`), so anyone can run them without setting up MySQL, and every test starts with a clean database. `phpunit.xml` also sets a test-only `JWT_SECRET`.

| File | Tests | What it covers |
|---|---|---|
| `tests/Feature/UserManagementTest.php` | 37 | Users page: access (guests, non-admins, inactive admins, login messages), list, status filter, search, pagination, create/update validation (unique email/phone, soft-deleted email still taken, blank password keeps the old one), the is_admin checkbox, single and bulk delete (one query, skips self), self-protection |
| `tests/Feature/UserExportTest.php` | 12 | Excel export: file name, access (admin / non-admin / inactive / guest), heading row, row values, all non-deleted users, ignores filters, no password or remember token, leading zero kept, chunked reading |
| `tests/Feature/Api/AuthTest.php` | 19 | JWT login (token response, error messages, 5/min limit), 401 for missing/invalid/expired/blacklisted tokens, refresh (new token, old one dies, 1-day window, suspended admin), logout |
| `tests/Feature/Api/UserApiTest.php` | 27 | `/api/users`: list with filter/search/pagination, create (201), show, update, delete, bulk delete, is_admin ignored, self-protection, 404 for soft-deleted users, no password in responses, suspended admin blocked mid-token, 60/min limit (429) |
| `tests/Unit/UserTest.php` | 17 | `isActive()`, `hasAdminAccess()`, `adminAccessDeniedReason()`, the `filter()` scope's SQL (grouping, escaping), hidden fields, `is_admin` not fillable |
| `tests/Unit/UsersExportTest.php` | 8 | Export headings, row mapping, selected columns, soft-delete exclusion, chunk size, values written as text |
| `tests/Unit/UserFormRequestsTest.php` | 9 | Store/update rules, self-protection rules, API requests dropping `is_admin`, bulk-delete rules |
| Starter kit tests (`Auth/`, `Settings/`, `DashboardTest`, examples) | 18 | Web login/logout, password reset, profile and password settings, dashboard |

## 8. Project structure

The files worth looking at first:

| Path | What it is |
|---|---|
| `resources/views/pages/users/⚡index.blade.php` | The Users page (single-file Livewire component: list, filter, search, modals, delete, bulk delete) |
| `app/Livewire/Forms/UserForm.php` | The create/edit form, using the Form Request rules |
| `app/Http/Requests/` | `StoreUserRequest`, `UpdateUserRequest` (the single source of validation rules), and `Api/` versions that drop `is_admin`, plus `BulkDeleteUsersRequest` |
| `app/Actions/` | `Auth/AuthenticateAdmin` (login check for web and API), `Users/CreateUser`, `UpdateUser`, `DeleteUsers` |
| `app/Http/Middleware/EnsureUserIsAdmin.php` | The admin + active check on logged-in web pages, Livewire requests and `/api/users` |
| `app/Http/Controllers/Api/` | `AuthController` (login, refresh, logout) and `UserController` (users endpoints) |
| `app/Http/Resources/UserResource.php` | The API's user JSON shape |
| `app/Exports/UsersExport.php` | The Excel export |
| `app/Http/Controllers/UserExportController.php` | The export download route |
| `app/Models/User.php` | Statuses, access rules, the `filter()` scope, JWT methods |
| `routes/web.php`, `routes/api.php` | Routes and their middleware |
| `config/scribe.php` | API docs settings (public `/docs`, Bearer auth, Try It Out, no response calls) |
| `app/Providers/` | Fortify login hook and rate limiters |
| `database/migrations/0001_01_01_000000_create_users_table.php`, `database/seeders/DatabaseSeeder.php` | Users table and seed data |
| `tests/` | Feature and unit tests (see above) |
