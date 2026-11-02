# Food Orders

A dependency-free food order management application built with PHP, PDO SQLite, HTML, CSS and vanilla JavaScript. Source code, database objects and interface text are in English.

## Start with WAMP

1. Place the application in `C:\wamp64\www\narudzbe`.
2. Start WAMP and make sure Apache is running.
3. Enable `pdo_sqlite`, `sqlite3` and `mbstring` for the PHP version used by Apache. PHP 8.1 or later is required. No Composer or Node installation is required to run the application.
4. Open `http://localhost/narudzbe/`.
5. Create the first administrator account. There is no predefined account or password. The name is the login identifier and must be unique, ignoring ASCII case. Passwords must be 10–72 bytes.

The database is created automatically at `storage/food_orders.sqlite`. Session files are stored in `storage/sessions`, independently of WAMP's global temporary folder. Apache must be able to write to the storage directory. SQLite does not use MySQL or phpMyAdmin. The timezone is `Europe/Zagreb`; prices are in KM. No sample data is added.

Apache must honor the included `.htaccess` files (`AllowOverride All` or equivalent for the used directives, with Apache 2.4 authorization enabled). They deny access to the database, application internals, tests and documentation. Confirm that `http://localhost/narudzbe/storage/food_orders.sqlite` and `/app/schema.sql` return 403, never the file contents, before allowing other machines to access the application. Do not put database backup copies in the public directory. Use HTTPS when accessing the application over a network.

### Optional local development server

From the application directory:

```powershell
php -S 127.0.0.1:8085 router.php
```

Open `http://127.0.0.1:8085/`. The supplied router restricts public paths; use it because PHP's built-in server does not process `.htaccess`. This server is for local development.

## Accounts and permissions

The first account is an administrator. Administrators create other accounts in **Users** and can list, create, edit and delete all orders. Normal users can manage only their own orders. All signed-in users can maintain the shared supplier directory and supplier items. User administration is restricted to administrators.

An administrator can reset a user password using **Users → Reset password**, entering and confirming the new password. The reset invalidates that user's other signed-in sessions on their next request. An administrator resetting their own password remains signed in. Passwords can also be changed by editing an account; leaving that field blank preserves its existing hash. The final administrator cannot be demoted, and a signed-in account cannot delete itself. Referenced users cannot be deleted until their orders are removed or reassigned by an administrator. Public registration creates regular User accounts only; email password reset is not implemented.

Supplier passwords are optional and stored as hashes, ready for a later supplier authentication feature. **Supplier sign-in is not implemented.** Supplier records are not user accounts. The supplier login identifier and its authorization model can be added in the next phase.

## Public user registration

Registration link: **http://localhost/narudzbe/register.php**

Users can also click **Register** on the sign-in screen. Enter a unique name, optional phone, password and matching confirmation. Successful registration signs the new user in automatically. The server always assigns the `user` role, ignoring submitted roles; administrators are created only through initial setup or administrator-managed user creation. Initial administrator setup must be completed before public registration is available.

## Everyday use

1. Add a supplier with name, phone and optional comment.
2. Open **Supplier items**, choose that supplier and add menu items with name, description and price.
3. Open **Orders → New order**. Choose a supplier, date, status and optional notes. Administrators can select the account the order belongs to.
4. Select a menu item, optionally enter its **Description** beside the item picker, and click **Add to order**. The description is copied into that order line and cleared for the next item. Edit its quantity and line notes, or remove the line. An order requires 1–100 lines; each quantity must be an integer from 1 to 9999.
5. If an item is missing, click **New supplier item**. The dialog creates it for the selected supplier and immediately adds it to the current order. Existing unsaved order fields remain in place. The catalog item is saved immediately, even if the order is later abandoned.
6. Save the order. Reopen it to view or update its details and lines. Deleting an order also deletes all its lines.

Changing suppliers in the editor requires confirmation and clears its existing lines. A menu item already used in an order cannot move to another supplier. Items and suppliers referenced by orders are protected against deletion.

Prices are displayed in KM (BAM). Amounts use integer minor units (100 fening = 1 KM) to avoid floating-point rounding in storage; database column names retain the `_cents` suffix. Every order line snapshots its unit price when added. Changes to catalog prices do not change saved line prices. To apply a new catalog price to an existing order line, remove that line and add the item again. Names and catalog descriptions are current catalog values; they are not historical snapshots. Notes are saved per order and per line. No tax, discounts or delivery charges are calculated.

### Order statuses

| Stored value | Meaning |
| --- | --- |
| `active` | Draft / active order |
| `inactive` | Paused or inactive order |
| `ordered` | Marked as placed with the supplier |
| `delivered` | Marked as delivered |

All four statuses are editable. Statuses are tracking labels; saving or changing a status does not send messages, place an external order or notify a supplier.

The overview shows order counts and recent orders. The orders list supports text search, status filtering and pagination (25 rows per page). Normal users see only their own orders and counts. The supplier, item and user directories show all their records.

### Supplier order summary and printing

Use **Orders → View** or **View order** in the order editor to open a dedicated order summary. The supplier's name and phone number appear at the top, followed by the order date, account name and total quantity. The table has one row per order line with **Item name**, **Quantity** and **Description** (the line note). The order's general notes appear below the table. Blank line descriptions appear as a dash. **Edit order** returns to the editor.

**Compact view** switches to a one-column list with one line per item, for example `Hamburger x2 Salad mayonnaise`. **Table view** returns to the three-column layout. The order date appears beside the order identity and is included in printing. Both layouts retain the supplier name, phone and order notes.

**Print order** opens the browser print dialog; choose a printer or Save as PDF. The print layout removes navigation and controls and keeps the supplier header and item list. This view uses the same order ownership permissions as the editor.

## Database

Schema: `app/schema.sql`. Foreign keys are enabled on every connection. Tables are created if missing, without resetting existing data. Later schema changes will need explicit migrations; this initial version does not provide a migration runner.

| Table | Columns |
| --- | --- |
| `users` | `id`, `name`, `password_hash`, `phone`, `role`, `created_at` |
| `suppliers` | `id`, `name`, `phone`, `comment`, `password_hash` (nullable) |
| `supplier_items` | `id`, `supplier_id`, `name`, `description`, `price_cents` |
| `orders` | `id`, `order_date`, `status`, `user_id`, `supplier_id`, `description`, `version` |
| `order_items` | `id`, `order_id`, `supplier_item_id`, `quantity`, `description`, `unit_price_cents` |
| `login_attempts` | `address`, `attempts`, `window_start` (internal login throttling) |

`role` adds administrator/user access control. `version` detects concurrent changes to an order. Order headers and lines are saved in a transaction; a stale editor must reload before saving. Supplier items must belong to the order's selected supplier, validated by the application. Foreign keys prevent deleting referenced suppliers, items and users; deleting an order cascades to its lines.

`order_date` is a local date/time (`YYYY-MM-DDTHH:MM`) for Zagreb, without a stored UTC offset. `created_at` uses SQLite's UTC timestamp. Passwords use `password_hash()` and `password_verify()`; raw passwords are never stored or displayed. The requested password fields are therefore represented by `password_hash`.

## Source layout

```text
index.php             Request entry point, authentication and layout
register.php          Public registration entry point (regular users only)
router.php            Safe local development server router
app/bootstrap.php     PDO connection, sessions and shared helpers
app/schema.sql        Initial database tables, constraints and indexes
app/actions.php       Validated POST actions and transactions
app/views.php         Overview, lists and directory CRUD forms
app/order-view.php    Order editor and quick item dialog
app/order-summary.php Supplier header and printable item list
app/password-reset.php Administrator password reset form
assets/app.css        Responsive interface styles
assets/app.js         Order editor, dialog and confirmations
storage/              Runtime SQLite file, protected from HTTP access
tests/smoke.mjs       Isolated HTTP integration checks
```

Every write requires a POST request with a session CSRF token. Database values use prepared statements; output is HTML escaped and JavaScript inserts text using DOM methods. Session cookies are HttpOnly and SameSite=Lax; HTTPS connections also set Secure. Login regenerates the session identifier. Ten failed sign-ins from one IP within 15 minutes block sign-ins until that window expires. Sessions use PHP's configured lifetime. There are no third-party assets, analytics or external network calls.

## Backup and restore

Stop application writes (for example, stop Apache), copy `storage/food_orders.sqlite` to a location outside the public web root, then restart Apache. Restore with writes stopped by replacing the database with a known backup. Keep backup access restricted: it contains account hashes, phone numbers and order history. For live backups, use SQLite's backup tooling rather than copying a database while it is changing.

An optional `FOOD_ORDERS_DB` environment variable selects another SQLite file. Its parent directory is created if needed and must be writable by PHP. This is useful for keeping a production database outside the web root and for isolated testing.

## Verification

PHP syntax checks:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

For the HTTP checks, use a **new disposable database**, never your real database. In a separate PowerShell terminal:

```powershell
$env:FOOD_ORDERS_DB = Join-Path $env:TEMP ('food-orders-test-' + [guid]::NewGuid().ToString() + '.sqlite')
php -S 127.0.0.1:8085 router.php
```

Then run `node tests/smoke.mjs http://127.0.0.1:8085/`. The test refuses a database that is already initialized. It creates its own administrator, runs HTTP workflows and leaves test records only in the disposable database. Stop that test server when finished.
