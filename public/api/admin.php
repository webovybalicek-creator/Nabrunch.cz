<?php
/**
 * Administrace naBrunch.cz – schvalování hodnocení a správy kaváren bez proklikávání e-mailů. Adresa: /sprava/ (přepis v .htaccess; /admin/ hosting někdy blokuje kvůli robotům).
 *
 * Přihlášení (bez hesla v repu):
 *  - odkaz „Přehled všech majitelů a změn“ na konci e-mailů pro správce (?admin=<podpis>), nebo
 *  - formulář: e-mail ze seznamu SPRAVCI dostane jednorázový odkaz platný 30 minut.
 * Přihlášení drží cookie 30 dní. Akce jdou přes POST s CSRF tokenem.
 */
declare(strict_types=1);
const NB_ADMIN = true;
/** Kdo si může nechat poslat přihlašovací odkaz */
const SPRAVCI = ['karolina@nabrunch.cz'];
const COOKIE = 'nb_spravce';
const COOKIE_DNY = 30;
const VLASTNOSTI = ['snidane' => 'snídaně', 'brunch' => 'brunch', 'vyberovaKava' => 'výběrová káva', 'pekarna' => 'pekárna', 'dorty' => 'dorty',
    'wifi' => 'Wi-Fi', 'zahradka' => 'zahrádka', 'psi' => 'se psem', 'bezbarierove' => 'bezbariérové', 'karta' => 'platba kartou',
    'vegan' => 'vegan', 'bezlepek' => 'bezlepkové', 'deti' => 'pro děti'];

const JS = <<<'JS'
document.addEventListener('submit', (e) => {
  const f = e.target, q = f.dataset.potvrdit;
  if (q && !confirm(q)) { e.preventDefault(); return; }
  const b = f.querySelector('button');
  if (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); }
});
JS;
const CSS = <<<'CSS'
:root{--hneda:#532a19;--text:#2b211c;--slaba:#7a6a62;--pozadi:#fff5f4;--karta:#fff;--linka:#f0dcd7;--akcent:#c8553d;--zelena:#2f7a4f;--cervena:#b3261e;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text);background:var(--pozadi);-webkit-font-smoothing:antialiased}
main{max-width:60rem;margin:0 auto;padding:1.5rem 1rem 4rem}
.znacka{margin:0;color:var(--slaba);font-size:13px;letter-spacing:.02em}
h1{margin:.1rem 0 1.25rem;font-weight:650;color:var(--hneda);font-size:1.6rem;letter-spacing:-.01em}
h2{margin:2rem 0 .75rem;font-size:1.05rem;color:var(--hneda)}
h3{margin:0;font-size:1.05rem}
a{color:var(--hneda)}
.zalozky{display:flex;gap:.25rem;overflow-x:auto;white-space:nowrap;scrollbar-width:none;border-bottom:1px solid var(--linka);margin-bottom:1rem}
.zalozky a{padding:.55rem .8rem;text-decoration:none;color:var(--slaba);border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s ease}
.zalozky a:hover{color:var(--text)}
.zalozky a[aria-current]{color:var(--hneda);border-color:var(--akcent);font-weight:600}
.zalozky b{display:inline-block;min-width:1.4em;padding:0 .35em;border-radius:99px;background:var(--akcent);color:#fff;font-size:12px;text-align:center}
.zalozky .odhlasit{margin-left:auto}
.karta{background:var(--karta);border:1px solid var(--linka);border-radius:12px;padding:1rem 1.1rem;margin:0 0 .75rem;box-shadow:0 1px 2px rgba(83,42,25,.04)}
.karta header{display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem;margin-bottom:.4rem}
.karta time,small{color:var(--slaba);font-size:13px}
.karta header time{margin-left:auto}
summary{cursor:pointer;display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline}
summary time{margin-left:auto}
.typ{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--akcent);font-weight:600}
.kdo{margin:.6rem 0 0}
.hvezdy{color:#e8893f;letter-spacing:.05em}.hvezdy span{color:#ecdcd5}
.recenze{white-space:pre-wrap;margin:.3rem 0 0;overflow-wrap:anywhere}
.udaje{display:grid;grid-template-columns:max-content 1fr;gap:.25rem 1rem;margin:.5rem 0;font-size:14px}
.udaje dt{color:var(--slaba)}.udaje dd{margin:0;overflow-wrap:anywhere}
.udaje dd.zmena{background:#fff3cd;border-radius:4px;padding:0 .3rem}
.udaje small{display:block}
.akce{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.8rem}
td.akce{margin:0}
form{margin:0}
button{font:inherit;font-size:14px;padding:.45rem .9rem;border-radius:8px;border:1px solid var(--linka);background:#fff;color:var(--text);cursor:pointer;transition:transform .1s ease,background-color .15s ease}
button:hover{background:#fbf0ee}
button:active{transform:scale(.97)}
button:disabled{opacity:.55;cursor:default;transform:none}
button.hlavni{background:var(--zelena);border-color:var(--zelena);color:#fff}
button.hlavni:hover{background:#286a44}
button.nebezpecne{color:var(--cervena);border-color:#f1c9c5}
button:focus-visible,a:focus-visible,input:focus-visible{outline:2px solid var(--akcent);outline-offset:2px}
.ok{background:#e6f4ea;color:#1e4d32;border-radius:8px;padding:.6rem .9rem}
.varovani{background:#fdecea;color:var(--cervena);border-radius:8px;padding:.4rem .7rem;margin:.4rem 0}
.prazdne,.pozn{color:var(--slaba)}
.stav{font-size:12px;padding:.1rem .5rem;border-radius:99px;background:#eee}
.stav-ok{background:#e6f4ea;color:#1e4d32}.stav-ceka{background:#fff3cd;color:#6b4e00}
.stav-blokovan,.stav-stazeno,.stav-zamitnuto{background:#fdecea;color:var(--cervena)}
.tabulka{overflow-x:auto;background:#fff;border:1px solid var(--linka);border-radius:12px}
table{border-collapse:collapse;width:100%;font-size:14px}
th,td{text-align:left;padding:.6rem .8rem;border-bottom:1px solid var(--linka);vertical-align:top}
th{font-weight:600;color:var(--slaba);font-size:13px}
tr:last-child td{border-bottom:0}
.fotky{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem}
.fotky figure{margin:0;background:#fff;border:1px solid var(--linka);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.fotky img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}
.fotky figcaption{padding:.5rem .7rem 0;font-size:14px;display:flex;flex-direction:column}
.fotky form{padding:.5rem .7rem .7rem}
.prihlaseni{display:flex;flex-direction:column;gap:.75rem;max-width:22rem}
.prihlaseni label{display:flex;flex-direction:column;gap:.3rem;font-size:14px;color:var(--slaba)}
input{font:inherit;padding:.55rem .7rem;border:1px solid var(--linka);border-radius:8px;background:#fff}
.prihlaseni button{align-self:flex-start;background:var(--hneda);border-color:var(--hneda);color:#fff}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
@media (max-width:560px){.karta header time,summary time{margin-left:0;width:100%}}
CSS;

require __DIR__ . '/kavarna.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'");

$ADMIN = $WEB . '/sprava/';
$e = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES);

// ---------------------------------------------------------------- přihlášení
$prihlas = function () use ($podpis, $ADMIN): void {
    $exp = time() + COOKIE_DNY * 86400;
    setcookie(COOKIE, $exp . '.' . $podpis("spravce:$exp"), ['expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    $z = preg_replace('/[^a-z]/', '', (string)($_GET['z'] ?? ''));
    header('Location: ' . $ADMIN . ($z ? "?z=$z" : ''), true, 303);
    exit;
};
$cookie = (string)($_COOKIE[COOKIE] ?? '');
[$cExp, $cPodpis] = array_pad(explode('.', $cookie, 2), 2, '');
$prihlasen = ctype_digit($cExp) && (int)$cExp > time() && hash_equals($podpis("spravce:$cExp"), $cPodpis);

// Odkazy z e-mailů: správa kaváren (kavarny-admin) i hodnocení (admin)
if (isset($_GET['admin']) && (hash_equals($podpis('kavarny-admin'), (string)$_GET['admin']) || hash_equals($podpis('admin'), (string)$_GET['admin']))) $prihlas();
if (isset($_GET['vstup'], $_GET['t']) && ctype_digit((string)$_GET['vstup'])) {
    $exp = (int)$_GET['vstup'];
    if ($exp > time() && hash_equals($podpis("vstup:$exp"), (string)$_GET['t'])) $prihlas();
    stranka('Odkaz vypršel', '<p>Přihlašovací odkaz už neplatí. <a href="' . $e($ADMIN) . '">Pošlete si nový.</a></p>');
}
if (isset($_GET['odhlasit'])) {
    setcookie(COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $ADMIN, true, 303);
    exit;
}

if (!$prihlasen) {
    $zprava = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $limit('admin', 5);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (in_array($email, SPRAVCI, true)) {
            $exp = time() + 1800;
            posli($email, 'Přihlášení do administrace naBrunch.cz', "Dobrý den,\n\ndo administrace se přihlásíte tímto odkazem (platí 30 minut):\n\n"
                . $WEB . '/api/admin.php?vstup=' . $exp . '&t=' . $podpis("vstup:$exp") . "\n\nPokud jste o přihlášení nežádali, e-mail ignorujte.\n");
        }
        // Stejná odpověď pro každou adresu – nevyzrazuje, kdo je správce
        $zprava = '<p class="ok">Pokud je adresa mezi správci, přihlašovací odkaz je na cestě.</p>';
    }
    stranka('Administrace', $zprava . '<form method="post" class="prihlaseni"><label>E-mail správce<input type="email" name="email" required autocomplete="email"></label>'
        . '<button>Poslat přihlašovací odkaz</button></form>'
        . '<p class="pozn">Nebo použijte odkaz „Přehled všech majitelů a změn“ z konce e-mailu o kavárně.</p>', false);
}

// ---------------------------------------------------------------- hodnocení podniků (data z api/hodnoceni.php)
$hodSoubor = "$uloziste/hodnoceni.sqlite";
$hdb = is_file($hodSoubor) ? new PDO("sqlite:$hodSoubor", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) : null;
$hodAkce = function (string $a, int $id) use ($hdb, $uloziste): array {
    if (!$hdb || $id < 1) return ['Hodnocení nenalezeno'];
    if ($a === 'hod-schvalit') $hdb->prepare("UPDATE hodnoceni SET stav = 'ok' WHERE id = ?")->execute([$id]);
    elseif ($a === 'hod-zamitnout') $hdb->prepare("UPDATE hodnoceni SET stav = 'zamitnuto' WHERE id = ?")->execute([$id]);
    elseif ($a === 'hod-smazat') $hdb->prepare('DELETE FROM hodnoceni WHERE id = ?')->execute([$id]);
    else return ['Neznámá akce'];
    // Souhrn pro karty a strukturovaná data (stejný formát jako obnov_souhrn v hodnoceni.php)
    $out = new stdClass();
    foreach ($hdb->query('SELECT podnik, ROUND(AVG(hvezdy), 1) p, COUNT(*) n FROM hodnoceni GROUP BY podnik') as $r) $out->{$r['podnik']} = [(float)$r['p'], (int)$r['n']];
    file_put_contents("$uloziste/souhrn.json", json_encode($out), LOCK_EX);
    return [['hod-schvalit' => 'Recenze schválena', 'hod-zamitnout' => 'Text recenze zamítnut', 'hod-smazat' => 'Hodnocení smazáno'][$a]];
};

// ---------------------------------------------------------------- akce (POST + CSRF)
$csrf = $podpis("csrf:$cookie");
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) stranka('Neplatný požadavek', '<p>Obnovte stránku a zkuste to znovu.</p>');
    $a = (string)($_POST['akce'] ?? '');
    [$titulek] = str_starts_with($a, 'hod-') ? $hodAkce($a, (int)($_POST['id'] ?? 0)) : $spravce($a, (string)($_POST['id'] ?? ''), (string)($_POST['f'] ?? ''));
    $zalozka = preg_replace('/[^a-z]/', '', (string)($_POST['zalozka'] ?? ''));
    header('Location: ' . $ADMIN . '?' . http_build_query(array_filter(['z' => $zalozka, 'hotovo' => $titulek])), true, 303);
    exit;
}

// ---------------------------------------------------------------- data
$nazev = fn(string $k): string => $KAV[$k][0] ?? $k;
$url = fn(string $k): string => $WEB . ($KAV[$k][1] ?? '/');
$nove = [];
foreach ($db->query('SELECT * FROM nove') as $r) $nove[$r['id']] = $r + ['d' => json_decode($r['data'], true) ?: []];

$recenzeCeka = $hdb ? $hdb->query("SELECT * FROM hodnoceni WHERE stav = 'ceka' ORDER BY id DESC")->fetchAll() : [];
$zadosti = $db->query("SELECT * FROM majitele WHERE stav = 'ceka' ORDER BY id DESC")->fetchAll();
$zmeny = $db->query("SELECT p.*, m.email FROM profily p LEFT JOIN majitele m ON m.id = p.majitel WHERE p.stav = 'ceka' ORDER BY p.upraveno DESC")->fetchAll();
$majitele = $db->query('SELECT * FROM majitele ORDER BY id DESC LIMIT 500')->fetchAll();
$profily = $db->query("SELECT p.*, m.email FROM profily p LEFT JOIN majitele m ON m.id = p.majitel ORDER BY p.upraveno DESC LIMIT 300")->fetchAll();
$fotkyVse = [];
foreach (glob(dirname(__DIR__) . '/fotky-kavaren/*/*_640.webp') ?: [] as $f) {
    $fotkyVse[] = ['kavarna' => basename(dirname($f)), 'jm' => basename($f, '_640.webp'), 'cas' => filemtime($f)];
}
usort($fotkyVse, fn($a, $b) => $b['cas'] <=> $a['cas']);
$fotkyVse = array_slice($fotkyVse, 0, 120);

// ---------------------------------------------------------------- vykreslení
$tlacitko = function (string $akce, string $id, string $popisek, string $zalozka, string $trida = '', string $f = '', string $potvrdit = '') use ($csrf, $e): string {
    return '<form method="post"' . ($potvrdit ? ' data-potvrdit="' . $e($potvrdit) . '"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="akce" value="' . $e($akce) . '">'
        . '<input type="hidden" name="id" value="' . $e($id) . '"><input type="hidden" name="zalozka" value="' . $e($zalozka) . '">'
        . ($f !== '' ? '<input type="hidden" name="f" value="' . $e($f) . '">' : '')
        . '<button class="' . $e($trida) . '">' . $e($popisek) . '</button></form>';
};
$POPISKY = ['telefon' => 'Telefon', 'email' => 'E-mail', 'web' => 'Web', 'rezervace' => 'Rezervace', 'instagram' => 'Instagram', 'facebook' => 'Facebook',
    'oteviraciDoba' => 'Otevírací doba', 'popis' => 'Popis', 'vlastnosti' => 'Vlastnosti'];
$udaje = function (array $d, array $puvodni = []) use ($POPISKY, $e): string {
    $h = '<dl class="udaje">';
    foreach ($POPISKY as $k => $n) {
        $v = $d[$k] ?? null;
        $p = $puvodni[$k] ?? null;
        if (($v === null || $v === [] || $v === '') && ($p === null || $p === [])) continue;
        $txt = fn($x) => is_array($x) ? implode(', ', array_map(fn($v) => VLASTNOSTI[$v] ?? $v, $x)) : (string)$x;
        $zmena = $puvodni && $txt($v) !== $txt($p);
        $h .= '<dt>' . $e($n) . '</dt><dd' . ($zmena ? ' class="zmena"' : '') . '>' . nl2br($e($txt($v) ?: '–'))
            . ($zmena && $p !== null ? '<small>dřív: ' . $e($txt($p)) . '</small>' : '') . '</dd>';
    }
    return $h . '</dl>';
};
$kdy = fn($c): string => $c ? date('j. n. Y H:i', is_numeric($c) ? (int)$c : strtotime((string)$c)) : '';
$STAVY = ['ok' => 'aktivní', 'ceka' => 'čeká', 'zamitnuto' => 'zamítnuto', 'blokovan' => 'zablokován', 'stazeno' => 'staženo'];
$stav = fn($s): string => '<span class="stav stav-' . $e($s) . '">' . $e($STAVY[$s] ?? $s) . '</span>';

$zal = (string)($_GET['z'] ?? 'schvalit');
$cekaPocet = count($recenzeCeka) + count($zadosti) + count($zmeny);
$ZALOZKY = ['schvalit' => 'Ke schválení' . ($cekaPocet ? " <b>$cekaPocet</b>" : ''), 'hodnoceni' => 'Hodnocení', 'fotky' => 'Fotky', 'majitele' => 'Majitelé', 'profily' => 'Změny kaváren'];
if (!isset($ZALOZKY[$zal])) $zal = 'schvalit';

$h = '<nav class="zalozky">';
foreach ($ZALOZKY as $k => $n) $h .= '<a href="?z=' . $k . '"' . ($k === $zal ? ' aria-current="page"' : '') . '>' . $n . '</a>';
$h .= '<a class="odhlasit" href="?odhlasit=1">Odhlásit</a></nav>';
if (isset($_GET['hotovo'])) $h .= '<p class="ok" role="status">' . $e($_GET['hotovo']) . '</p>';

$PODNIKY = is_file(__DIR__ . '/_podniky-nazvy.php') ? (array)require __DIR__ . '/_podniky-nazvy.php' : [];
$podnikOdkaz = fn(array $r): string => '<a href="' . $e($WEB . $r['url']) . '" target="_blank" rel="noopener">'
    . $e($PODNIKY[$r['podnik']] ?? ucfirst(str_replace('-', ' ', trim(preg_replace('~^/podniky/~', '', (string)$r['url']), '/')))) . '</a>';
$recenze = function (array $r, string $zal) use ($e, $kdy, $tlacitko, $podnikOdkaz): string {
    $n = (int)$r['hvezdy'];
    return '<article class="karta"><header><span class="hvezdy" aria-label="' . $n . ' z 5">' . str_repeat('★', $n) . '<span>' . str_repeat('★', 5 - $n) . '</span></span>'
        . '<h3>' . $podnikOdkaz($r) . '</h3>' . ($r['stav'] !== 'ceka' && $r['text'] ? '<span class="stav stav-' . $e($r['stav']) . '">' . ($r['stav'] === 'ok' ? 'zveřejněno' : 'text skrytý') . '</span>' : '') . '<time>' . $kdy($r['vytvoreno']) . '</time></header>'
        . ($r['text'] ? '<p class="recenze">' . nl2br($e($r['text'])) . '</p>' : '<p class="prazdne">Jen hvězdičky, bez textu.</p>')
        . '<p class="kdo">' . $e($r['jmeno'] ?: 'Anonym') . '</p><div class="akce">'
        . ($r['text'] && $r['stav'] !== 'ok' ? $tlacitko('hod-schvalit', (string)$r['id'], 'Schválit text', $zal, 'hlavni') : '')
        . ($r['text'] && $r['stav'] !== 'zamitnuto' ? $tlacitko('hod-zamitnout', (string)$r['id'], $r['stav'] === 'ok' ? 'Skrýt text' : 'Zamítnout text', $zal) : '')
        . $tlacitko('hod-smazat', (string)$r['id'], 'Smazat i hvězdičky', $zal, 'nebezpecne', '', 'Smazat celé hodnocení včetně hvězdiček?')
        . '</div></article>';
};

if ($zal === 'schvalit') {
    $h .= '<h2>Recenze podniků</h2><p class="pozn">Hvězdičky se počítají hned, na schválení čeká jen text.</p>';
    if (!$recenzeCeka) $h .= '<p class="prazdne">Nic nečeká.</p>';
    foreach ($recenzeCeka as $r) $h .= $recenze($r, $zal);
    $h .= '<h2>Nové kavárny a žádosti o správu</h2>';
    if (!$zadosti) $h .= '<p class="prazdne">Nic nečeká.</p>';
    foreach ($zadosti as $m) {
        $k = $m['kavarna'];
        $n = $nove[$k] ?? null;
        $h .= '<article class="karta"><header><span class="typ">' . ($n ? 'Nová kavárna' : 'Převzetí správy') . '</span>'
            . '<h3>' . ($n ? $e($n['d']['nazev'] ?? $k) : '<a href="' . $e($url($k)) . '" target="_blank" rel="noopener">' . $e($nazev($k)) . '</a>') . '</h3>'
            . '<time>' . $kdy($m['vytvoreno']) . '</time></header>';
        if ($n) {
            $d = $n['d'];
            $h .= '<p>' . $e(trim(($d['ulice'] ?? '') . ', ' . ($d['psc'] ?? '') . ' ' . ($d['mesto'] ?? ''), ', '))
                . ' · <a href="https://mapy.com/?q=' . $e(($d['lat'] ?? '') . ',' . ($d['lon'] ?? '')) . '" target="_blank" rel="noopener">mapa</a></p>';
            $st = $db->prepare('SELECT data, duvod FROM profily WHERE kavarna = ?');
            $st->execute([$k]);
            if ($p = $st->fetch()) {
                $h .= $udaje(json_decode($p['data'], true) ?: []);
                if ($p['duvod']) $h .= '<p class="varovani">Podezření: ' . $e($p['duvod']) . '</p>';
            }
        }
        $h .= '<p class="kdo"><strong>' . $e($m['jmeno']) . '</strong> · <a href="mailto:' . $e($m['email']) . '">' . $e($m['email']) . '</a>'
            . ($m['telefon'] ? ' · <a href="tel:' . $e(preg_replace('/\s/', '', $m['telefon'])) . '">' . $e($m['telefon']) . '</a>' : '') . '</p>'
            . '<div class="akce">' . $tlacitko('schvalit', (string)$m['id'], $n ? 'Schválit a přidat na web' : 'Schválit a poslat přístup', $zal, 'hlavni')
            . $tlacitko('zamitnout', (string)$m['id'], 'Zamítnout', $zal, '', '', 'Opravdu zamítnout?') . '</div></article>';
    }
    $h .= '<h2>Úpravy zadržené kontrolou</h2>';
    if (!$zmeny) $h .= '<p class="prazdne">Nic nečeká.</p>';
    foreach ($zmeny as $p) {
        $k = $p['kavarna'];
        $h .= '<article class="karta"><header><span class="typ">Úprava</span><h3><a href="' . $e($url($k)) . '" target="_blank" rel="noopener">' . $e($nazev($k)) . '</a></h3>'
            . '<time>' . $kdy($p['upraveno']) . '</time></header>'
            . ($p['duvod'] ? '<p class="varovani">Zadrženo: ' . $e($p['duvod']) . '</p>' : '')
            . $udaje(json_decode($p['data'], true) ?: [], json_decode((string)$p['verejna'], true) ?: [])
            . '<p class="kdo">' . $e($p['email']) . '</p><div class="akce">'
            . $tlacitko('pustit', $k, 'Zveřejnit', $zal, 'hlavni') . $tlacitko('vratit', $k, 'Zahodit změnu', $zal)
            . $tlacitko('zablokovat', (string)$p['majitel'], 'Zablokovat majitele', $zal, 'nebezpecne', '', 'Zablokovat majitele a stáhnout jeho údaje i fotky z webu?')
            . '</div></article>';
    }
}

if ($zal === 'hodnoceni') {
    $h .= '<h2>Hodnocení podniků</h2>';
    if ($hdb) {
        $s = $hdb->query("SELECT COUNT(*) n, ROUND(AVG(hvezdy), 2) p, COUNT(DISTINCT podnik) k, SUM(stav = 'ceka') c FROM hodnoceni")->fetch();
        $h .= '<p class="pozn">' . (int)$s['n'] . ' hodnocení u ' . (int)$s['k'] . ' podniků, průměr ' . str_replace('.', ',', (string)($s['p'] ?? 0)) . ' z 5'
            . ((int)$s['c'] ? ' · ke schválení ' . (int)$s['c'] : '') . '.</p>';
        $vse = $hdb->query('SELECT * FROM hodnoceni ORDER BY id DESC LIMIT 300')->fetchAll();
    }
    if (empty($vse)) $h .= '<p class="prazdne">Zatím žádná hodnocení.</p>';
    foreach ($vse ?? [] as $r) $h .= $recenze($r, $zal);
}

if ($zal === 'fotky') {
    $h .= '<h2>Fotky od majitelů</h2><p class="pozn">Nejnovější nahoře. Na web jdou při další synchronizaci (každé 3 hodiny).</p>';
    if (!$fotkyVse) $h .= '<p class="prazdne">Zatím žádné fotky.</p>';
    $h .= '<div class="fotky">';
    foreach ($fotkyVse as $f) {
        $src = '/fotky-kavaren/' . $f['kavarna'] . '/' . $f['jm'];
        $h .= '<figure><a href="' . $e($src . '_1400.webp') . '" target="_blank" rel="noopener"><img src="' . $e($src . '_640.webp') . '" alt="" loading="lazy"></a>'
            . '<figcaption><a href="' . $e($url($f['kavarna'])) . '" target="_blank" rel="noopener">' . $e($nazev($f['kavarna'])) . '</a><time>' . $kdy($f['cas']) . '</time></figcaption>'
            . $tlacitko('smazatfoto', $f['kavarna'], 'Smazat', $zal, 'nebezpecne', $f['jm'], 'Smazat fotku?') . '</figure>';
    }
    $h .= '</div>';
}

if ($zal === 'majitele') {
    $h .= '<h2>Majitelé</h2><div class="tabulka"><table><thead><tr><th>Kavárna</th><th>Majitel</th><th>Ověření</th><th>Stav</th><th></th></tr></thead><tbody>';
    foreach ($majitele as $m) {
        $k = $m['kavarna'];
        $jm = isset($nove[$k]) ? ($nove[$k]['d']['nazev'] ?? $k) . ' (nová)' : $nazev($k);
        $h .= '<tr><td><a href="' . $e($url($k)) . '" target="_blank" rel="noopener">' . $e($jm) . '</a><br><small>' . $kdy($m['vytvoreno']) . '</small></td>'
            . '<td>' . $e($m['jmeno']) . '<br><small>' . $e($m['email']) . ($m['telefon'] ? ' · ' . $e($m['telefon']) : '') . '</small></td>'
            . '<td><small>' . $e($m['overeni'] ?: '–') . '</small></td><td>' . $stav($m['stav']) . '</td><td class="akce">'
            . ($m['stav'] === 'ceka' ? $tlacitko('schvalit', (string)$m['id'], 'Schválit', $zal, 'hlavni') : '')
            . ($m['stav'] !== 'blokovan' ? $tlacitko('zablokovat', (string)$m['id'], 'Zablokovat', $zal, 'nebezpecne', '', 'Zablokovat majitele a stáhnout jeho údaje i fotky z webu?') : '')
            . '</td></tr>';
    }
    $h .= '</tbody></table></div>' . (!$majitele ? '<p class="prazdne">Zatím žádní majitelé.</p>' : '');
}

if ($zal === 'profily') {
    $h .= '<h2>Změny kaváren</h2><p class="pozn">Poslední úpravy od majitelů. „Vrátit předchozí“ obnoví verzi před poslední změnou.</p>';
    if (!$profily) $h .= '<p class="prazdne">Zatím žádné úpravy.</p>';
    foreach ($profily as $p) {
        $k = $p['kavarna'];
        $jm = isset($nove[$k]) ? ($nove[$k]['d']['nazev'] ?? $k) : $nazev($k);
        $h .= '<details class="karta"><summary><strong>' . $e($jm) . '</strong> ' . $stav($p['stav']) . ' <time>' . $kdy($p['upraveno']) . '</time>'
            . ($p['duvod'] ? ' <small>(' . $e($p['duvod']) . ')</small>' : '') . '</summary>'
            . '<p><a href="' . $e($url($k)) . '" target="_blank" rel="noopener">Otevřít na webu</a> · ' . $e($p['email']) . '</p>'
            . $udaje(json_decode($p['data'], true) ?: [], json_decode((string)$p['predchozi'], true) ?: [])
            . '<div class="akce">' . ($p['stav'] === 'ceka' ? $tlacitko('pustit', $k, 'Zveřejnit', $zal, 'hlavni') : '')
            . ($p['predchozi'] !== null ? $tlacitko('vratit', $k, 'Vrátit předchozí', $zal, '', '', 'Vrátit kavárnu na předchozí verzi?') : '') . '</div></details>';
    }
}

stranka('Administrace', $h);

// ---------------------------------------------------------------- šablona
function stranka(string $titulek, string $obsah, bool $menu = true): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($titulek) . ' – naBrunch.cz</title><style>' . CSS . '</style>'
        . '<body><main><p class="znacka">naBrunch.cz · správa</p><h1>' . htmlspecialchars($titulek) . '</h1>' . $obsah . '</main>'
        . '<script>' . JS . '</script></body></html>';
    exit;
}
