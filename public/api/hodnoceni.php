<?php
/**
 * Hodnocení podniků (hvězdičky 1–5 + volitelný text se schvalováním).
 *
 * GET  ?podnik=<id>                → průměr, počet a schválené recenze jednoho podniku
 * GET  ?souhrn=1                   → { id: [průměr, počet] } pro všechny hodnocené podniky
 * POST podnik, url, hvezdy, text, jmeno, web2 (past), t (čas načtení v ms)
 *                                  → hvězdy platí hned, text po schválení
 * GET  ?akce=schvalit|zamitnout&id=<id>&t=<podpis>  → moderace z e-mailu
 * GET  ?admin=<podpis>             → přesměruje do administrace /prihlaseni/ (api/admin.php)
 *
 * Data: SQLite v /_data/ (mimo dosah deploye, přímý přístup zakazuje .htaccess).
 * Ukládá se jen anonymní otisk IP (HMAC), ne IP adresa.
 */
declare(strict_types=1);
require __DIR__ . '/_smtp.php';

const PRIJEMCE = 'karolina@nabrunch.cz';
const LIMIT_ZA_HODINU = 10;
const MAX_TEXT = 600;
const MAX_JMENO = 40;

header('X-Robots-Tag: noindex');

function json_out(int $code, $data, int $cache = 0): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header($cache > 0 ? "Cache-Control: public, max-age=$cache" : 'Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function html_out(string $titulek, string $obsah): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($titulek) . '</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#2b211c;background:#fff5f4">'
        . '<h1 style="font-weight:600;color:#532a19">' . htmlspecialchars($titulek) . '</h1>' . $obsah . '</body>';
    exit;
}

// Adresa webu pro odkazy v e-mailech (new.nabrunch.cz i nabrunch.cz)
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$WEB = 'https://' . (preg_match('~^(new\.|www\.)?nabrunch\.cz$~', $host) ? $host : 'nabrunch.cz');

// ---------------------------------------------------------------- úložiště
$uloziste = dirname(__DIR__) . '/_data';
if (!is_dir($uloziste) && !@mkdir($uloziste, 0700, true)) {
    json_out(503, ['ok' => false, 'chyba' => 'Úložiště není dostupné.']);
}
if (!is_file("$uloziste/.htaccess")) {
    @file_put_contents("$uloziste/.htaccess", "Require all denied\nDeny from all\n");
}
$tajSoubor = "$uloziste/tajemstvi.txt";
if (!is_file($tajSoubor)) {
    file_put_contents($tajSoubor, bin2hex(random_bytes(32)));
    @chmod($tajSoubor, 0600);
}
$TAJ = trim((string)file_get_contents($tajSoubor));
$podpis = fn(string $s): string => substr(hash_hmac('sha256', $s, $TAJ), 0, 32);

if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    json_out(503, ['ok' => false, 'chyba' => 'Na serveru chybí podpora SQLite (pdo_sqlite).']);
}
$db = new PDO("sqlite:$uloziste/hodnoceni.sqlite", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS hodnoceni (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    podnik INTEGER NOT NULL,
    url TEXT,
    hvezdy INTEGER NOT NULL CHECK (hvezdy BETWEEN 1 AND 5),
    text TEXT,
    jmeno TEXT,
    stav TEXT NOT NULL DEFAULT "ok",          -- ok | ceka | zamitnuto (týká se textu)
    otisk TEXT NOT NULL,                      -- HMAC(IP + podnik): jedno hodnocení na podnik
    otisk_ip TEXT NOT NULL,                   -- HMAC(IP + hodina): limit odeslání
    vytvoreno TEXT NOT NULL
)');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS u_otisk ON hodnoceni(otisk)');
$db->exec('CREATE INDEX IF NOT EXISTS i_podnik ON hodnoceni(podnik)');

$souhrnSoubor = "$uloziste/souhrn.json";
function obnov_souhrn(PDO $db, string $soubor): void
{
    $out = new stdClass();
    foreach ($db->query('SELECT podnik, ROUND(AVG(hvezdy), 1) p, COUNT(*) n FROM hodnoceni GROUP BY podnik') as $r) {
        $out->{$r['podnik']} = [(float)$r['p'], (int)$r['n']];
    }
    file_put_contents($soubor, json_encode($out), LOCK_EX);
}

$platneId = fn($s): bool => is_string($s) && preg_match('/^\d{1,9}$/', $s) === 1;

// ---------------------------------------------------------------- moderace
if (isset($_GET['akce'], $_GET['id'], $_GET['t'])) {
    $id = (int)$_GET['id'];
    $akce = (string)$_GET['akce'];
    if (!in_array($akce, ['schvalit', 'zamitnout'], true) || !hash_equals($podpis("$akce:$id"), (string)$_GET['t'])) {
        html_out('Neplatný odkaz', '<p>Odkaz je neplatný nebo neúplný.</p>');
    }
    $db->prepare('UPDATE hodnoceni SET stav = ? WHERE id = ?')->execute([$akce === 'schvalit' ? 'ok' : 'zamitnuto', $id]);
    $st = $db->prepare('SELECT url FROM hodnoceni WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    html_out(
        $akce === 'schvalit' ? 'Recenze schválena' : 'Text recenze zamítnut',
        '<p>' . ($akce === 'schvalit' ? 'Text je teď na webu vidět.' : 'Text se nezobrazí, hvězdičky zůstávají započítané.') . '</p>'
        . ($r && $r['url'] ? '<p><a href="' . htmlspecialchars($WEB . $r['url']) . '">Zobrazit podnik</a></p>' : '')
        . '<p><a href="' . htmlspecialchars($WEB) . '/prihlaseni/">Další ke schválení v administraci</a></p>'
    );
}

// Přehled je v administraci (/prihlaseni/); starý odkaz z e-mailu rovnou přihlásí
if (isset($_GET['admin'])) {
    header('Location: ' . $WEB . '/api/admin.php?admin=' . rawurlencode((string)$_GET['admin']) . '&z=hodnoceni', true, 302);
    exit;
}

// ---------------------------------------------------------------- čtení
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['souhrn'])) {
        if (!is_file($souhrnSoubor)) obnov_souhrn($db, $souhrnSoubor);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile($souhrnSoubor);
        exit;
    }
    $podnik = (string)($_GET['podnik'] ?? '');
    if (!$platneId($podnik)) json_out(400, ['ok' => false]);
    $st = $db->prepare('SELECT ROUND(AVG(hvezdy), 1) p, COUNT(*) n FROM hodnoceni WHERE podnik = ?');
    $st->execute([(int)$podnik]);
    $s = $st->fetch();
    $st = $db->prepare("SELECT hvezdy, text, jmeno, substr(vytvoreno, 1, 10) datum FROM hodnoceni
        WHERE podnik = ? AND stav = 'ok' AND text IS NOT NULL AND text != '' ORDER BY id DESC LIMIT 50");
    $st->execute([(int)$podnik]);
    json_out(200, ['prumer' => (float)$s['p'], 'pocet' => (int)$s['n'], 'recenze' => $st->fetchAll()], 60);
}

// ---------------------------------------------------------------- zápis
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(405, ['ok' => false]);

$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '' && !preg_match('~^https?://(new\.|www\.)?nabrunch\.cz/~', $ref)) json_out(403, ['ok' => false]);

$v = fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $max);
if ($v('web2', 50) !== '') json_out(200, ['ok' => true]); // robot
$start = (int)($_POST['t'] ?? 0);
if ($start > 0 && (time() * 1000 - $start) < 2500) json_out(400, ['ok' => false, 'chyba' => 'Zkuste to prosím znovu.']);

$podnik = (string)($_POST['podnik'] ?? '');
$url = (string)($_POST['url'] ?? '');
$hvezdy = (int)($_POST['hvezdy'] ?? 0);
$text = $v('text', MAX_TEXT);
$jmeno = $v('jmeno', MAX_JMENO);
if (!$platneId($podnik) || !preg_match('~^/podniky/[a-z0-9\-]{2,120}/$~', $url)) json_out(400, ['ok' => false, 'chyba' => 'Neplatný podnik.']);
if ($hvezdy < 1 || $hvezdy > 5) json_out(422, ['ok' => false, 'chyba' => 'Vyberte počet hvězdiček.']);
if ($text !== '' && mb_strlen($text) < 10) json_out(422, ['ok' => false, 'chyba' => 'Text je moc krátký (aspoň 10 znaků), nebo ho nechte prázdný.']);
if (preg_match('~https?://|www\.~i', $text)) json_out(422, ['ok' => false, 'chyba' => 'Text nesmí obsahovat odkazy.']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$otisk = $podpis("podnik:$podnik:$ip");
$otiskIp = $podpis('ip:' . $ip . ':' . date('YmdH'));
$st = $db->prepare('SELECT COUNT(*) FROM hodnoceni WHERE otisk_ip = ?');
$st->execute([$otiskIp]);
if ((int)$st->fetchColumn() >= LIMIT_ZA_HODINU) json_out(429, ['ok' => false, 'chyba' => 'Příliš mnoho hodnocení. Zkuste to prosím později.']);

$stav = $text === '' ? 'ok' : 'ceka';
try {
    $db->prepare('INSERT INTO hodnoceni (podnik, url, hvezdy, text, jmeno, stav, otisk, otisk_ip, vytvoreno) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$podnik, $url, $hvezdy, $text ?: null, $jmeno ?: null, $stav, $otisk, $otiskIp, date('c')]);
} catch (PDOException $e) {
    json_out(409, ['ok' => false, 'chyba' => 'Tento podnik jste už hodnotili. Děkujeme!']);
}
$id = (int)$db->lastInsertId();
obnov_souhrn($db, $souhrnSoubor);

if ($stav === 'ceka') {
    $base = $WEB . '/api/hodnoceni.php';
    $telo = str_repeat('★', $hvezdy) . str_repeat('☆', 5 - $hvezdy) . "\n"
        . 'Podnik: ' . $WEB . $url . "\n"
        . 'Jméno: ' . ($jmeno ?: 'neuvedeno') . "\n\n"
        . $text . "\n\n"
        . "Schválit: $base?akce=schvalit&id=$id&t=" . $podpis("schvalit:$id") . "\n"
        . "Zamítnout text: $base?akce=zamitnout&id=$id&t=" . $podpis("zamitnout:$id") . "\n\n"
        . "Vše ke schválení v administraci: $WEB/api/admin.php?admin=" . $podpis('admin') . "&z=hodnoceni\n";
    nb_odesli(
        PRIJEMCE,
        '=?UTF-8?B?' . base64_encode("[naBrunch] Nová recenze ke schválení ($hvezdy/5)") . '?=',
        chunk_split(base64_encode($telo)),
        implode("\r\n", [
            'From: =?UTF-8?B?' . base64_encode('naBrunch.cz') . '?= <' . SMTP_UZIVATEL . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ])
    );
}

json_out(200, ['ok' => true, 'ceka' => $stav === 'ceka']);
