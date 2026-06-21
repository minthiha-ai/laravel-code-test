# API Reference

All GraphQL operations are served from a single endpoint:

```
POST /graphql
```

The Excel export is a REST download:

```
GET /api/employees/export
```

## Authentication

Every operation except `login` requires a Passport access token, sent as a
Bearer token:

```
Authorization: Bearer <access_token>
```

Obtain a token with the [`login`](#mutation-login) mutation. Unauthenticated
calls to guarded operations return an `Unauthenticated.` error.

## Types

### Employee

| Field | Type | Notes |
|---|---|---|
| `id` | `ID!` | Primary key |
| `first_name` | `String!` | |
| `last_name` | `String!` | |
| `email` | `String!` | Unique |
| `phone` | `String!` | |
| `address` | `String!` | |
| `salary` | `Float!` | |
| `created_at` | `DateTime!` | |
| `updated_at` | `DateTime!` | |

### User

| Field | Type |
|---|---|
| `id` | `ID!` |
| `name` | `String!` |
| `username` | `String!` |
| `email` | `String!` |
| `created_at` | `DateTime!` |
| `updated_at` | `DateTime!` |

---

## Queries

### `me`

Returns the currently authenticated user. **Auth required.**

```graphql
query { me { id name username email } }
```

```json
{ "data": { "me": { "id": "1", "name": "Administrator", "username": "admin", "email": "admin@example.com" } } }
```

---

### `employees`

Paginated list of employees. **Auth required.**

| Argument | Type | Default | Notes |
|---|---|---|---|
| `first` | `Int` | 25 | Page size (max **100**) |
| `page` | `Int` | 1 | Page number |

```graphql
query {
  employees(first: 25, page: 1) {
    paginatorInfo { total currentPage lastPage perPage hasMorePages }
    data { id first_name last_name email phone address salary }
  }
}
```

```json
{
  "data": {
    "employees": {
      "paginatorInfo": { "total": 10000, "currentPage": 1, "lastPage": 400, "perPage": 25, "hasMorePages": true },
      "data": [ { "id": "1", "first_name": "Mathew", "last_name": "Quigley", "email": "mathew.quigley.1@example.com", "phone": "+1-787-703-2019", "address": "46491 Bennie Court, Port Boyd, WY 34036-7402", "salary": 168841 } ]
    }
  }
}
```

---

### `employee`

Fetch a single employee by id. **Auth required.** Returns `null` if not found.

| Argument | Type | Required |
|---|---|---|
| `id` | `ID!` | yes |

```graphql
query { employee(id: 1) { id first_name last_name email phone address salary } }
```

```json
{ "data": { "employee": { "id": "1", "first_name": "Mathew", "last_name": "Quigley", "email": "mathew.quigley.1@example.com", "phone": "+1-787-703-2019", "address": "46491 Bennie Court, Port Boyd, WY 34036-7402", "salary": 168841 } } }
```

---

## Mutations

### <a id="mutation-login"></a>`login`

Exchange a username and password for a Passport access token. **No auth
required.**

| Argument | Type | Required |
|---|---|---|
| `username` | `String!` | yes |
| `password` | `String!` | yes |

Returns `AuthPayload`: `access_token`, `token_type`, `expires_in`,
`refresh_token`.

```graphql
mutation {
  login(username: "admin", password: "admin") {
    access_token
    token_type
    expires_in
    refresh_token
  }
}
```

```json
{ "data": { "login": { "access_token": "eyJ0eXAiOiJKV1Qi...", "token_type": "Bearer", "expires_in": 31536000, "refresh_token": "def50200..." } } }
```

**Invalid credentials** return a validation error:

```json
{ "errors": [ { "message": "The provided credentials are incorrect.", "extensions": { "validation": { "username": ["The provided credentials are incorrect."] } } } ] }
```

---

### `updateEmployee`

Update an existing employee. **Auth required.** Takes an `UpdateEmployeeInput`.
Every field except `id` is optional — send only what you want to change.

| Input field | Type | Required | Validation |
|---|---|---|---|
| `id` | `ID!` | yes | must exist in `employees` |
| `first_name` | `String` | no | string, max 255 |
| `last_name` | `String` | no | string, max 255 |
| `email` | `String` | no | valid email, **unique** (ignores this employee), max 255 |
| `phone` | `String` | no | string, max 255 |
| `address` | `String` | no | string, max 255 |
| `salary` | `Float` | no | numeric, ≥ 0 |

```graphql
mutation {
  updateEmployee(input: { id: 1, salary: 95000, phone: "+1-555-0100" }) {
    id salary phone
  }
}
```

```json
{ "data": { "updateEmployee": { "id": "1", "salary": 95000, "phone": "+1-555-0100" } } }
```

**Duplicate email** returns:

```json
{ "errors": [ { "message": "Validation failed for the field [updateEmployee].", "extensions": { "validation": { "input.email": ["The input.email has already been taken."] } } } ] }
```

---

### `deleteEmployee`

Delete an employee by id and return the deleted record. **Auth required.**

| Argument | Type | Required |
|---|---|---|
| `id` | `ID!` | yes |

```graphql
mutation { deleteEmployee(id: 1) { id first_name email } }
```

```json
{ "data": { "deleteEmployee": { "id": "1", "first_name": "Mathew", "email": "mathew.quigley.1@example.com" } } }
```

---

### `importEmployees`

Bulk-update employees from an uploaded Excel/CSV file. **Auth required.**
Returns immediately; the file is processed in the background by the queue
worker (`php artisan queue:work`).

| Argument | Type | Validation |
|---|---|---|
| `file` | `Upload!` | required, file, max 50 MB |

**Matching:** each row is matched to an existing employee **by `email`**.
- Matched rows are **updated** (`first_name`, `last_name`, `phone`, `address`,
  `salary`).
- Rows whose email does **not** match any employee are **skipped** (never
  inserted).
- Rows failing per-row validation are **skipped** and logged.

**Expected columns** (header row, exact names):

```
first_name | last_name | email | phone | address | salary
```

A ready-made example is at `storage/samples/employees_sample.xlsx`
(25 valid updates + 1 unmatched row + 1 invalid row).

Uploads use the
[GraphQL multipart request spec](https://github.com/jaydenseric/graphql-multipart-request-spec):

```bash
curl -s -X POST http://127.0.0.1:8000/graphql \
  -H "Authorization: Bearer $TOKEN" \
  -F operations='{"query":"mutation ($file: Upload!) { importEmployees(file: $file) { message queued } }","variables":{"file":null}}' \
  -F map='{"0":["variables.file"]}' \
  -F 0=@storage/samples/employees_sample.xlsx
```

```json
{ "data": { "importEmployees": { "message": "Import accepted. Employees are being bulk-updated in the background.", "queued": true } } }
```

Progress is logged to `storage/logs/laravel.log`, e.g.
`EmployeesImport chunk processed {"updated":25,"skipped":1}`.

---

## REST: export employees

### `GET /api/employees/export`

Stream all employees as an `.xlsx` download. **Auth required** (`auth:api`).
Implemented as REST because GraphQL cannot return a binary file body.

| | |
|---|---|
| Method | `GET` |
| Auth | `Authorization: Bearer <token>` |
| Response | `200` `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |
| Filename | `employees.xlsx` |
| Columns | `first_name, last_name, email, phone, address, salary` (header + one row per employee) |

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/employees/export -o employees.xlsx
```

Without a valid token the endpoint returns `401`:

```json
{ "message": "Unauthenticated." }
```

---

## Error format

GraphQL errors come back in the standard `errors` array. Validation errors
include an `extensions.validation` map keyed by field. With `APP_DEBUG=true`,
responses also include `extensions.file`, `line`, and `trace` for debugging;
set `APP_DEBUG=false` to suppress those.
