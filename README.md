# Nginx Mapper for YunoHost

An admin-only YunoHost app that scrapes every site's nginx access log and shows
**where your traffic comes from** on a world map, plus a **user-agent bar
chart** and a **top-countries** table.

IP-to-location lookups use a local [DB-IP City Lite](https://db-ip.com/db/download/ip-to-city-lite)
database (free, CC-BY-4.0, no licence key). The reader is the vendored
[MaxMind DB Reader](https://github.com/maxmind/MaxMind-DB-Reader-php) (pure PHP,
Apache-2.0) under `conf/vendor/` — no PHP extension or composer needed.

## How it works

- **`/`** — the dashboard. It tails the last 5000 lines of each
  `/var/log/nginx/*access*.log`, extracts the client IP and user-agent from each
  combined-format line, geolocates the public IPs against the local `.mmdb`, and
  renders:
  - a [Leaflet](https://leafletjs.com) map (OpenStreetMap tiles) with one marker
    per location, sized by request volume;
  - a CSS bar chart of the top 25 user-agents;
  - overview stats and a top-countries table.
- **Refresh now** (button) — downloads the newest DB-IP City Lite database,
  streaming the `.gz` and decompressing it in place. The same code runs from the
  CLI (`php index.php refresh`) via a **monthly cron** (`/etc/cron.d/<app>`).

The GeoIP database is stored in the app's data dir
(`<data_dir>/dbip-city-lite.mmdb`) so it survives upgrades and is backed up.

### Permissions

- The main permission defaults to the **`admins`** group, so SSOwat forces an
  admin login before the page is reachable. It is **not** public — it exposes
  traffic data for the whole server.
- To read every site's logs (`root:adm`, mode `640`), the app's system user is
  added to the **`adm`** group and php-fpm is restarted so the pool worker picks
  the group up.

## Install

This app is not in the YunoHost catalogue; install it straight from this repo.

```bash
sudo yunohost app install https://github.com/LukeKeller/nginx-mapper_ynh \
  --args "domain=nginx-map.p10.club&path=/&init_main_permission=admins"
```

Or, from a local checkout on the server:

```bash
sudo yunohost app install /path/to/nginx-mapper_ynh \
  --args "domain=nginx-map.p10.club&path=/&init_main_permission=admins"
```

The install downloads the GeoIP database automatically (best-effort; if there is
no network at install time, just click **Refresh now** afterwards). Then browse
to <https://nginx-map.p10.club/> as a YunoHost admin.

## Upgrade / remove

```bash
sudo yunohost app upgrade nginx_mapper -u https://github.com/LukeKeller/nginx-mapper_ynh
sudo yunohost app remove  nginx_mapper
```

## Notes

- **Data freshness:** DB-IP publishes a new City Lite build at the start of each
  month; the cron refreshes on the 1st. Use **Refresh now** any time.
- **Map coverage:** only public IPs are mapped — private/reserved ranges
  (`10.*`, `192.168.*`, loopback, etc.) are counted in the totals but never
  plotted.
- **Map tiles & Leaflet** load from public CDNs (unpkg, OpenStreetMap); the
  GeoIP lookups themselves are fully local.
- Tested against YunoHost 11.2+ (packaging v2, PHP 8.2).

## Attribution

- IP geolocation by [DB-IP](https://db-ip.com) (CC-BY-4.0).
- MMDB reader © MaxMind, Apache-2.0 (`conf/vendor/MaxMind/Db/LICENSE`).
