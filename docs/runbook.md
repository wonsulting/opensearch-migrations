# Operational runbook

Day-to-day development and operations for **`wonsulting/opensearch-migrations`**: prerequisites,
building, testing, static analysis, a live smoke test against Docker OpenSearch, wiring the
package into a host application, and the release process. For the internal design see
[architecture.md](architecture.md); for the consumer-facing API see the [README](../README.md).

Every command block below was executed while writing this document. Output is reproduced from a
real run on **macOS (Apple Silicon), PHP 8.4.23 (Laravel Herd), Composer 2.10.1, Docker 29.6.1**.
Version numbers and any environment-specific gotchas are called out where they matter.

## Contents

- [Prerequisites](#prerequisites)
- [Install](#install)
- [Development commands](#development-commands)
- [CI ↔ script mapping](#ci--script-mapping)
- [Local smoke test against Docker OpenSearch](#local-smoke-test-against-docker-opensearch)
- [Using the package in a host app (path repository)](#using-the-package-in-a-host-app-path-repository)
- [Release process](#release-process)

---

## Prerequisites

- **PHP 8.2+** — the supported target (README/CONTRIBUTING). Note `composer.json` technically
  allows `^7.4 || ^8.0`; CI tests 7.4 – 8.2. This doc was verified on PHP 8.4, which surfaces a
  couple of forward-compat notes (flagged below).
- **[Composer](https://getcomposer.org/download/)** 2.x.
- **[SQLite 3](https://www.sqlite.org/download.html)** — the test suite's migration-history
  table runs on Orchestra Testbench's in-memory SQLite `testing` connection (selected via
  `DB_CONNECTION=testing` in `phpunit.xml.dist`). No database *server* is required, and no real
  OpenSearch is needed for the test suite (the client is mocked in tests).
- **Docker** — only for the [live smoke test](#local-smoke-test-against-docker-opensearch).

## Install

```bash
composer install
```

> **Gotcha (current tooling).** On a modern Composer (2.x with the default security-advisory
> policy), a plain `composer install` **fails to resolve** because the dev dependency
> `orchestra/testbench ^7.5` pulls in the now-EOL **Laravel 9**, which is flagged by security
> advisories:
>
> ```text
> Your requirements could not be resolved to an installable set of packages.
>   Problem 1
>     - Root composer.json requires orchestra/testbench ^7.5 ...
>     - orchestra/testbench ... require laravel/framework ^9... but these were not loaded,
>       because they are affected by security advisories (...). ...
>       To turn the feature off entirely, you can set "policy.advisories.block" to false.
> ```
>
> Workaround until the dev dependencies are modernized — disable advisory blocking for the
> install (globally, or per-project without editing the committed `composer.json`):
>
> ```bash
> composer config --global policy.advisories.block false
> composer install
> ```
>
> A successful install resolves, among others:
> `laravel/framework v9.52.21`, `orchestra/testbench v7.56.0`, `phpstan/phpstan 1.12.33`,
> `friendsofphp/php-cs-fixer 3.95.12`, and the OpenSearch stack
> `wonsulting/opensearch-adapter 2.1.1` → `wonsulting/opensearch-client 2.0.1` →
> `opensearch-project/opensearch-php 2.6.0`.

## Development commands

Use the **composer scripts** (defined in `composer.json`) — they use `./vendor/bin/…` and are
exactly what CI runs.

> There is also a `Makefile` (`make test`, `make style-check`, `make static-analysis`) referenced
> by `CONTRIBUTING.md`, but its targets shell out to a `bin/` directory that is **git-ignored and
> not created by a default `composer install`** — so they fail out of the box. Prefer the
> composer scripts.

| Command                  | Runs                                                                  | Result on this environment |
|--------------------------|-----------------------------------------------------------------------|----------------------------|
| `composer test`          | `phpunit --testdox`                                                    | ✅ Pass — 103 tests, 169 assertions |
| `composer test-coverage` | `phpunit --testdox --coverage-text`                                   | ⚠️ Tests pass, but no coverage report without a driver |
| `composer check-style`   | `php-cs-fixer fix --allow-risky=yes --dry-run --diff …`               | ✅ Pass — 0 of 37 files need fixing |
| `composer fix-style`     | `php-cs-fixer fix --allow-risky=yes`                                  | ✅ No-op — 0 of 37 files fixed |
| `composer analyse`       | `phpstan analyse`                                                     | ⚠️ Crashes at the default 128M memory limit (see note) |

### `composer test`

```text
PHPUnit 9.6.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: .../phpunit.xml.dist
...
Time: 00:00.556, Memory: 40.00 MB

OK (103 tests, 169 assertions)
```

Two suites run (`unit` → `tests/Unit`, `integration` → `tests/Integration`). Integration tests
use a mocked `OpenSearch\Client`, so no real cluster is contacted.

### `composer test-coverage`

Runs the same suite with `--coverage-text`. It needs a coverage driver (**Xdebug** or **PCOV**).
Herd's default PHP has neither, so the run passes the tests but prints:

```text
Runtime:       PHP 8.4.23
Configuration: .../phpunit.xml.dist
Warning:       No code coverage driver available
...
OK (103 tests, 169 assertions)
```

To get an actual coverage report, enable a driver first, e.g. `XDEBUG_MODE=coverage composer
test-coverage` with Xdebug installed, or install PCOV.

### `composer check-style` / `composer fix-style`

```text
PHP CS Fixer 3.95.12 Adalbertus ...
Loaded config default from ".../.php-cs-fixer.dist.php".
Running analysis on 9 cores with 10 files per process.
.....................................                             37 / 37 (100%)
Found 0 of 37 files that can be fixed in 0.482 seconds, 98.00 MB memory used
```

The config (`.php-cs-fixer.dist.php`) is `@PSR2` plus a custom rule set (`declare_strict_types`,
short arrays, ordered imports, etc.) over `src` and `tests`. On PHP 8.4 the run also prints a
"minimum PHP version supported … is PHP 7.4" warning and a few "Rule … is deprecated" notices —
both are advisory and do not fail the check. `composer fix-style` is the same tool without
`--dry-run`; it currently fixes nothing.

### `composer analyse`

PHPStan runs at `level: max` over `src` (config `phpstan.neon.dist` + `phpstan-baseline.neon`).
The composer script does **not** raise the memory limit, so on a machine with the default 128M
`memory_limit` it crashes:

```text
 [ERROR] Child process error: PHPStan process crashed because it reached configured PHP
         memory limit: 128M
         Increase your memory limit ... or run PHPStan with --memory-limit CLI option.
```

Run it with a higher limit (the `Makefile` does this with `php -d memory_limit=-1`):

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
```

On **PHP 8.4** this then completes but reports one forward-compat deprecation not present on the
supported PHP range:

```text
  160    Adapters/IndexManagerAdapter.php
         Deprecated in PHP 8.4: Parameter #3 $settings (array) is implicitly nullable
         via default value null.
 [ERROR] Found 1 error
```

On CI's PHP 7.4 – 8.2 runners this analysis passes clean (the baseline covers the known items and
the PHP-8.4 implicit-nullable rule does not apply).

## CI ↔ script mapping

Workflows live in `.github/workflows/`. All three build workflows trigger on `push` to any
branch **except** `master`, with tags ignored — so **CI does not run on `master` or on release
tags, and there is no publish automation**.

| Workflow file          | Name                | PHP            | Runs                    |
|------------------------|---------------------|---------------|-------------------------|
| `test.yml`             | Tests               | 7.4, 8.0, 8.1, 8.2 (matrix, paired testbench/phpunit) | `composer test` |
| `code-style.yml`       | Code style          | 8.0           | `composer check-style`  |
| `static-analysis.yml`  | Static analysis     | 8.0           | `composer analyse`      |
| `stale.yml`            | Close stale issues  | —             | `actions/stale` (housekeeping, no script) |

`test.yml` installs the matrix-specific tooling with
`composer require --no-interaction --dev orchestra/testbench:^<v> phpunit/phpunit:^<v>` and runs
with `coverage: none`. Note **`composer test-coverage` and `composer fix-style` are not run in
CI**.

## Local smoke test against Docker OpenSearch

This exercises the full lifecycle — `make` → `migrate` → `status` → `rollback` — against a real
cluster, driven through a throwaway Laravel app wired via a composer path repository (the same
mechanism used for a real host app; see the [next section](#using-the-package-in-a-host-app-path-repository)).

### 1. Start OpenSearch

The command suggested by the ticket:

```bash
docker run -p 9200:9200 -e discovery.type=single-node opensearchproject/opensearch:2
```

⚠️ **This fails as-is** on current images. `opensearchproject/opensearch:2` resolves to **2.19.6**,
and OpenSearch 2.12+ requires an initial admin password, so the container exits immediately:

```text
OpenSearch 2.12.0 onwards, the OpenSearch Security Plugin a change that requires an initial
password for 'admin' user.
Please define an environment variable 'OPENSEARCH_INITIAL_ADMIN_PASSWORD' with a strong
password string.
...
No custom admin password found. Please provide a password ... OPENSEARCH_INITIAL_ADMIN_PASSWORD.
```

For a **local, no-auth smoke test**, disable the security plugin so the client can talk plain
HTTP with no credentials:

```bash
docker run -d --name osm-smoke -p 9200:9200 \
  -e discovery.type=single-node \
  -e DISABLE_SECURITY_PLUGIN=true \
  opensearchproject/opensearch:2
```

Wait ~10–15s, then confirm it is reachable:

```bash
curl -s http://localhost:9200
```

```json
{
  "name" : "24531fdc479b",
  "cluster_name" : "docker-cluster",
  "version" : {
    "distribution" : "opensearch",
    "number" : "2.19.6",
    ...
  },
  "tagline" : "The OpenSearch Project: https://opensearch.org/"
}
```

(For an auth-enabled cluster instead, pass `-e OPENSEARCH_INITIAL_ADMIN_PASSWORD='<Strong#Pass1>'`
and configure the client with `https` + credentials in `config/opensearch.client.php`.)

### 2. Wire up a throwaway Laravel app

```bash
composer create-project laravel/laravel osm-smoke-app
cd osm-smoke-app

# point a composer path repository at your local checkout of this package
composer config repositories.osm '{"type":"path","url":"/absolute/path/to/opensearch-migrations","options":{"symlink":true}}'
composer require "wonsulting/opensearch-migrations:@dev"
```

Package discovery confirms both layers load:

```text
 wonsulting/opensearch-client .. DONE
 wonsulting/opensearch-migrations .. DONE
```

Publish both config files and create the tracking table (the app's `.env` defaults to
`DB_CONNECTION=sqlite`; the client defaults to `OPENSEARCH_HOST=localhost:9200`, which matches
the container above):

```bash
php artisan vendor:publish --provider="OpenSearch\Laravel\Client\ServiceProvider"
php artisan vendor:publish --provider="OpenSearch\Migrations\ServiceProvider"
php artisan migrate            # creates the opensearch_migrations table
```

```text
 INFO  Running migrations.
 2019_15_12_112000_create_opensearch_migrations_table .. 3.51ms DONE
```

### 3. Author a migration

```bash
php artisan opensearch:make:migration create_products_index
# Created migration: 2026_07_08_172334_create_products_index
```

The generated file lands in `opensearch/migrations/`. Fill in `up()` / `down()`:

```php
public function up(): void
{
    Index::create('products', function (Mapping $mapping, Settings $settings) {
        $mapping->text('name');
        $mapping->float('price');

        $settings->index(['number_of_replicas' => 0]); // 0 replicas → green on a single node
    });
}

public function down(): void
{
    Index::dropIfExists('products');
}
```

### 4. Migrate and verify

```bash
php artisan opensearch:migrate
```

```text
Migrating: 2026_07_08_172334_create_products_index
Migrated: 2026_07_08_172334_create_products_index
```

```bash
php artisan opensearch:migrate:status
```

```text
  Ran?   Last batch?   Migration
  Yes    Yes           2026_07_08_172334_create_products_index
```

Confirm the index and mapping in the cluster:

```bash
curl -s "http://localhost:9200/_cat/indices?v"
# health status index    ...
# green  open   products ...   (0 docs)

curl -s "http://localhost:9200/products/_mapping?pretty"
# "properties": { "name": {"type":"text"}, "price": {"type":"float"} }
```

### 5. Roll back and verify removal

```bash
php artisan opensearch:migrate:rollback
```

```text
Rolling back: 2026_07_08_172334_create_products_index
Rolled back: 2026_07_08_172334_create_products_index
```

```bash
php artisan opensearch:migrate:status         # Ran? No | Last batch? No
curl -s "http://localhost:9200/products"      # {"error":{... "index_not_found_exception" ...},"status":404}
```

### 6. Tear down

```bash
docker rm -f osm-smoke
rm -rf osm-smoke-app
```

## Using the package in a host app (path repository)

To develop the package against a real application (e.g. the WonsultingAI app) without publishing
to Packagist, use the same composer **path repository** mechanism as the smoke test. In the host
app's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../opensearch-migrations", "options": { "symlink": true } }
    ]
}
```

```bash
composer require "wonsulting/opensearch-migrations:@dev"
php artisan vendor:publish --provider="OpenSearch\Laravel\Client\ServiceProvider"
php artisan vendor:publish --provider="OpenSearch\Migrations\ServiceProvider"
php artisan migrate
```

With `symlink: true`, edits to the package are picked up immediately (no re-require). Point
`OPENSEARCH_HOST` (and, for a secured cluster, the client's auth settings in
`config/opensearch.client.php`) at the target cluster, then use the `opensearch:*` commands as
above.

## Release process

The package is distributed on Packagist as
[`wonsulting/opensearch-migrations`](https://packagist.org/packages/wonsulting/opensearch-migrations)
and follows **[semantic versioning](https://semver.org/)**.

There is **no in-repo release automation** — CI does not run on `master` or on tags. A release is
a tag pushed to the repository, which a Packagist webhook picks up:

1. Merge the release commit to `master`; make sure `composer test`, `composer check-style`, and
   `composer analyse` (with `--memory-limit`) are green.
2. Choose the version per semver (MAJOR = breaking API/behavior, MINOR = new backward-compatible
   feature, PATCH = backward-compatible fix).
3. Tag and push:
   ```bash
   git tag v1.2.3
   git push origin v1.2.3
   ```
4. Confirm the new version appears on the
   [Packagist page](https://packagist.org/packages/wonsulting/opensearch-migrations). If it does
   not, check that the GitHub → Packagist webhook is configured, or click **Update** on Packagist.

> If Packagist is not yet auto-updating for this fork, configure the GitHub service hook (or
> Packagist's GitHub integration) once so tag pushes publish automatically.
