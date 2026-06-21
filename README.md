# Employee GraphQL API

A Laravel GraphQL API for managing employees at scale (10,000 records). It
covers authentication, CRUD, and Excel bulk-import / export, with every part
designed around handling large datasets without exhausting memory or timing out.

## Features

| # | Capability | How |
|---|---|---|
| 1 | Login with username + password → access token | Laravel Passport **password grant** via a GraphQL `login` mutation |
| 2 | Seed 10,000 employees | Faker + **chunked batch inserts** (1,000/insert) |
| 3 | List employees | GraphQL `employees` query with **pagination** |
| 4 | Delete an employee | GraphQL `deleteEmployee` mutation |
| 5 | Update an employee | GraphQL `updateEmployee` mutation (validated) |
| 6 | Bulk import from Excel (~10k rows) | `importEmployees` mutation → **queued, chunked** upsert (+ `importStatus` progress) |
| 7 | Export all employees (~10k) to Excel | Passport-guarded REST route using **`FromQuery`** streaming |

## Stack

- PHP **8.3**, Laravel **13.x**
- [nuwave/lighthouse](https://lighthouse-php.com) **v6** (GraphQL)
- [laravel/passport](https://laravel.com/docs/passport) **v13** (OAuth2)
- [maatwebsite/excel](https://docs.laravel-excel.com) **v3.1** (import/export)
- **PostgreSQL** + **database** queue driver

> Tested with PHP 8.3.30, Laravel 13.16.1, Passport v13.7, Lighthouse v6.67,
> maatwebsite/excel 3.1.69.

---

## Setup

### 1. Prerequisites

- PHP 8.3+, Composer 2
- PostgreSQL 14+ running locally
- A database for the app:

```bash
createdb employee_api
```

### 2. Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 3. Configure the database

Edit `.env` to match your PostgreSQL setup:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=employee_api
DB_USERNAME=your_pg_user
DB_PASSWORD=your_pg_password

QUEUE_CONNECTION=database
LIGHTHOUSE_QUERY_CACHE_MODE=opcache
```

### 4. Passport keys + password client

```bash
php artisan passport:keys                       # generate OAuth signing keys
php artisan passport:client --password \
  --name="employee-api password grant" \
  --provider=users --no-interaction
```

Copy the printed **Client ID** and **Client secret** into `.env`:

```dotenv
PASSPORT_PASSWORD_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
PASSPORT_PASSWORD_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

> The password grant is enabled in code via `Passport::enablePasswordGrant()`
> (`app/Providers/AppServiceProvider.php`) — Passport disables it by default.

### 5. Migrate + seed

```bash
php artisan migrate:fresh --seed
```

This:
- creates all tables (users, employees, Passport, jobs),
- seeds the **password grant client** from your `.env` (so it survives
  `migrate:fresh`),
- seeds an **admin** user (`admin` / `password`),
- seeds **10,000 employees** (deterministic — fixed Faker seed — so the bundled
  sample import file always matches).

### 6. Run

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan queue:work       # in a second terminal — required for Excel import
```

> After changing `.env`, `config/*`, or `bootstrap/app.php`, restart
> `php artisan serve` (it is single-process and reads those once at boot). If a
> schema change ever seems stale, run `php artisan optimize:clear`.

---

## Quick start

GraphQL endpoint: `POST /graphql`. Export endpoint: `GET /api/employees/export`.

### Get a token

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"mutation { login(username: \"admin\", password: \"password\") { access_token token_type expires_in } }"}'
```

Use the `access_token` as a Bearer token on every other operation:

```bash
TOKEN="paste-access-token-here"
```

### List employees (paginated)

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"query":"{ employees(first: 25, page: 1) { paginatorInfo { total currentPage lastPage } data { id first_name last_name email salary } } }"}'
```

### Update an employee

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"query":"mutation { updateEmployee(input: { id: 1, salary: 95000, phone: \"+1-555-0100\" }) { id salary phone } }"}'
```

### Delete an employee

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"query":"mutation { deleteEmployee(id: 1) { id first_name } }"}'
```

### Import from Excel

Uses the [GraphQL multipart request spec](https://github.com/jaydenseric/graphql-multipart-request-spec).
A ready-made sample file is at `storage/samples/employees_sample.xlsx`.

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Authorization: Bearer $TOKEN" \
  -F operations='{"query":"mutation ($file: Upload!) { importEmployees(file: $file) { message queued import_id } }","variables":{"file":null}}' \
  -F map='{"0":["variables.file"]}' \
  -F 0=@storage/samples/employees_sample.xlsx
```

Make sure `php artisan queue:work` is running — the import is processed in the
background, 1,000 rows per queued job. Rows are keyed **by email**: existing
emails are updated and new emails are inserted; invalid rows are logged and
skipped. The mutation returns an `import_id` you can poll with the
`importStatus` query to track progress.

### Export all employees to Excel

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/employees/export -o employees.xlsx
```

Returns an `.xlsx` with all employees (header + 10,000 rows), in the same column
order as the import format.

---

## How scale is handled

- **Seeding**: `EmployeeSeeder` builds rows in PHP and inserts them with
  `DB::table('employees')->insert($chunk)` in batches of 1,000 — not 10,000
  individual model saves. ~0.5–1s for 10k rows.
- **Import**: `EmployeesImport` uses `WithChunkReading` (1,000) + `ShouldQueue`,
  so a 10k-row file fans out into 10 queued jobs and is never fully loaded into
  memory. Each chunk applies **one** batched `upsert` keyed on email (update
  existing, insert new).
- **Export**: `EmployeesExport` implements `FromQuery`, so maatwebsite iterates
  the query in chunks instead of loading all 10k models into a collection.
- **List**: the `employees` query is paginated (`@paginate`, default 25, max
  100 per page).

---

## Project map

| Path | Purpose |
|---|---|
| `graphql/schema.graphql` | GraphQL schema (types, queries, mutations) |
| `app/GraphQL/Mutations/Login.php` | Issues a Passport token in-process |
| `app/GraphQL/Mutations/ImportEmployees.php` | Stores upload + queues import |
| `app/GraphQL/Validators/UpdateEmployeeInputValidator.php` | Update validation |
| `app/Imports/EmployeesImport.php` | Queued chunked upsert by email |
| `app/Exports/EmployeesExport.php` | Streamed `FromQuery` export |
| `app/Http/Controllers/ExportEmployeesController.php` | Export download route |
| `database/seeders/` | Passport client, admin, 10k employees |
| `storage/samples/employees_sample.xlsx` | Sample import file |
| `docs/API.md` | Full per-operation API reference |
| `postman/Employee-API.postman_collection.json` | Postman collection for the whole API |

Full API reference with every argument, validation rule, and example
request/response: **[docs/API.md](docs/API.md)**.

## Default credentials

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `password` |
