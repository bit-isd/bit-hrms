# Bureau of Information Technology (BIT) - HRMS
## Ogun State Government, Nigeria

Enterprise Human Resource Management System built for the Bureau of Information Technology.

### Technology Stack
- **Backend:** Laravel 12.x RESTful API
- **Frontend:** React + Tailwind CSS
- **Database:** MySQL 8.0+ / PostgreSQL 15+

### Modules Active in Version 1
1. **Nominal Roll Engine:** Filterable, searchable active staff record.
2. **Staff Directory:** Categorized profile directory.
3. **Authentication & RBAC:** Role-based access control with granular policies.
4. **Data Import & Export:** Excel/CSV row validation & printable PDF generation.
5. **Audit Logging:** Append-only operation tracking.
6. **Executive Dashboard:** Aggregate statistical metrics.

---

## Quick Start for Development & Testing

### 1. Install Dependencies
```bash
# From the repository root; first create a database and configure its credentials below
cp .env.example .env
composer install --no-security-blocking
npm install --legacy-peer-deps
```

Edit `.env` and set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for a local MySQL or PostgreSQL database. The database must already exist and the configured account must be allowed to create tables.

### 2. Migrate & Seed Test Data
```bash
php artisan key:generate
php artisan migrate:fresh --seed
```

> `migrate:fresh` drops **all tables** in the configured database, including tables not created by this application, then reruns all migrations. `--seed` also runs the database seeders. Use this destructive reset only for a disposable local/test database when you want a clean start; check your connection first. Never use it on a database containing data you need to retain, including a shared Supabase database.

For a new database or an existing deployment, use `php artisan migrate` instead. It applies only pending migrations and preserves existing data in normal operation; review migrations and back up the database before production updates. Production commands use `php artisan migrate --force` to skip Laravel's confirmation prompt. Migrations create the schema, not a copy of existing records.

The seeders create test accounts. The Super Administrator test login is username `superadmin` (or email `superadmin@bit.ogunstate.gov.ng`) and password `password123`. These are test credentials; do not use them on a publicly accessible deployment.

For Render, Supabase, and remote Linux deployment procedures, see the [Docker Deployment guide](DOCKER_DEPLOYMENT.md).

### 3. Start Development Servers
Open two terminals at the repository root and leave each running:
```bash
php artisan serve
```

```bash
npm run dev
```

### 4. Access the Application
- **Frontend Portal:** Open http://localhost:5173 in your browser
- **BIT-HRMS API:** The API endpoints are available at http://localhost:8000/api/v1

### 5. Local Testing & Debugging
```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

Laravel application errors are written to `storage/logs/laravel.log`. The local `.env.example` uses file-backed sessions; if using the database session driver, the included sessions migration creates the required table.

### 7. Audit Log Search & Pagination
Audit entries record the actor's username and request IP address alongside action, module, timestamp, record reference, and change details. A separate User Name column shows the linked account's name, or "Not available" for an unavailable account. Search includes account names, usernames, IPs, actions, modules, and record references. Action quick filters, module/date filters, and adjustable pagination are available.

Staff Nominal Roll, Bureau Staff Registry, and Staff Directory Cards share server-side search, department/grade/status filters, cadre quick filters, and pagination across the entire matching dataset. Milestones, user accounts, import errors, personnel documents, and posting history also support search, relevant quick filters, and pagination. Filters reset pagination to the first matching page.

The Vite development proxy forwards the connecting client's IP, and Laravel trusts loopback proxies by default. Restart Vite and Laravel after changing proxy configuration. For a production reverse proxy, set `TRUSTED_PROXIES` to a comma-separated list of its actual IP addresses or CIDR ranges. The proxy must sanitize incoming forwarded headers and append the real connecting address. Do not trust all internet clients. IPs identify the network address visible to the trusted proxy; a client behind NAT or a VPN may share a public address. Old loopback audit entries cannot be reconstructed and are left unchanged.

### 8. Nominal Roll CSV Downloads
The Export CSV action downloads matching staff records, respecting the current text, department, and grade filters. CSV Template downloads the import template. Both requests use the logged-in API token and require an authenticated account; template access is shown to users with import permission.

The downloadable template uses [BIT_Nominal_Roll_Template.csv](BIT_Nominal_Roll_Template.csv) as its source. [BIT_Nominal_Roll_Sample_Data_100.csv](BIT_Nominal_Roll_Sample_Data_100.csv) contains 100 synthetic records using the current seeded department/unit codes and designations. Import it once into a development database with those lookups; importing it again reports duplicate identifiers rather than overwriting staff.

Use dates in `YYYY-MM-DD` format, employment status **codes** (`ACTIVE`, `POSTED`, etc.), and a unit belonging to the specified department. The 31 columns include OG/file/IPPIS identifiers, contact information, birthplace, state/LGA, qualification, address, confirmation/promotion/deployment dates, station and remarks. Required columns are `staff_number`, `surname`, `first_name`, `gender`, `date_of_birth`, `official_email`, `department_code`, `unit_code`, `designation`, `grade_level`, `date_of_first_appointment`, and `date_of_present_appointment`. Optional columns may be blank; step defaults to 1 and employment status defaults to `ACTIVE`. Retirement dates are calculated; staff activity defaults to Active and is changed separately by authorized administrators. Upload passport pictures/documents through the staff personnel folder, not CSV paths. Invalid rows receive row-specific errors; valid rows are imported. Malformed CSV headers or column counts reject the file before any insertion.

### Staff Activity and Account Permissions

- Staff records cannot be deleted. **Superadmin and HR Admin only** may toggle a record Active/Suspended, optionally giving a reason that is recorded in the audit log. This is separate from employment status (such as Active in Bureau or Posted).
- Suspended records stay searchable and visible, with a Suspended label. Profile, posting, document, event and confidential-folder changes are blocked by the API until reactivation. Existing soft-deleted staff are restored as suspended by the lifecycle migration; rolling it back cannot reconstruct their former archival state.
- Staff are retired automatically at age 60 or 35 years of service, whichever comes first. Retired records are highlighted red, permanently read-only, and cannot be suspended or reactivated. The system writes one `STAFF_RETIRED` audit entry (user `SYSTEM`) per retiree, naming the trigger.
- Department administrators see only their assigned department, including its disciplinary history. The demo `deptadmin` is assigned to department 4, which currently has one seeded staff member; that count is intentional, not a loading failure. HR Officers can read disciplinary history bureau-wide. Disciplinary records are read-only for HR Officers and Department Administrators; only Superadmin and HR Admin can create, edit or remove those records. Confidential ratings/files remain limited to Superadmin and HR Admin with password confirmation.
- Creating or assigning a Department Administrator requires a department. Superadmin can change that assignment in user management; changes revoke existing sessions so the new scope applies on the next login.
- Only the existing Superadmin may hold that role. Account creation/promotion to Superadmin is rejected; the account cannot be suspended or demoted. A database uniqueness guard also prevents duplicate Superadmin accounts.
- User accounts have only Active or Suspended status. Only Superadmin manages accounts. Suspension immediately revokes API tokens; the browser checks session validity every 10 seconds, as well as on API access. Reinstatement requires a new login. Passive session checks do not extend the inactivity timeout.

For an existing deployment, run `php artisan migrate --force` and deploy the rebuilt frontend. The lifecycle migration preserves staff details, converts legacy inactive user accounts to suspended, and adds the singleton guard. It stops if duplicate Superadmin accounts already exist; resolve those deliberately before retrying. Do not reseed or reset the database to apply these changes.

Retirement audit entries are also written when the dashboard or Milestones page is opened. To record them even when nobody signs in, run the Laravel scheduler (cron: `* * * * * php artisan schedule:run`), which runs `php artisan hrms:record-retirements` daily at 00:05. The command can also be run manually.

### System Configuration (Superadmin)
The **System Configuration** menu lets the Superadmin manage departments, units, designations, grade levels, posting/employment statuses, LGAs of origin, staff titles, leave types and disciplinary actions without code changes. Values already used by records cannot be deleted or have their code/value changed; deactivate them instead to hide them from new entries while existing records keep them. The system statuses `ACTIVE`, `POSTED` and `ON_LEAVE` cannot be removed or deactivated. Every change is audited.

### Frontend Build and Navigation
Tailwind CSS is compiled at build time (`tailwind.config.js`, `postcss.config.js`, `src/index.css`); no CDN is used. Run `npm run build` to produce `public/build` for deployment. Pages are addressed by URL hash (for example `/#/audit`), so refreshing keeps the current page. The Executive Dashboard is the default page after login.

### 9. Common Issues

**Login Issues:**
- Ensure `.env` file matches local settings (e.g., `DB_HOST`, `DB_DATABASE`)
- Run migrations after updating Laravel versions or application structure

**Session Expiry:**
- Switch to `database` driver in `.env` if filesystem permissions are limited
- Ensure `SESSION_SECURE_COOKIE` matches your protocol (use `false` for `http://localhost`)

**CSV download fails:**
- Sign in again if the API token has expired.
- Confirm the Apache/Nginx site routes `/api/*` requests to Laravel's `public/index.php` and that the latest frontend build and backend routes are deployed together.

---

## Support
For technical issues, consult the [Developer Setup & Workflow Guide](docs/DEVELOPMENT.md) or contact the development team.
