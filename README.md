# WalangBrownout Appliances — Laravel API

Laravel 12 REST API backend for the existing React/Vite WalangBrownout Appliances interface. This is a **separate repository**; the frontend repository is not changed.

## Included

- Laravel 12, PHP 8.2+, SQLite by default, and Laravel Sanctum personal-access tokens.
- Customer registration/login, authenticated profile, logout, and role-aware access checks.
- Product catalogue and server-managed prices/stock.
- Orders with transactional stock reservation, FIFO batch depletion, order history, cancellation, and staff status updates.
- Warehouse opening stock, batch receiving, FIFO picks, cycle counts, discrepancy investigation/resolution, and movement audit trail.
- Reorder alerts, purchase orders, receiving, and live management summary reports.
- Seed data matching the frontend's four sample products and local demo accounts.

## Quick start

```bash
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

The API will be available at `http://127.0.0.1:8000/api/v1`. Health check:

```bash
curl http://127.0.0.1:8000/api/v1/health
```

The sample manager/staff/customer accounts are only seeded when the application environment is `local` or `testing`:

| Role | Email | Password |
| --- | --- | --- |
| Manager | `manager@walangbrownout.ph` | `manager123` |
| Staff | `staff@walangbrownout.ph` | `staff123` |
| Customer | `customer@gmail.com` | `customer123` |

**Do not use those demo credentials in production.** Production `APP_ENV=production` skips the demo account seeder. Public registration always assigns `customer`; staff, manager, and admin accounts must be provisioned by an authorized operator.

## Authentication

Send `Accept: application/json` and use the bearer token returned by register/login:

```http
Authorization: Bearer <token>
```

- `POST /auth/register` — `name`, `email`, `password`; optional `role` accepts only `customer`.
- `POST /auth/login` — `email`, `password`.
- `GET /auth/me` — authenticated account.
- `POST /auth/logout` — revokes the current token.

Successful responses use a `{ "data": ... }` envelope. Laravel validation errors return HTTP 422 with an `errors` object. Unauthenticated requests return 401; insufficient role returns 403.

## API routes

All paths below are under `/api/v1`.

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/health` | Public | API health check |
| GET | `/products` | Public | Browse; supports `q`, `category`, `in_stock=true` |
| GET | `/products/{identifier}` | Public | Look up by SKU or slug |
| POST | `/products` | Staff/manager/admin | Register SKU and optional opening stock |
| PATCH/DELETE | `/products/{product}` | Staff/manager/admin | Edit/archive product |
| GET/POST | `/orders` | Authenticated | List own orders/create order |
| GET | `/orders/{order}` | Owner or staff | Order details |
| POST | `/orders/{order}/cancel` | Owner or staff | Cancel eligible order and restore stock |
| PATCH | `/orders/{order}/status` | Staff/manager/admin | Advance order to Processing, In Transit, or Delivered |
| GET | `/warehouse/batches` | Staff/manager/admin | List batches; optional `sku` filter |
| POST | `/warehouse/receipts` | Staff/manager/admin | Receive a batch into inventory |
| POST | `/warehouse/picks` | Staff/manager/admin | Pick stock using FIFO rules |
| POST | `/warehouse/counts` | Staff/manager/admin | Submit a cycle count; differences over 2 are flagged |
| GET | `/warehouse/discrepancies` | Staff/manager/admin | List cycle-count discrepancies |
| POST | `/warehouse/discrepancies/{id}/resolve` | Manager/admin | Resolve; optional `apply_counted_stock: true` |
| GET | `/purchasing/reorder-alerts` | Staff/manager/admin | Current stock versus reorder point |
| GET/POST | `/purchasing/orders` | Staff/manager/admin | List/create purchase orders |
| POST | `/purchasing/orders/{id}/receive` | Staff/manager/admin | Receive a pending PO into stock |
| GET | `/reports/summary` | Manager/admin | Live stock, sales, PO and discrepancy metrics |
| GET | `/reports/inventory-movements` | Manager/admin | Recent stock audit trail |

### Example order

```json
{
  "items": [{ "productId": "ac-12k", "qty": 1 }],
  "payment": "Cash on Delivery"
}
```

An item identifier may be its product `sku`, slug (`productId`), or database `product_id`. The server reads the current product price and stock; any client-supplied `price` or `name` is ignored. Supported payment methods: `Cash on Delivery`, `GCash`, and `Card`.

### Example warehouse receipt

```json
{
  "sku": "Filter-Carbon-9m",
  "qty": 12,
  "expiry": "2027-06-01",
  "bin": "C-14-2"
}
```

## Frontend integration

The current frontend uses `localStorage` and synchronous auth/order helpers. This backend exposes the API but intentionally does **not** alter that repository. To connect it later, set a frontend API base URL (for example `VITE_API_BASE_URL=http://localhost:8000/api/v1`) and replace those helpers with `fetch`/Axios calls; persist the returned bearer token securely and send it for protected routes. The API returns frontend-friendly product and order properties (`available`, `price`, `productId`, `qty`, `payment`, `createdAt`) alongside normalized database fields.

Set `FRONTEND_URL` to the deployed React origin if you choose to tighten CORS for production. Use HTTPS, a production database, strong unique secrets, restricted CORS origins, and provision privileged accounts through a trusted process before deployment.

## Tests

```bash
php artisan test
```
