# Bureau of Information Technology Human Resource Management System (BIT-HRMS)

## Master Production Documentation

Welcome to the official developer and operational documentation for the **Bureau of Information Technology Human Resource Management System (BIT-HRMS)** developed for the **Ogun State Government, Nigeria**.

---

## 📚 Documentation Index

1. [Architecture & System Design](ARCHITECTURE.md)
2. [Database ERD & Schema Specification](DATABASE.md)
3. [RESTful API Gateway Documentation](API.md)
4. [Cybersecurity & Data Privacy (NDPC) Compliance](SECURITY.md)
5. [Role-Based Access Control (RBAC) Matrix](RBAC.md)
6. [Docker and Render Deployment Guide](../DOCKER_DEPLOYMENT.md)
7. [Nominal Roll Data Import Template](IMPORT_TEMPLATE.md)
8. [Developer Setup & Workflow Guide](DEVELOPMENT.md)
9. [Future Modules Architectural Roadmap](FUTURE_MODULES.md)

---

## Local Setup and Build

Install PHP 8.2 or newer, Composer 2, Node.js 22 or newer, npm, and a local PostgreSQL or MySQL server. The example environment is configured for PostgreSQL.

```bash
# From the repository root
cp .env.example .env
composer install
npm ci
```

Edit `.env` with the local database name, user, and password, and create that database before running migrations. Generate a local application key and apply pending migrations:

```bash
php artisan key:generate
php artisan migrate
php artisan storage:link
```

Build the production frontend assets into `public/build`:

```bash
npm run build
```

Run the app locally with two terminals:

```bash
php artisan serve
```

```bash
npm run dev
```

Open `http://localhost:5173/build/`. To test the compiled frontend instead, stop the Vite development server and open `http://localhost:8000` after building. Run the backend feature tests with `php vendor/bin/phpunit tests/Feature`.

Do not use `php artisan migrate:fresh` on a database containing data; it drops and recreates tables.

## Deploy the Built App to a Linux Server

These steps use Git Bash or WSL on Windows and SSH access to a Linux server. They transfer the Laravel source and the locally built React assets. PHP dependencies are installed on the server for its runtime. The archive intentionally excludes local secrets, dependencies, caches, and uploaded files; an existing server `.env` and `storage` directory are kept in place.

### Build and Package Locally

Stop `npm run dev` before preparing a release so `public/hot` is not included. From the repository root:

```bash
npm ci
npm run build

tar -czf ../bit-hrms-release.tar.gz \
--exclude='./.git' \
--exclude='./.env' \
--exclude='./node_modules' \
--exclude='./vendor' \
--exclude='./storage/app/public' \
--exclude='./storage/framework' \
--exclude='./storage/logs' \
--exclude='./bootstrap/cache' \
--exclude='./public/storage' \
--exclude='./public/hot' \
--exclude='./.phpunit.result.cache' \
.
```

The archive includes `public/build`, `composer.json`, and `composer.lock`. Keep `.env`, existing uploads, and runtime storage on the server; do not copy local credentials or local uploaded documents into the release.

### Upload and Install on the Server

Replace the example account, host, and paths with the values for your server:

```bash
scp ../bit-hrms-release.tar.gz deploy@YOUR_SERVER:/tmp/
ssh deploy@YOUR_SERVER
```

On the server, extract the release into the application directory. For example:

```bash
APP_DIR=/var/www/bit-hrms
mkdir -p "$APP_DIR"
tar -xzf /tmp/bit-hrms-release.tar.gz -C "$APP_DIR"
cd "$APP_DIR"
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
```

On the first deployment only, create `.env` from the example and configure production values. For later deployments, keep the existing `.env` unchanged unless intentionally updating configuration:

```bash
cp .env.example .env
nano .env
```

Set at least `APP_ENV=production`, `APP_DEBUG=false`, the public HTTPS `APP_URL`, and the production database connection values. Generate `APP_KEY` once with `php artisan key:generate`; do not regenerate it on an existing installation because that can invalidate encrypted data and sessions. Store `.env` outside public web access and never upload or commit it.

Install PHP dependencies and run the deployment commands:

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
[ -L public/storage ] || php artisan storage:link
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

Back up the production database before applying migrations. `migrate --force` applies pending migrations without dropping existing tables; never use `migrate:fresh` on production. Ensure the PHP web-server user can write to `storage` and `bootstrap/cache`, and preserve `storage/app/public` between releases so uploaded personnel documents remain available.

Configure Apache or Nginx to serve the application's `public` directory, not the repository root, and enable HTTPS. After deployment, check `https://YOUR_DOMAIN/up`, sign in, and verify that an uploaded document opens from the same domain.

For container-based deployment, see the [Docker and Render guide](../DOCKER_DEPLOYMENT.md).
