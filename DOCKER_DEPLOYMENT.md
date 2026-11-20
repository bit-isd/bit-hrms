# Docker Deployment

The repository-root `Dockerfile` builds the React frontend and Laravel API into one image. The container listens on `PORT` (default `8080`) and serves both the UI and `/api/v1` from the same origin. PostgreSQL is external; Redis is not required.

## Build and Run

Create a local `.env` using `.env.example`, then set a unique `APP_KEY` and your database connection values. Generate an application key with:

```sh
php artisan key:generate --show
```

Build and run the image:

```sh
docker build -t bit-hrms .
docker run --rm -p 8080:8080 --env-file .env -e PORT=8080 bit-hrms
```

Apply database migrations once before using the app:

```sh
docker run --rm --env-file .env bit-hrms php artisan migrate --force
```

The health endpoint is `/up`.

## Required Runtime Configuration

Set these as environment variables on your container platform. Never commit real credentials or `.env` files.

| Variable | Example / purpose |
| --- | --- |
| `APP_KEY` | Generated Laravel key (`base64:...`) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Public HTTPS URL of the deployed service |
| `DB_CONNECTION` | `pgsql` |
| `DATABASE_URL` | Optional PostgreSQL connection URI; when set, it overrides corresponding `DB_*` connection fields |
| `DB_HOST` | External PostgreSQL hostname |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | Database name |
| `DB_USERNAME` | Database user |
| `DB_PASSWORD` | Database password |
| `DB_SSLMODE` | `require` for Supabase; use the provider's required setting |
| `CACHE_STORE` | `file` (default for this container) |
| `SESSION_DRIVER` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `PORT` | Set by many platforms; image defaults to `8080` |

The image includes both PostgreSQL and MySQL PDO drivers. PostgreSQL is the recommended production database. Use either `DATABASE_URL` or the individual `DB_*` values consistently: remove an old `DATABASE_URL` when switching to individual settings. URI query parameters can override connection settings, including SSL. URL-encode special characters in URI passwords. Never put credentials in the repository or command history.

## Render

For a regular Render Web Service, connect the Git repository, select **Docker** as the runtime/language, and use the repository-root `Dockerfile`. Leave the Docker build and start command overrides empty: the image defines both. Add the required environment variables above and set the health check path to `/up`. Render supplies `PORT`, which the container honors.

To deploy from `render.yaml`, use **New > Blueprint**. It defines one Docker web service and prompts for the app key, public URL, and external PostgreSQL credentials; it does not provision a database or Redis instance.

### Migrate a new Supabase database

Migrations create this application's tables in the database configured for Laravel. They do **not** copy users, staff, or uploads from an old database. If you need existing records, plan and test a separate database migration/restore and copy uploaded files before switching traffic. Keep a backup of the source and verify the target; do not run `migrate:fresh` on a shared Supabase project or on data you need.

1. In Supabase, open the target project and select **Connect**. For migrations, Supabase recommends a direct PostgreSQL connection if the machine running the command can reach it. Direct connections normally require IPv6 unless the project has the IPv4 add-on. If your network cannot reach it, select the shared **Session pooler**, normally port `5432`; copy its exact host and username, which includes the project reference. Avoid transaction pooling for this procedure. See [Supabase connection options](https://supabase.com/docs/guides/database/connecting-to-postgres).
2. In Render, open your web service's **Environment** settings. Set `DB_CONNECTION=pgsql` and either `DATABASE_URL` to the PostgreSQL connection URI, or `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to the matching values from Supabase. Set `DB_SSLMODE=require` (or `sslmode=require` in the URI). Use the database password, not a Supabase API key. Remove any stale `DATABASE_URL` if using `DB_*` fields. Confirm the project, host, port, database, and user point to the intended new database without exposing the password.
3. Save the settings and deploy the intended application version with the updated environment. If the service is already running, a restart alone may retain the old environment; use a deploy that incorporates the changes. Keep traffic away from the new database until migrations finish.
4. Run `php artisan migrate --force` **once** using one of the methods below. Wait for successful completion; a health check alone does not prove that the database schema is ready. `--force` only bypasses the production confirmation and does not erase tables.
5. Verify by running `php artisan migrate:status` in the same environment. All expected migrations should show as run. In Supabase, check the `public` schema for the application's tables and the `migrations` table. A new database has no application users or role/reference data until they are provisioned. The repository has no documented production admin bootstrap; arrange a secure provisioning process before opening the app to users. After that, test the app and logs. Do not run the default demo seeders in production.

**Option A: Render paid service.** In the service's **Settings**, set the **Pre-Deploy Command** to `php artisan migrate --force` and deploy. Render runs it after building and before activating the new version. Check the deploy logs for success. For verification, use the service's Shell if available and run `php artisan migrate:status`. Pre-deploy commands are available on paid web services, not the free plan in this repository's `render.yaml`. See [Render deploy steps](https://render.com/docs/deploys#pre-deploy-command). For later deployments, review migrations for compatibility with the currently running version because the old instance can still receive traffic during deployment.

**Option B: local Docker, including Render Free.** This does not require shell access to Render. On a machine with Docker and network access to Supabase, check out the **same commit** deployed on Render. Create a separate private environment file, for example `.env.migrate`, containing the same `APP_KEY`, `APP_ENV=production`, `DB_CONNECTION`, and either `DATABASE_URL` or the matching `DB_*` variables used on Render. Include the same SSL settings. Use Docker's `KEY=value` env-file format: quotes and shell variable expansion are not interpreted as they are in Laravel's `.env`, so do not simply copy a quoted `.env` file. Do not copy secrets into Git or paste them into commands; restrict file access and remove the file securely when finished. Ensure no conflicting `DATABASE_URL` is present. If using a different reachable endpoint locally, it must still identify the same Supabase project and database.

```sh
docker build -t bit-hrms:migrate .
docker run --rm --env-file .env.migrate bit-hrms:migrate php artisan migrate --force
docker run --rm --env-file .env.migrate bit-hrms:migrate php artisan migrate:status
```

The image excludes `.env.migrate` via `.dockerignore`. Do not continue if the migration command fails. Check the target database, credentials, SSL, network access, and PostgreSQL permissions to create tables; retry the normal migration after resolving the error. If a migration partially applied, inspect the database and migration error before retrying or making manual changes. If this machine cannot connect to Supabase, run the same one-off container on a trusted machine that can, or use the paid Render option. Do not open database access to everyone just to run migrations.

Render also supports [one-off jobs](https://render.com/docs/one-off-jobs), which use a snapshot of a service's latest successful build and environment and are billed. Check current plan and pricing availability before using one.

### Database resets and updates

`php artisan migrate:fresh` drops all tables in the configured database and reruns the migrations; `--seed` additionally runs seeders. It is for disposable development/test databases only, not a production or shared Supabase database. The default seeders include demo users and staff and can overwrite account passwords. For subsequent releases, back up first and use `php artisan migrate --force` to apply pending migrations, not `migrate:fresh` or the default seeders. Review each migration: a normal migration can itself remove or change data. Rollback of application code does not automatically undo database changes.

## Remote Linux server

These examples assume an administrator has configured Docker access, a reverse proxy with HTTPS, a firewall, and a PostgreSQL database reachable from the server. Use the same image, application key, and database settings for the migration and web container. Store the production environment file outside the repository with restricted permissions; do not regenerate `APP_KEY` on updates. Configure persistent uploads as described below. The current image uses Laravel's development server; assess and replace it with a production-grade PHP web server for a hardened deployment.

### First deployment with a new database

1. Create an empty PostgreSQL database and a dedicated account with the permissions needed for the migrations. Provision the Linux server and set the variables in a private file such as `/opt/bit-hrms/app.env`. Set `APP_ENV=production`, `APP_DEBUG=false`, a unique persistent `APP_KEY`, the HTTPS `APP_URL`, and the database connection. Configure mail and durable uploads before relying on them.
2. Build the image on the server from the desired commit (`docker build -t bit-hrms:release .`), or deliver a prebuilt image as shown below. Before serving traffic, confirm the database target and run:

	```sh
	docker run --rm --env-file /opt/bit-hrms/app.env bit-hrms:release php artisan migrate --force
	docker run --rm --env-file /opt/bit-hrms/app.env bit-hrms:release php artisan migrate:status
	```

3. Create a persistent upload volume and make it writable by the image's `www-data` user, then start the container bound to the local reverse proxy:

	```sh
	docker volume create bit-hrms-uploads
	docker run --rm --user root --entrypoint sh -v bit-hrms-uploads:/uploads bit-hrms:release -c 'chown www-data:www-data /uploads'
	docker run -d --name bit-hrms --restart unless-stopped -p 127.0.0.1:8080:8080 --env-file /opt/bit-hrms/app.env -v bit-hrms-uploads:/var/www/html/storage/app/public bit-hrms:release
	```

4. Point the HTTPS reverse proxy to `127.0.0.1:8080`, check `/up`, inspect `docker logs bit-hrms`, and test the application and database access. Provision real accounts securely; do not expose the demo credentials.

### Updating an existing deployment

Back up and test restoring the database and uploads first. Build or deliver a new, distinctly tagged image from the intended commit. Review pending migrations and compatibility with the running version, schedule downtime if necessary, and run migrations **once** using the new image and the existing production environment. Verify migration status, then replace the web container with the new image while retaining its environment file and upload volume. Stop and remove only the old container, not the volume; use the `docker run` command above with the new tag. Check logs, `/up`, login, and critical workflows. Keep the prior image available for application rollback, but plan database recovery separately because rolling back an image does not reverse migrations. Coordinate migrations across instances and never run destructive resets during an update.

### Server cannot build the image

Build on a trusted machine or CI runner with Docker and the same checkout; select an image architecture compatible with the Linux host. Transfer the resulting image through a private registry or, for a host with SSH access, as an archive:

```sh
# On the build machine, from the repository root
docker build -t bit-hrms:release .
docker save -o bit-hrms-release.tar bit-hrms:release
scp bit-hrms-release.tar deploy@server:/tmp/

# On the Linux server
docker load -i /tmp/bit-hrms-release.tar
```

Then use the first-deployment or update steps above. The archive contains the app and built frontend, not the runtime environment or database. Protect the archive and transfer production secrets separately. This route still requires permission to load and run Docker images on the server, or an administrator to do so; Docker access is effectively privileged. If neither Docker nor a registry pull is allowed, ask the administrator to deploy a compatible build artifact and configure PHP and the web server; a frontend build alone is insufficient because Laravel and its dependencies must also be deployed.

## Password Recovery

Quick Access Testing Roles is disabled by `QUICK_ACCESS_ENABLED = false` in `src/components/LoginPortal.jsx`; the demo code remains available in source. Existing usernames and passwords are unchanged. Do not rerun user seeders when deploying this change, since they can overwrite passwords.

Run `php artisan migrate --force` against the deployed database to create `password_reset_tokens`. This migration does not modify existing accounts.

Configure these runtime variables to deliver password reset links:

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Public HTTPS frontend URL, used in reset links |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | SMTP provider hostname |
| `MAIL_PORT` | Provider's supported SMTP port |
| `MAIL_SCHEME` | `smtp` for STARTTLS, or `smtps` for implicit TLS |
| `MAIL_USERNAME` | SMTP username |
| `MAIL_PASSWORD` | SMTP password, stored only as a runtime secret |
| `MAIL_FROM_ADDRESS` | Verified sender address |
| `MAIL_FROM_NAME` | `BIT-HRMS` |

On Render free services, standard SMTP ports may be blocked. Use a provider supporting port 2525 if available, or configure a supported HTTPS mail transport instead. The default `log` mailer does not deliver email and must not be used for production recovery. If configuration is cached, rebuild the cache after changing runtime settings.

Users select **Forgot password?**, enter the email registered on their account, and follow the emailed link. Reset tokens expire after 60 minutes and can only be used once. Successful resets revoke existing API tokens. Accounts must be active; response messages do not disclose whether an email exists. Seeded placeholder addresses must be replaced with real, reachable account addresses before recovery can be used for those accounts.

Run the isolated recovery tests with `php vendor/bin/phpunit tests/Feature/PasswordRecoveryTest.php`. They use SQLite in memory, not the configured production database.

## Persistent Uploads

Staff documents are stored on Laravel's local `public` disk at `storage/app/public`. Container filesystems are commonly ephemeral. Attach a persistent volume at `/var/www/html/storage/app/public` or configure durable object storage before relying on uploaded documents in production. This is separate from the external database, which stores document metadata, not file contents.

## Other Docker Platforms

Any platform that builds a repository-root Dockerfile can use the same image. Configure its web process to route traffic to the container's `PORT`, provide the runtime variables above, and run migrations as a release/pre-deploy task. For local persistence, mount the upload volume path described above.
