# Branch Atlas

An anonymized example of an interactive branch map with tilted view, NFC links, role-based reports, passkeys and an administrator login audit. All offices, reports, and people in the demo are synthetic.

## Run locally

Requires Node.js 20+, pnpm, PHP 8 with PDO SQLite, and optional Composer for passkeys.

1. Run `pnpm install --frozen-lockfile` and `node build-php.mjs`.
2. Run `php init-demo.php` and supply a local demo password of 10–70 characters over standard input.
3. Run `php -S localhost:8080 -t public public/index.php`. Open <http://localhost:8080/>. The local admin username is `administrator`.

Run `composer install` to enable the passkey backend. The site origin defaults to the local example and can be set via `DEMO_ORIGIN`; passkeys require a secure browser context. Production deployment requires HTTPS, session secrets and the SQLite database belong outside the document root.

The admin audit can optionally look up approximate locations locally. Place the November 2026 DB-IP City Lite MMDB gzip and the MaxMind PHP reader under `public/atlas-geoip/` as `dbip-city.mmdb.gz`, `autoload.php` and `src/MaxMind/Db/`. Block public HTTP access to that folder on production servers. The audit continues with device and IP columns when the optional database is absent.

The map geometry is from [geoBoundaries KAZ ADM1](https://www.geoboundaries.org/api/current/gbOpen/KAZ/ADM1/), OpenStreetMap contributors and Wambacher, ODbL 1.0. Optional IP geolocation data by [DB-IP](https://db-ip.com/db/download/ip-to-city-lite), CC BY 4.0.
