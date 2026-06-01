<?php
// Nginx Mapper.
//   GET  /                 -> dashboard: a world map of where traffic comes from
//                            (geolocated client IPs) plus a user-agent bar chart.
//   POST / (action=refresh) -> download the latest DB-IP City Lite GeoIP database.
//   CLI  `php index.php refresh` -> same download, used by the monthly cron.
//
// __APP__, __PATH__, __INSTALL_DIR__ and __DATA_DIR__ are substituted by
// ynh_add_config at install time.

declare(strict_types=1);

const INSTALL_DIR = '__INSTALL_DIR__';
const DATA_DIR    = '__DATA_DIR__';
const MMDB        = DATA_DIR . '/dbip-city-lite.mmdb';

// Mount point of the app (e.g. "" for a root install, "/foo" for a sub-path).
$BASE     = rtrim('__PATH__', '/');
$BASE_URL = ($BASE === '' ? '' : $BASE) . '/';

// Every site's nginx access log. The app user is in the 'adm' group so it can
// read these (they are root:adm, mode 640).
const NGINX_LOG_GLOB     = '/var/log/nginx/*access*.log';
const MAX_LINES_PER_FILE = 5000;   // tail this many lines from each log
const TOP_USER_AGENTS    = 25;     // rows in the user-agent chart

// Load the vendored MaxMind DB reader (pure PHP, no composer autoloader).
require INSTALL_DIR . '/vendor/MaxMind/Db/Reader/Util.php';
require INSTALL_DIR . '/vendor/MaxMind/Db/Reader/InvalidDatabaseException.php';
require INSTALL_DIR . '/vendor/MaxMind/Db/Reader/Metadata.php';
require INSTALL_DIR . '/vendor/MaxMind/Db/Reader/Decoder.php';
require INSTALL_DIR . '/vendor/MaxMind/Db/Reader.php';

use MaxMind\Db\Reader;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// =============================================================================
// GeoIP database refresh (DB-IP City Lite — free, monthly, no licence key)
// =============================================================================

function http_download(string $url, string $destFile): array {
    $fp = @fopen($destFile, 'wb');
    if (!$fp) {
        return [false, "cannot open temp file $destFile"];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_USERAGENT      => 'nginx-mapper/1.0 (+https://nginx-map.p10.club)',
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false) {
        @unlink($destFile);
        return [false, "download error: $err"];
    }
    if ($code !== 200) {
        @unlink($destFile);
        return [false, "HTTP $code"];
    }
    return [true, ''];
}

// Stream-decompress a .gz to a plain file without loading it all into memory.
function gunzip_stream(string $gzPath, string $outPath): bool {
    $in = @gzopen($gzPath, 'rb');
    if (!$in) {
        return false;
    }
    $out = @fopen($outPath, 'wb');
    if (!$out) {
        gzclose($in);
        return false;
    }
    $ok = true;
    while (!gzeof($in)) {
        $chunk = gzread($in, 1 << 20);
        if ($chunk === false) {
            $ok = false;
            break;
        }
        if (fwrite($out, $chunk) === false) {
            $ok = false;
            break;
        }
    }
    gzclose($in);
    fclose($out);
    return $ok;
}

// Download the newest DB-IP City Lite database and atomically swap it in.
// Returns [bool ok, string message].
function refresh_mmdb(): array {
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        return [false, 'data dir ' . DATA_DIR . ' is not writable'];
    }

    // DB-IP publishes one build per month. Try this month, then last month in
    // case the new month's file is not posted yet.
    $now    = time();
    $months = [date('Y-m', $now), date('Y-m', strtotime('first day of last month', $now))];

    $tmpGz  = DATA_DIR . '/.dbip.mmdb.gz.part';
    $tmpOut = DATA_DIR . '/.dbip.mmdb.part';

    $tried = [];
    foreach ($months as $m) {
        $tried[] = $m;
        $url = "https://download.db-ip.com/free/dbip-city-lite-$m.mmdb.gz";
        [$ok, $msg] = http_download($url, $tmpGz);
        if (!$ok) {
            continue;   // 404 for not-yet-published month, etc. — try the next
        }
        if (!gunzip_stream($tmpGz, $tmpOut)) {
            @unlink($tmpGz);
            @unlink($tmpOut);
            return [false, "could not decompress $url"];
        }
        @unlink($tmpGz);

        // Sanity check: the new file must be a loadable MMDB before we swap it.
        try {
            $probe = new Reader($tmpOut);
            $probe->close();
        } catch (\Throwable $e) {
            @unlink($tmpOut);
            return [false, 'downloaded file is not a valid MMDB: ' . $e->getMessage()];
        }

        if (!@rename($tmpOut, MMDB)) {
            @unlink($tmpOut);
            return [false, 'could not move database into place'];
        }
        return [true, "updated from DB-IP City Lite ($m)"];
    }

    return [false, 'no database found (tried: ' . implode(', ', $tried) . ')'];
}

// =============================================================================
// CLI mode: `php index.php refresh`  (used by the monthly cron)
// =============================================================================
if (PHP_SAPI === 'cli') {
    $action = $argv[1] ?? '';
    if ($action === 'refresh') {
        [$ok, $msg] = refresh_mmdb();
        fwrite($ok ? STDOUT : STDERR, ($ok ? 'OK: ' : 'FAILED: ') . $msg . "\n");
        exit($ok ? 0 : 1);
    }
    fwrite(STDERR, "usage: php index.php refresh\n");
    exit(2);
}

// =============================================================================
// POST: refresh button  (Post/Redirect/Get so a reload doesn't re-download)
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
    [$ok, $msg] = refresh_mmdb();
    $flash = ($ok ? 'GeoIP database updated — ' : 'Refresh failed — ') . $msg;
    header('Location: ' . $BASE_URL . '?ok=' . ($ok ? '1' : '0') . '&flash=' . rawurlencode($flash));
    exit;
}

// =============================================================================
// GET: build the dashboard
// =============================================================================

// Read the last N lines of a (possibly large) file without loading all of it.
function tail_file(string $path, int $lines): ?array {
    if (!is_readable($path)) {
        return null;
    }
    $f = @fopen($path, 'rb');
    if (!$f) {
        return null;
    }
    $buffer = 4096;
    fseek($f, 0, SEEK_END);
    $pos  = ftell($f);
    $data = '';
    while ($pos > 0 && substr_count($data, "\n") <= $lines) {
        $read = ($pos - $buffer) > 0 ? $buffer : $pos;
        $pos -= $read;
        fseek($f, $pos);
        $data = fread($f, $read) . $data;
    }
    fclose($f);
    $arr = explode("\n", rtrim($data, "\n"));
    return $arr === [''] ? [] : array_slice($arr, -$lines);
}

// Parse client IP + user-agent out of every nginx "combined" log line we can
// read. Returns aggregate counts.
function scan_logs(): array {
    $ipCounts = [];   // ip  => request count
    $uaCounts = [];   // ua  => request count
    $files    = 0;
    $readable = 0;
    $parsed   = 0;

    foreach (glob(NGINX_LOG_GLOB) ?: [] as $path) {
        $files++;
        $tail = tail_file($path, MAX_LINES_PER_FILE);
        if ($tail === null) {
            continue;   // exists but not readable (group/permission issue)
        }
        $readable++;
        foreach ($tail as $line) {
            if ($line === '') {
                continue;
            }
            // First whitespace-delimited token is $remote_addr; require it to be
            // a real IP so error-log / non-combined lines are skipped.
            if (!preg_match('/^(\S+)/', $line, $m) || !filter_var($m[1], FILTER_VALIDATE_IP)) {
                continue;
            }
            $ip = $m[1];
            // Last quoted field in a combined log line is the user-agent.
            $ua = '-';
            if (preg_match_all('/"([^"]*)"/', $line, $q) && $q[1]) {
                $ua = end($q[1]);
            }
            if ($ua === '' || $ua === '-') {
                $ua = '(none)';
            }
            $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
            $uaCounts[$ua] = ($uaCounts[$ua] ?? 0) + 1;
            $parsed++;
        }
    }

    arsort($uaCounts);
    return [
        'ipCounts' => $ipCounts,
        'uaCounts' => $uaCounts,
        'files'    => $files,
        'readable' => $readable,
        'parsed'   => $parsed,
    ];
}

// Geolocate unique public IPs into map markers aggregated by location.
function geolocate(array $ipCounts): array {
    $markers   = [];   // "lat,lon" => [lat, lon, label, count]
    $countries = [];   // country  => count
    $located   = 0;
    $build     = null;

    if (!is_readable(MMDB)) {
        return ['markers' => [], 'countries' => [], 'located' => 0, 'build' => null, 'hasDb' => false];
    }

    try {
        $reader = new Reader(MMDB);
        $build  = $reader->metadata()->buildEpoch;
    } catch (\Throwable $e) {
        return ['markers' => [], 'countries' => [], 'located' => 0, 'build' => null, 'hasDb' => false];
    }

    foreach ($ipCounts as $ip => $count) {
        // Skip private/reserved ranges — they never geolocate.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            continue;
        }
        try {
            $rec = $reader->get($ip);
        } catch (\Throwable $e) {
            continue;
        }
        if (!is_array($rec) || empty($rec['location'])) {
            continue;
        }
        $lat = $rec['location']['latitude']  ?? null;
        $lon = $rec['location']['longitude'] ?? null;
        if ($lat === null || $lon === null) {
            continue;
        }

        $city    = $rec['city']['names']['en']    ?? '';
        $country = $rec['country']['names']['en'] ?? ($rec['country']['iso_code'] ?? 'Unknown');
        $label   = trim(($city !== '' ? "$city, " : '') . $country);

        $key = round((float)$lat, 3) . ',' . round((float)$lon, 3);
        if (!isset($markers[$key])) {
            $markers[$key] = ['lat' => (float)$lat, 'lon' => (float)$lon, 'label' => $label, 'count' => 0];
        }
        $markers[$key]['count'] += $count;
        $countries[$country] = ($countries[$country] ?? 0) + $count;
        $located += $count;
    }
    $reader->close();

    arsort($countries);
    return [
        'markers'   => array_values($markers),
        'countries' => $countries,
        'located'   => $located,
        'build'     => $build,
        'hasDb'     => true,
    ];
}

$scan = scan_logs();
$geo  = geolocate($scan['ipCounts']);

$totalRequests = array_sum($scan['ipCounts']);
$uniqueIps     = count($scan['ipCounts']);
$markers       = $geo['markers'];
$uaCounts      = array_slice($scan['uaCounts'], 0, TOP_USER_AGENTS, true);
$uaMax         = $uaCounts ? max($uaCounts) : 0;

$mmExists = is_readable(MMDB);
$mmSize   = $mmExists ? filesize(MMDB) : 0;
$dbBuilt  = $geo['build'] ? gmdate('Y-m-d', (int)$geo['build']) : null;

$flash   = $_GET['flash'] ?? '';
$flashOk = ($_GET['ok'] ?? '') === '1';

$css = <<<'CSS'
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 2rem 1.25rem;
    font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: #0d1117; color: #c9d1d9;
  }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 1.1rem; margin: 0 0 .25rem; color: #58a6ff; }
  .sub { color: #8b949e; margin: 0 0 1.25rem; }
  section { background: #161b22; border: 1px solid #30363d; border-radius: 8px; margin: 0 0 1rem; }
  section > h2 {
    font-size: .8rem; text-transform: uppercase; letter-spacing: .06em;
    margin: 0; padding: .6rem .9rem; color: #8b949e;
    border-bottom: 1px solid #30363d; background: #11161d;
    border-radius: 8px 8px 0 0;
  }
  .body { padding: .8rem .9rem; }
  #map { height: 460px; border-radius: 0 0 8px 8px; }
  .leaflet-popup-content { color: #111; font: 12px/1.4 ui-monospace, monospace; }
  .stats { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; }
  .stat { min-width: 120px; }
  .stat .n { font-size: 1.4rem; color: #79c0ff; font-weight: 700; }
  .stat .l { color: #8b949e; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; }
  .bars { display: grid; grid-template-columns: 1fr; gap: .35rem; }
  .bar { display: grid; grid-template-columns: 1fr; gap: .15rem; }
  .bar .meta { display: flex; justify-content: space-between; gap: 1rem; }
  .bar .ua { color: #c9d1d9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .bar .c { color: #79c0ff; font-weight: 700; white-space: nowrap; }
  .bar .track { background: #0d1117; border: 1px solid #21262d; border-radius: 4px; height: 8px; overflow: hidden; }
  .bar .fill { background: linear-gradient(90deg, #1f6feb, #58a6ff); height: 100%; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: .3rem .5rem; border-bottom: 1px solid #21262d; }
  th { color: #8b949e; font-weight: 600; }
  td.n { color: #79c0ff; text-align: right; width: 1%; white-space: nowrap; }
  .empty { color: #6e7681; margin: .3rem 0; }
  .flash { padding: .6rem .9rem; border-radius: 8px; margin: 0 0 1rem; border: 1px solid; }
  .flash.ok { background: #12261a; border-color: #238636; color: #7ee787; }
  .flash.err { background: #2a1416; border-color: #da3633; color: #ffa198; }
  .toolbar { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
  button {
    font: inherit; cursor: pointer; color: #fff; background: #238636;
    border: 1px solid #2ea043; border-radius: 6px; padding: .45rem .9rem; font-weight: 600;
  }
  button:hover { background: #2ea043; }
  .db { color: #8b949e; font-size: .85rem; }
  .db b { color: #c9d1d9; }
  .warn { color: #d29922; }
  footer { color: #6e7681; margin-top: 1rem; font-size: 12px; }
  a { color: #58a6ff; }
  .grid2 { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; align-items: start; }
  @media (max-width: 820px) { .grid2 { grid-template-columns: 1fr; } }
CSS;

function fmt_bytes(int $n): string {
    if ($n <= 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = (int)floor(log($n, 1024));
    $i = max(0, min($i, count($u) - 1));
    return round($n / (1024 ** $i), 1) . ' ' . $u[$i];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>nginx-mapper</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style><?= $css ?></style>
</head>
<body>
<div class="wrap">
  <h1>nginx mapper</h1>
  <p class="sub">geographic origin of all nginx traffic on this server &middot;
    <?= h(gmdate('Y-m-d H:i:s')) ?> UTC</p>

  <?php if ($flash !== ''): ?>
    <div class="flash <?= $flashOk ? 'ok' : 'err' ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <section>
    <h2>GeoIP database</h2>
    <div class="body">
      <div class="toolbar">
        <form method="post" action="<?= h($BASE_URL) ?>">
          <input type="hidden" name="action" value="refresh">
          <button type="submit">Refresh now</button>
        </form>
        <div class="db">
          <?php if ($mmExists): ?>
            DB-IP City Lite &middot; built <b><?= h($dbBuilt ?? 'unknown') ?></b>
            &middot; <?= h(fmt_bytes((int)$mmSize)) ?>
          <?php else: ?>
            <span class="warn">No database yet.</span> Click <b>Refresh now</b> to download DB-IP City Lite.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section>
    <h2>Overview</h2>
    <div class="body stats">
      <div class="stat"><div class="n"><?= number_format($totalRequests) ?></div><div class="l">requests</div></div>
      <div class="stat"><div class="n"><?= number_format($uniqueIps) ?></div><div class="l">unique IPs</div></div>
      <div class="stat"><div class="n"><?= number_format($geo['located']) ?></div><div class="l">located</div></div>
      <div class="stat"><div class="n"><?= number_format(count($markers)) ?></div><div class="l">locations</div></div>
      <div class="stat"><div class="n"><?= number_format($scan['readable']) ?>/<?= number_format($scan['files']) ?></div><div class="l">logs read</div></div>
    </div>
    <?php if ($scan['files'] > 0 && $scan['readable'] === 0): ?>
      <div class="body"><span class="warn">No nginx logs were readable.</span> The app user may not be in the
        <code>adm</code> group yet — re-run the install/upgrade, or check <code>/var/log/nginx</code> permissions.</div>
    <?php endif; ?>
  </section>

  <section>
    <h2>Traffic map (last <?= number_format(MAX_LINES_PER_FILE) ?> lines/log)</h2>
    <?php if (!$mmExists): ?>
      <div class="body"><p class="empty">Download the GeoIP database to populate the map.</p></div>
    <?php elseif (!$markers): ?>
      <div class="body"><p class="empty">No locatable public IPs in the scanned logs yet.</p></div>
    <?php else: ?>
      <div id="map"></div>
    <?php endif; ?>
  </section>

  <div class="grid2">
    <section>
      <h2>User agents (top <?= number_format(TOP_USER_AGENTS) ?>)</h2>
      <div class="body">
        <?php if (!$uaCounts): ?>
          <p class="empty">(no requests parsed)</p>
        <?php else: ?>
          <div class="bars">
            <?php foreach ($uaCounts as $ua => $count): ?>
              <div class="bar">
                <div class="meta">
                  <span class="ua" title="<?= h($ua) ?>"><?= h(mb_strimwidth($ua, 0, 90, '…')) ?></span>
                  <span class="c"><?= number_format($count) ?></span>
                </div>
                <div class="track"><div class="fill" style="width: <?= $uaMax ? round($count / $uaMax * 100, 1) : 0 ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section>
      <h2>Top countries</h2>
      <div class="body">
        <?php if (!$geo['countries']): ?>
          <p class="empty">(none)</p>
        <?php else: ?>
          <table>
            <tr><th>Country</th><th class="n">Requests</th></tr>
            <?php foreach (array_slice($geo['countries'], 0, 15, true) as $country => $count): ?>
              <tr><td><?= h($country) ?></td><td class="n"><?= number_format($count) ?></td></tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <footer>Geolocation by <a href="https://db-ip.com">DB-IP</a> (CC-BY-4.0).
    Restricted to YunoHost admins. Private/reserved IPs are not mapped.</footer>
</div>

<?php if ($mmExists && $markers): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
  const markers = <?= json_encode($markers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const map = L.map('map', { worldCopyJump: true }).setView([20, 0], 2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18, attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const max = markers.reduce((m, p) => Math.max(m, p.count), 1);
  for (const p of markers) {
    const r = 4 + 14 * Math.sqrt(p.count / max);
    L.circleMarker([p.lat, p.lon], {
      radius: r, color: '#58a6ff', weight: 1, fillColor: '#1f6feb', fillOpacity: 0.55
    }).addTo(map).bindPopup(
      '<b>' + (p.label || 'Unknown') + '</b><br>' + p.count.toLocaleString() + ' request' + (p.count === 1 ? '' : 's')
    );
  }
</script>
<?php endif; ?>
</body>
</html>
