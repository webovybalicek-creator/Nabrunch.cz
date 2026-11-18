<?php
/**
 * Správa neověřených kaváren jejich majiteli – co nejvíc bez ruční asistence.
 *
 * POST akce=zadost   id, jmeno, email, telefon, web2 (past)
 *      → e-mail sedí s kavárnou (e-mail z OSM, doména webu kavárny, nebo je uvedený na jejím webu)
 *        = přístup se pošle hned; jinak čeká na schválení (odkazy v e-mailu správci)
 * POST akce=nacti    o, t                      → údaje kavárny pro formulář
 * POST akce=uloz     o, t, data (JSON)         → automatická kontrola; v pořádku = zveřejní se při další synchronizaci,
 *                                                podezřelé = čeká na schválení
 * POST akce=foto     o, t, foto (soubor)       → uloží WebP 1400 + 640 px (max. 6 fotek)
 * POST akce=smazfoto o, t, f                   → smaže fotku
 * GET  export=1                                → veřejná data schválených profilů (pro build webu)
 * GET  akce=schvalit|zamitnout&z=<id>&t=       → žádost o přístup (z e-mailu)
 * GET  akce=pustit|vratit&s=<id>&t=            → změna profilu: zveřejnit / vrátit předchozí verzi
 * GET  akce=smazatfoto&s=&f=&t=                → smazání fotky správcem
 * GET  akce=zablokovat&o=&t=                   → zablokovat majitele a stáhnout profil
 * GET  admin=<podpis>                          → přesměruje do /admin/ (správa v api/admin.php)
 */
declare(strict_types=1);
require __DIR__ . '/_smtp.php';

const PRIJEMCE = 'karolina@nabrunch.cz';
const MAX_FOTEK = 6;
const MAX_POPIS = 600;

header('X-Robots-Tag: noindex');

function json_out(int $code, $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function html_out(string $titulek, string $obsah): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . htmlspecialchars($titulek) . '</title>'
        . '<body style="font:16px/1.5 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#2b211c;background:#fff5f4">'
        . '<h1 style="font-weight:600;color:#532a19">' . htmlspecialchars($titulek) . '</h1>' . $obsah . '</body>';
    exit;
}
function posli(string $komu, string $predmet, string $telo): bool
{
    $hl = implode("\r\n", [
        'From: naBrunch.cz <' . PRIJEMCE . '>',
        'Reply-To: ' . PRIJEMCE,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    if ($komu === PRIJEMCE && !empty($GLOBALS['ADMIN_ODKAZ'])) $telo .= "\nPřehled všech majitelů a změn: {$GLOBALS['ADMIN_ODKAZ']}\n";
    return nb_odesli($komu, '=?UTF-8?B?' . base64_encode($predmet) . '?=', $telo, $hl);
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$WEB = 'https://' . (preg_match('~^(new\.|www\.)?nabrunch\.cz$~', $host) ? $host : 'nabrunch.cz');
$API = $WEB . '/api/kavarna.php';

// ---------------------------------------------------------------- úložiště a podpisy
$uloziste = dirname(__DIR__) . '/_data';
if (!is_dir($uloziste) && !@mkdir($uloziste, 0700, true)) json_out(503, ['ok' => false, 'chyba' => 'Úložiště není dostupné.']);
if (!is_file("$uloziste/.htaccess")) @file_put_contents("$uloziste/.htaccess", "Require all denied\nDeny from all\n");
$tajSoubor = "$uloziste/tajemstvi.txt";
if (!is_file($tajSoubor)) {
    file_put_contents($tajSoubor, bin2hex(random_bytes(32)));
    @chmod($tajSoubor, 0600);
}
$TAJ = trim((string)file_get_contents($tajSoubor));
$podpis = fn(string $s): string => substr(hash_hmac('sha256', $s, $TAJ), 0, 32);
$ADMIN_ODKAZ = $WEB . '/api/admin.php?admin=' . $podpis('kavarny-admin');

$db = new PDO("sqlite:$uloziste/kavarny-majitele.sqlite", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE IF NOT EXISTS majitele (
    id INTEGER PRIMARY KEY AUTOINCREMENT, kavarna TEXT NOT NULL, email TEXT NOT NULL, jmeno TEXT, telefon TEXT,
    stav TEXT NOT NULL, overeni TEXT, vytvoreno TEXT NOT NULL, otisk TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS profily (
    kavarna TEXT PRIMARY KEY, majitel INTEGER NOT NULL, data TEXT NOT NULL, verejna TEXT, predchozi TEXT,
    stav TEXT NOT NULL, duvod TEXT, upraveno TEXT NOT NULL, overeno TEXT NOT NULL)');

$db->exec('CREATE TABLE IF NOT EXISTS nove (
    id TEXT PRIMARY KEY, data TEXT NOT NULL, stav TEXT NOT NULL, vytvoreno TEXT NOT NULL)');

// Kontakty kaváren z buildu (id → [název, url, e-mail, web]) + nově založené kavárny majitelů
$KAV = is_file(__DIR__ . '/_kavarny-kontakty.php') ? (array)require __DIR__ . '/_kavarny-kontakty.php' : [];
foreach ($db->query('SELECT id, data FROM nove') as $r) {
    $n = json_decode($r['data'], true) ?: [];
    $KAV[$r['id']] = [$n['nazev'] ?? $r['id'], $n['url'] ?? '/kavarny/', '', ''];
}

$otisk = hash_hmac('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), $TAJ);
$limit = function (string $druh, int $max) use ($uloziste, $otisk): void {
    $f = "$uloziste/limit-$druh-" . substr($otisk, 0, 16) . '-' . date('YmdH') . '.txt';
    $n = (int)@file_get_contents($f);
    if ($n >= $max) json_out(429, ['ok' => false, 'chyba' => 'Příliš mnoho pokusů. Zkuste to prosím za hodinu.']);
    @file_put_contents($f, (string)($n + 1));
};
$prihlaseni = function () use ($db, $podpis): array {
    $o = (int)($_POST['o'] ?? 0);
    if (!$o || !hash_equals($podpis("majitel:$o"), (string)($_POST['t'] ?? ''))) json_out(403, ['ok' => false, 'chyba' => 'Neplatný přihlašovací odkaz.']);
    $st = $db->prepare("SELECT * FROM majitele WHERE id = ? AND stav = 'ok'");
    $st->execute([$o]);
    $m = $st->fetch();
    if (!$m) json_out(403, ['ok' => false, 'chyba' => 'Přístup už neplatí. Požádejte o něj znovu na stránce kavárny.']);
    return $m;
};
$odkazPrihlaseni = fn(int $o): string => $WEB . '/sprava-kavarny/?o=' . $o . '&t=' . $podpis("majitel:$o");
$fotkyDir = fn(string $k): string => dirname(__DIR__) . '/fotky-kavaren/' . preg_replace('/[^a-z0-9]/', '', $k);
$fotky = function (string $k) use ($fotkyDir): array {
    $out = [];
    foreach (glob($fotkyDir($k) . '/*_1400.webp') ?: [] as $f) $out[filemtime($f) . basename($f)] = '/fotky-kavaren/' . basename(dirname($f)) . '/' . basename($f, '_1400.webp');
    ksort($out);
    return array_values($out);
};
$akce = (string)($_GET['akce'] ?? $_POST['akce'] ?? '');

/**
 * Zásahy správce – společné pro odkazy z e-mailu i pro /admin/.
 * schvalit|zamitnout|zablokovat: $id = id majitele; pustit|vratit: $id = id kavárny; smazatfoto: $id = kavárna, $f = fotka.
 * Vrací [titulek, html].
 */
$spravce = function (string $akce, string $id, string $f = '') use ($db, $KAV, $fotkyDir, $odkazPrihlaseni): array {
    if (in_array($akce, ['schvalit', 'zamitnout'], true)) {
        $o = (int)$id;
        $st = $db->prepare('SELECT * FROM majitele WHERE id = ?');
        $st->execute([$o]);
        $m = $st->fetch();
        if (!$m) return ['Žádost nenalezena', ''];
        $nazev = $KAV[$m['kavarna']][0] ?? $m['kavarna'];
        if ($akce === 'zamitnout') {
            $db->prepare("UPDATE majitele SET stav = 'zamitnuto' WHERE id = ?")->execute([$o]);
            $db->prepare("UPDATE nove SET stav = 'zamitnuto' WHERE id = ?")->execute([$m['kavarna']]);
            return ['Zamítnuto', '<p>Žádost ' . htmlspecialchars($m['email']) . ' o kavárnu ' . htmlspecialchars($nazev) . ' je zamítnutá.</p>'];
        }
        if ($m['stav'] === 'ok') return ['Už schváleno', '<p>Žádost ' . htmlspecialchars($m['email']) . ' už byla schválená dřív.</p>'];
        $db->prepare("UPDATE majitele SET stav = 'ok', overeni = 'ručně' WHERE id = ?")->execute([$o]);
        $db->prepare("UPDATE nove SET stav = 'ok' WHERE id = ?")->execute([$m['kavarna']]);
        $db->prepare("UPDATE profily SET stav = 'ok', duvod = NULL WHERE kavarna = ? AND verejna IS NOT NULL")->execute([$m['kavarna']]);
        posli($m['email'], "Přístup ke správě kavárny $nazev", "Dobrý den,\n\nvaši žádost jsme schválili. Údaje kavárny $nazev na naBrunch.cz upravíte tady:\n\n"
            . $odkazPrihlaseni($o) . "\n\nOdkaz si uložte, slouží k dalšímu přihlášení.\n\nnaBrunch.cz\n");
        return ['Schváleno', '<p>Přístup poslán na ' . htmlspecialchars($m['email']) . '.</p>'];
    }
    if ($akce === 'pustit') {
        $db->prepare("UPDATE profily SET verejna = data, stav = 'ok', duvod = NULL WHERE kavarna = ?")->execute([$id]);
        return ['Zveřejněno', '<p>Změna se na webu objeví při další synchronizaci.</p>'];
    }
    if ($akce === 'vratit') {
        $db->prepare("UPDATE profily SET verejna = predchozi, data = COALESCE(predchozi, data), stav = 'ok', duvod = 'vráceno správcem' WHERE kavarna = ?")->execute([$id]);
        return ['Vráceno', '<p>Na webu zůstane předchozí verze (nebo původní údaje z OpenStreetMap).</p>'];
    }
    if ($akce === 'zablokovat') {
        $o = (int)$id;
        $st = $db->prepare('SELECT kavarna FROM majitele WHERE id = ?');
        $st->execute([$o]);
        $kav = (string)$st->fetchColumn();
        $db->prepare("UPDATE majitele SET stav = 'blokovan' WHERE id = ?")->execute([$o]);
        $db->prepare("UPDATE nove SET stav = 'blokovan' WHERE id = ?")->execute([$kav]);
        $db->prepare("UPDATE profily SET stav = 'stazeno', verejna = NULL WHERE kavarna = ? AND majitel = ?")->execute([$kav, $o]);
        if ($kav !== '') foreach (glob($fotkyDir($kav) . '/*.webp') ?: [] as $x) @unlink($x);
        return ['Zablokováno', '<p>Majitel je zablokovaný, jeho údaje a fotky jsou stažené z webu.</p>'];
    }
    if ($akce === 'smazatfoto') {
        $jm = preg_replace('/[^a-f0-9]/', '', $f);
        foreach ([1400, 640] as $x) @unlink($fotkyDir($id) . "/{$jm}_$x.webp");
        return ['Smazáno', '<p>Fotka je smazaná, z webu zmizí při další synchronizaci.</p>'];
    }
    return ['Neznámá akce', ''];
};

// /api/admin.php načítá tento soubor kvůli úložišti a funkcím, akce níž jsou jen pro přímé volání
if (defined('NB_ADMIN')) return;

// ---------------------------------------------------------------- automatické ověření e-mailu
function domena(string $url): string
{
    $h = strtolower((string)parse_url(preg_match('~^https?://~i', $url) ? $url : "https://$url", PHP_URL_HOST));
    return preg_replace('/^www\./', '', $h);
}
/** Registrovatelná doména (kavarna.cz z shop.kavarna.cz) */
function koren(string $d): string
{
    $c = explode('.', $d);
    return count($c) > 2 ? implode('.', array_slice($c, -2)) : $d;
}
const SDILENE = '~(^|\.)(facebook|instagram|google|wix|wixsite|webnode|estranky|blogspot|wordpress|squarespace|gmail|seznam|email|centrum|atlas|volny|post|tiscali|outlook|hotmail|icloud|yahoo|linktr|linktree|business\.site|sites\.google)\.~';

function emailNaWebu(string $email, string $web): bool
{
    if ($web === '') return false;
    $zaklad = preg_match('~^https?://~i', $web) ? rtrim($web, '/') : 'https://' . rtrim($web, '/');
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'max_redirects' => 4, 'user_agent' => 'nabrunch.cz-overeni/1.0 (+https://nabrunch.cz)'], 'ssl' => ['verify_peer' => true]]);
    [$u, $d] = explode('@', $email, 2);
    $vzory = [$email, "$u [at] $d", "$u(at)$d", "$u&#64;$d", "$u&#x40;$d", "mailto:$email"];
    foreach (['', '/kontakt', '/kontakty', '/contact', '/o-nas'] as $cesta) {
        $html = @file_get_contents($zaklad . $cesta, false, $ctx, 0, 600000);
        if (!$html) continue;
        $html = strtolower(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($vzory as $v) if (str_contains($html, strtolower($v))) return true;
    }
    return false;
}

// ---------------------------------------------------------------- žádost o přístup
if ($akce === 'zadost' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['web2'] ?? '')) !== '') json_out(200, ['ok' => true, 'hned' => false]);
    $limit('zadost', 5);
    $id = (string)($_POST['id'] ?? '');
    $k = $KAV[$id] ?? null;
    if (!$k) json_out(400, ['ok' => false, 'chyba' => 'Kavárna nenalezena.']);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $jmeno = mb_substr(trim((string)($_POST['jmeno'] ?? '')), 0, 80);
    $tel = mb_substr(trim((string)($_POST['telefon'] ?? '')), 0, 30);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(422, ['ok' => false, 'chyba' => 'Zadejte platný e-mail.']);
    if ($jmeno === '') json_out(422, ['ok' => false, 'chyba' => 'Vyplňte své jméno.']);
    [$nazev, $url, $kEmail, $kWeb] = $k + [null, null, '', ''];

    $domEmail = substr(strrchr($email, '@'), 1);
    $domWeb = $kWeb ? domena($kWeb) : '';
    $overeni = null;
    if ($kEmail && strtolower($kEmail) === $email) $overeni = 'e-mail kavárny v OpenStreetMap';
    elseif ($domWeb && !preg_match(SDILENE, ".$domWeb.") && koren($domEmail) === koren($domWeb)) $overeni = "e-mail na doméně webu ($domWeb)";
    elseif ($kWeb && emailNaWebu($email, $kWeb)) $overeni = 'e-mail uvedený na webu kavárny';

    $db->prepare('INSERT INTO majitele (kavarna, email, jmeno, telefon, stav, overeni, vytvoreno, otisk) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $email, $jmeno, $tel, $overeni ? 'ok' : 'ceka', $overeni, date('c'), $otisk]);
    $o = (int)$db->lastInsertId();

    if ($overeni) {
        posli($email, "Přístup ke správě kavárny $nazev", "Dobrý den,\n\nváš e-mail jsme ověřili ($overeni). Údaje kavárny $nazev na naBrunch.cz upravíte tady:\n\n"
            . $odkazPrihlaseni($o) . "\n\nOdkaz si uložte, slouží k dalšímu přihlášení. Nikomu ho neposílejte.\n\nnaBrunch.cz\n");
        posli(PRIJEMCE, "[naBrunch] Majitel ověřen automaticky: $nazev", "Kavárna: $WEB$url\nMajitel: $jmeno, $email, $tel\nOvěření: $overeni\n\n"
            . "Kdyby to nesedělo, zablokujte přístup: $API?akce=zablokovat&o=$o&t=" . $podpis("zablokovat:$o") . "\n");
        json_out(200, ['ok' => true, 'hned' => true]);
    }
    posli(PRIJEMCE, "[naBrunch] Žádost o správu kavárny: $nazev", "Kavárna: $WEB$url\nWeb kavárny: " . ($kWeb ?: '–') . "\nE-mail kavárny: " . ($kEmail ?: '–')
        . "\n\nŽadatel: $jmeno\nE-mail: $email\nTelefon: " . ($tel ?: '–') . "\n\nE-mail nesedí s kavárnou automaticky – ověřte prosím (např. telefonátem do kavárny).\n\n"
        . "Schválit: $API?akce=schvalit&z=$o&t=" . $podpis("schvalit:$o") . "\nZamítnout: $API?akce=zamitnout&z=$o&t=" . $podpis("zamitnout:$o") . "\n");
    json_out(200, ['ok' => true, 'hned' => false]);
}

if (in_array($akce, ['schvalit', 'zamitnout'], true) && isset($_GET['z'], $_GET['t'])) {
    $o = (int)$_GET['z'];
    if (!hash_equals($podpis("$akce:$o"), (string)$_GET['t'])) html_out('Neplatný odkaz', '');
    html_out(...$spravce($akce, (string)$o));
}

// ---------------------------------------------------------------- úprava ověřeného podniku naBrunche (spravuje redakce)
if ($akce === 'uprava' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['web2'] ?? '')) !== '') json_out(200, ['ok' => true]);
    $limit('uprava', 5);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $jmeno = mb_substr(trim((string)($_POST['jmeno'] ?? '')), 0, 80);
    $tel = mb_substr(trim((string)($_POST['telefon'] ?? '')), 0, 30);
    $zprava = mb_substr(trim((string)($_POST['zprava'] ?? '')), 0, 3000);
    $nazev = mb_substr(trim((string)($_POST['nazev'] ?? '')), 0, 120);
    $url = (string)($_POST['url'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(422, ['ok' => false, 'chyba' => 'Zadejte platný e-mail.']);
    if ($jmeno === '' || mb_strlen($zprava) < 5) json_out(422, ['ok' => false, 'chyba' => 'Vyplňte jméno a napište, co chcete upravit.']);
    if (!preg_match('~^/podniky/[a-z0-9-]+/$~', $url)) json_out(400, ['ok' => false, 'chyba' => 'Neplatný podnik.']);
    $hl = implode("\r\n", ['From: naBrunch.cz <' . PRIJEMCE . '>', "Reply-To: $email", 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit']);
    $ok = nb_odesli(PRIJEMCE, '=?UTF-8?B?' . base64_encode("[naBrunch] Úprava podniku: $nazev") . '?=',
        "Podnik: $WEB$url\nOd: $jmeno, $email" . ($tel ? ", $tel" : '') . "\n\nCo upravit:\n$zprava\n\n(Odpovědí na tento e-mail napíšete přímo žadateli.)\n", $hl);
    if (!$ok) json_out(500, ['ok' => false, 'chyba' => 'Zprávu se nepodařilo odeslat. Napište prosím na ' . PRIJEMCE . '.']);
    json_out(200, ['ok' => true]);
}

// ---------------------------------------------------------------- nová kavárna (kterou ještě nemáme)
function slugify(string $s): string
{
    $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', str_replace('&', ' a ', $s)), '-');
    return substr($s, 0, 80);
}
if ($akce === 'novy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string)($_POST['web2'] ?? '')) !== '') json_out(200, ['ok' => true, 'hned' => false]);
    $limit('novy', 3);
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $jmeno = mb_substr(trim((string)($_POST['jmeno'] ?? '')), 0, 80);
    $tel = mb_substr(trim((string)($_POST['telefon_majitel'] ?? '')), 0, 30);
    $nazev = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['nazev'] ?? ''))), 0, 100);
    $ulice = mb_substr(trim((string)($_POST['ulice'] ?? '')), 0, 120);
    $mesto = mb_substr(trim((string)($_POST['mesto'] ?? '')), 0, 80);
    $psc = preg_replace('/\D/', '', (string)($_POST['psc'] ?? ''));
    $lat = (float)($_POST['lat'] ?? 0);
    $lon = (float)($_POST['lon'] ?? 0);
    $chyby = [];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $chyby[] = 'Zadejte platný e-mail.';
    if ($jmeno === '') $chyby[] = 'Vyplňte své jméno.';
    if (mb_strlen($nazev) < 2) $chyby[] = 'Vyplňte název kavárny.';
    if ($ulice === '' || $mesto === '') $chyby[] = 'Vyplňte ulici s číslem a obec.';
    if ($psc !== '' && strlen($psc) !== 5) $chyby[] = 'PSČ má 5 číslic.';
    if ($lat < 48.5 || $lat > 51.1 || $lon < 12.0 || $lon > 18.9) $chyby[] = 'Označte polohu kavárny na mapě (kliknutím).';
    $v = json_decode((string)($_POST['data'] ?? '{}'), true);
    [$d, $ch2] = validuj(is_array($v) ? $v : []);
    $chyby = array_merge($chyby, $ch2);
    if ($chyby) json_out(422, ['ok' => false, 'chyba' => implode(' ', $chyby)]);
    if (preg_match('~https?://|www\.~i', "$nazev $ulice $mesto")) json_out(422, ['ok' => false, 'chyba' => 'Název a adresa nesmí obsahovat odkazy.']);

    // Stejná kavárna už existuje? (stejný název v okolí 100 m)
    $id = 'm' . substr(bin2hex(random_bytes(5)), 0, 9);
    $mestoNorm = preg_match('/^Praha\b/u', $mesto) ? 'Praha' : $mesto;
    $obvod = $mestoNorm === 'Praha' && preg_match('/^1(\d)0\d\d$/', $psc, $mm) ? 'Praha ' . ($mm[1] === '0' ? '10' : $mm[1]) : null;
    $url = '/kavarny/' . slugify($mestoNorm) . '/' . slugify($nazev) . '/';
    $novy = ['nazev' => $nazev, 'ulice' => $ulice, 'mesto' => $mestoNorm, 'obvod' => $obvod, 'psc' => $psc ?: null, 'lat' => round($lat, 6), 'lon' => round($lon, 6), 'url' => $url];

    // Automatické ověření: e-mail na doméně webu kavárny a web kavárny opravdu zmiňuje její název
    $overeni = null;
    $web = $d['web'] ?? '';
    $domEmail = substr(strrchr($email, '@'), 1);
    if ($web && !preg_match(SDILENE, '.' . domena($web) . '.') && koren($domEmail) === koren(domena($web))) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 1, 'user_agent' => 'nabrunch.cz-overeni/1.0 (+https://nabrunch.cz)']]);
        $html = (string)@file_get_contents($web, false, $ctx, 0, 600000);
        $norm = fn($x) => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
        if ($html && str_contains($norm($html), $norm($nazev))) $overeni = "e-mail na doméně webu kavárny ($domEmail), web uvádí název";
    }
    $duvod = podezrele($d + ['popis' => ($d['popis'] ?? '') . " $nazev"]);
    $stav = $overeni && !$duvod ? 'ok' : 'ceka';
    $ted = date('c');
    $db->prepare('INSERT INTO nove (id, data, stav, vytvoreno) VALUES (?,?,?,?)')->execute([$id, json_encode($novy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $stav, $ted]);
    $db->prepare('INSERT INTO majitele (kavarna, email, jmeno, telefon, stav, overeni, vytvoreno, otisk) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $email, $jmeno, $tel, $stav, $overeni, $ted, $otisk]);
    $o = (int)$db->lastInsertId();
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->prepare('INSERT INTO profily (kavarna, majitel, data, verejna, stav, duvod, upraveno, overeno) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $o, $json, $json, 'ok', $duvod ?: null, $ted, $ted]);
    $navsteva = !empty($_POST['navsteva']) ? "\n\nPODNIK CHCE, ABYCHOM HO NAVŠTÍVILI A ZAŘADILI MEZI DOPORUČENÉ.\n" : '';

    if ($stav === 'ok') {
        posli($email, "Kavárna $nazev je přidaná na naBrunch.cz", "Dobrý den,\n\nkavárnu $nazev jsme přidali, na webu se objeví do 3 hodin:\n$WEB$url\n\n"
            . "Údaje a fotky upravíte tady:\n" . $odkazPrihlaseni($o) . "\n\nOdkaz si uložte, slouží k dalšímu přihlášení.\n\nnaBrunch.cz\n");
        posli(PRIJEMCE, "[naBrunch] Nová kavárna (ověřeno automaticky): $nazev", "$nazev, $ulice, $mesto\nMajitel: $jmeno, $email, $tel\nOvěření: $overeni$navsteva\n"
            . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\nZablokovat a stáhnout: $API?akce=zablokovat&o=$o&t=" . $podpis("zablokovat:$o") . "\n");
        json_out(200, ['ok' => true, 'hned' => true]);
    }
    posli(PRIJEMCE, "[naBrunch] Nová kavárna ke schválení: $nazev", "$nazev, $ulice, $psc $mesto\nPoloha: https://mapy.com/?q=$lat,$lon\nMajitel: $jmeno, $email, $tel"
        . ($duvod ? "\nPodezření: $duvod" : '') . "$navsteva\n\n" . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . "\n\nSchválit (přidat na web a poslat majiteli přístup): $API?akce=schvalit&z=$o&t=" . $podpis("schvalit:$o")
        . "\nZamítnout: $API?akce=zamitnout&z=$o&t=" . $podpis("zamitnout:$o") . "\n");
    json_out(200, ['ok' => true, 'hned' => false]);
}

// ---------------------------------------------------------------- načtení a uložení profilu
if ($akce === 'nacti' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $m = $prihlaseni();
    $st = $db->prepare('SELECT data, stav, duvod FROM profily WHERE kavarna = ?');
    $st->execute([$m['kavarna']]);
    $p = $st->fetch();
    $k = $KAV[$m['kavarna']] ?? [$m['kavarna'], '/'];
    json_out(200, ['ok' => true, 'kavarna' => $m['kavarna'], 'nazev' => $k[0], 'url' => $k[1], 'data' => $p ? json_decode($p['data'], true) : null,
        'stav' => $p['stav'] ?? null, 'fotky' => $fotky($m['kavarna'])]);
}

/** Kontrola a očištění údajů od majitele → [data, chyby] */
function validuj(array $v): array
{
    $txt = fn($x, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$x)), 0, $max);
    $odkaz = function ($x) use ($txt) {
        $x = $txt($x, 300);
        if ($x === '') return '';
        if (!preg_match('~^https?://~i', $x)) $x = 'https://' . $x;
        return filter_var($x, FILTER_VALIDATE_URL) && preg_match('~^https?://[^\s/]+\.[a-z]{2,}~i', $x) ? $x : null;
    };
    $d = [];
    $chyby = [];
    $tel = $txt($v['telefon'] ?? '', 30);
    if ($tel !== '') preg_match('/^\+?[\d\s]{9,20}$/', $tel) ? $d['telefon'] = $tel : $chyby[] = 'Telefon zadejte číslicemi, např. +420 777 123 456.';
    $em = strtolower($txt($v['email'] ?? '', 120));
    if ($em !== '') filter_var($em, FILTER_VALIDATE_EMAIL) ? $d['email'] = $em : $chyby[] = 'Neplatný e-mail kavárny.';
    foreach (['web' => 'Web', 'rezervace' => 'Odkaz na rezervace', 'instagram' => 'Instagram', 'facebook' => 'Facebook'] as $kl => $n) {
        $x = $odkaz($v[$kl] ?? '');
        if ($x === null) $chyby[] = "$n: neplatná adresa.";
        elseif ($x !== '') $d[$kl] = $x;
    }
    if (isset($d['instagram']) && !preg_match('~^https?://(www\.)?instagram\.com/~i', $d['instagram'])) $chyby[] = 'Instagram: zadejte odkaz na instagram.com.';
    if (isset($d['facebook']) && !preg_match('~^https?://([a-z]+\.)?facebook\.com/~i', $d['facebook'])) $chyby[] = 'Facebook: zadejte odkaz na facebook.com.';
    $popis = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', (string)($v['popis'] ?? ''))), 0, MAX_POPIS);
    if (preg_match('~https?://|www\.|@[a-z0-9-]+\.[a-z]~i', $popis)) $chyby[] = 'Popis nesmí obsahovat odkazy ani e-maily.';
    elseif ($popis !== '') $d['popis'] = $popis;
    $doba = $txt($v['oteviraciDoba'] ?? '', 250);
    if ($doba !== '') preg_match('/^[A-Za-z0-9 :;,\-]+$/', $doba) ? $d['oteviraciDoba'] = $doba : $chyby[] = 'Otevírací doba má neplatný formát.';
    $POVOLENE = ['snidane', 'brunch', 'vyberovaKava', 'pekarna', 'dorty', 'wifi', 'zahradka', 'psi', 'bezbarierove', 'karta', 'vegan', 'bezlepek', 'deti'];
    $d['vlastnosti'] = array_values(array_intersect((array)($v['vlastnosti'] ?? []), $POVOLENE));
    return [$d, $chyby];
}

/** Podezřelý obsah → ruční kontrola (vrací důvod, nebo '') */
function podezrele(array $d): string
{
    $text = mb_strtolower(implode(' ', [$d['popis'] ?? '', $d['instagram'] ?? '', $d['facebook'] ?? '']));
    $bez = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
    if (preg_match('~\b(kurv|pic[aeiou]|kokot|debil|hovn|srac|zmrd|curak|prdel|mrd[ae]|buzn|negr|cigan|cikan|idiot|kretén|kreten|fuck|shit|bitch|dick)~', $bez)) return 'nevhodné výrazy';
    if (preg_match('~\b(casino|kasino|viagra|crypto|bitcoin|forex|porn|escort|pujck|loan|bet365|xxx)~', $bez)) return 'podezření na spam';
    if (preg_match('~(.)\1{5,}~u', $d['popis'] ?? '')) return 'opakované znaky';
    $velka = preg_match_all('~\p{Lu}~u', $d['popis'] ?? '');
    $vsech = preg_match_all('~\p{L}~u', $d['popis'] ?? '');
    if ($vsech > 40 && $velka / $vsech > 0.5) return 'text velkými písmeny';
    return '';
}

if ($akce === 'uloz' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $m = $prihlaseni();
    $limit('uloz', 30);
    $v = json_decode((string)($_POST['data'] ?? ''), true);
    if (!is_array($v)) json_out(400, ['ok' => false, 'chyba' => 'Neplatná data.']);
    [$d, $chyby] = validuj($v);
    if ($chyby) json_out(422, ['ok' => false, 'chyba' => implode(' ', $chyby)]);

    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $db->prepare('SELECT * FROM profily WHERE kavarna = ?');
    $st->execute([$m['kavarna']]);
    $p = $st->fetch();
    if ($p && $p['data'] === $json) json_out(200, ['ok' => true, 'stav' => $p['stav']]);
    $duvod = podezrele($d);
    $stav = $duvod ? 'ceka' : 'ok';
    $ted = date('c');
    if ($p) {
        $db->prepare('UPDATE profily SET majitel = ?, data = ?, predchozi = verejna, verejna = CASE WHEN ? = \'ok\' THEN ? ELSE verejna END, stav = ?, duvod = ?, upraveno = ? WHERE kavarna = ?')
            ->execute([$m['id'], $json, $stav, $json, $stav, $duvod, $ted, $m['kavarna']]);
    } else {
        $db->prepare('INSERT INTO profily (kavarna, majitel, data, verejna, stav, duvod, upraveno, overeno) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$m['kavarna'], $m['id'], $json, $stav === 'ok' ? $json : null, $stav, $duvod, $ted, $ted]);
    }
    $k = $KAV[$m['kavarna']] ?? [$m['kavarna'], '/'];
    $s = $m['kavarna'];
    posli(PRIJEMCE, ($duvod ? '[naBrunch] KE SCHVÁLENÍ' : '[naBrunch] Majitel upravil') . " kavárnu {$k[0]}",
        "Kavárna: $WEB{$k[1]}\nUpravil: {$m['email']}\n" . ($duvod ? "\nZadrženo: $duvod\n" : "\nZměna prošla automatickou kontrolou a zveřejní se při další synchronizaci (do 3 hodin).\n")
            . "\n" . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\n"
            . ($duvod ? "Zveřejnit: $API?akce=pustit&s=$s&t=" . $podpis("pustit:$s") . "\n" : '')
            . "Vrátit předchozí verzi: $API?akce=vratit&s=$s&t=" . $podpis("vratit:$s") . "\n"
            . "Zablokovat majitele: $API?akce=zablokovat&o={$m['id']}&t=" . $podpis("zablokovat:{$m['id']}") . "\n");
    json_out(200, ['ok' => true, 'stav' => $stav]);
}

if (in_array($akce, ['pustit', 'vratit'], true) && isset($_GET['s'], $_GET['t'])) {
    $s = (string)$_GET['s'];
    if (!hash_equals($podpis("$akce:$s"), (string)$_GET['t'])) html_out('Neplatný odkaz', '');
    html_out(...$spravce($akce, $s));
}

if ($akce === 'zablokovat' && isset($_GET['o'], $_GET['t'])) {
    $o = (int)$_GET['o'];
    if (!hash_equals($podpis("zablokovat:$o"), (string)$_GET['t'])) html_out('Neplatný odkaz', '');
    html_out(...$spravce('zablokovat', (string)$o));
}

// ---------------------------------------------------------------- fotky
if ($akce === 'foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $m = $prihlaseni();
    $limit('foto', 20);
    if (count($fotky($m['kavarna'])) >= MAX_FOTEK) json_out(422, ['ok' => false, 'chyba' => 'Nejvýš ' . MAX_FOTEK . ' fotek. Nejdřív nějakou smažte.']);
    $f = $_FILES['foto'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 12e6) json_out(422, ['ok' => false, 'chyba' => 'Fotku se nepodařilo nahrát (max. 12 MB).']);
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) json_out(422, ['ok' => false, 'chyba' => 'Podporované formáty: JPG, PNG, WebP.']);
    if (min($info[0], $info[1]) < 500) json_out(422, ['ok' => false, 'chyba' => 'Fotka je moc malá (aspoň 500 px na kratší straně).']);
    if (!function_exists('imagewebp')) json_out(503, ['ok' => false, 'chyba' => 'Server neumí převést fotku.']);
    $img = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']), default => @imagecreatefromwebp($f['tmp_name']) };
    if (!$img) json_out(422, ['ok' => false, 'chyba' => 'Fotku se nepodařilo přečíst.']);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        if ($o === 6) $img = imagerotate($img, -90, 0);
        if ($o === 8) $img = imagerotate($img, 90, 0);
    }
    $dir = $fotkyDir($m['kavarna']);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $jm = bin2hex(random_bytes(6));
    foreach ([1400 => 82, 640 => 78] as $sirka => $q) {
        $w = imagesx($img);
        $h = imagesy($img);
        $nw = min($sirka, $w);
        $nh = (int)round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagewebp($out, "$dir/{$jm}_$sirka.webp", $q);
    }
    $k = $KAV[$m['kavarna']] ?? [$m['kavarna'], '/'];
    $s = $m['kavarna'];
    $cesta = '/fotky-kavaren/' . basename($dir) . "/{$jm}_1400.webp";
    posli(PRIJEMCE, "[naBrunch] Nová fotka: {$k[0]}", "Kavárna: $WEB{$k[1]}\nNahrál: {$m['email']}\nFotka: $WEB$cesta\n\n"
        . "Fotka se zveřejní při další synchronizaci. Smazat: $API?akce=smazatfoto&s=$s&f=$jm&t=" . $podpis("smazatfoto:$s:$jm") . "\n");
    json_out(200, ['ok' => true, 'fotky' => $fotky($m['kavarna'])]);
}
if ($akce === 'smazfoto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $m = $prihlaseni();
    $jm = preg_replace('/[^a-f0-9]/', '', basename((string)($_POST['f'] ?? '')));
    foreach ([1400, 640] as $s) @unlink($fotkyDir($m['kavarna']) . "/{$jm}_$s.webp");
    json_out(200, ['ok' => true, 'fotky' => $fotky($m['kavarna'])]);
}
if ($akce === 'smazatfoto' && isset($_GET['s'], $_GET['f'], $_GET['t'])) {
    $s = (string)$_GET['s'];
    $jm = preg_replace('/[^a-f0-9]/', '', (string)$_GET['f']);
    if (!hash_equals($podpis("smazatfoto:$s:$jm"), (string)$_GET['t'])) html_out('Neplatný odkaz', '');
    html_out(...$spravce('smazatfoto', $s, $jm));
}

// ---------------------------------------------------------------- export pro build
if (isset($_GET['export'])) {
    $out = [];
    foreach ($db->query("SELECT kavarna, verejna, overeno FROM profily WHERE verejna IS NOT NULL AND stav != 'stazeno' ORDER BY kavarna") as $r) {
        $d = json_decode($r['verejna'], true) ?: [];
        $d['overeno'] = substr($r['overeno'], 0, 10);
        $out[$r['kavarna']] = $d;
    }
    // Ověření majitelé bez úprav (jen přihlášení) – kavárna je ověřená, i když zatím nic nezměnili
    foreach ($db->query("SELECT kavarna, MIN(vytvoreno) v FROM majitele WHERE stav = 'ok' GROUP BY kavarna") as $r) {
        if (!isset($out[$r['kavarna']])) $out[$r['kavarna']] = ['overeno' => substr($r['v'], 0, 10)];
    }
    // Nově založené kavárny: jen schválené (ostatní z exportu vypadnou)
    $nove = [];
    foreach ($db->query('SELECT id, data, stav FROM nove') as $r) $nove[$r['id']] = $r;
    foreach ($out as $k => $_) {
        if (isset($nove[$k])) {
            if ($nove[$k]['stav'] !== 'ok') unset($out[$k]);
            else $out[$k]['novy'] = json_decode($nove[$k]['data'], true);
        }
    }
    foreach ($out as $k => &$d) {
        $f = $fotky((string)$k);
        if ($f) $d['fotky'] = $f;
    }
    unset($d);
    json_out(200, ['kavarny' => (object)$out]);
}

// ---------------------------------------------------------------- přehled pro správce → /admin/ (starý odkaz z e-mailů přihlásí)
if (isset($_GET['admin'])) {
    header('Location: ' . $WEB . '/api/admin.php?admin=' . rawurlencode((string)$_GET['admin']), true, 302);
    exit;
}

json_out(400, ['ok' => false]);
