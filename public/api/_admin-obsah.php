<?php
/**
 * Editor obsahu v administraci: podniky (src/data/podniky.json), články (src/content/blog/*.md), nastavení tokenu.
 * Načítá ho admin.php (sdílí jeho proměnné: $e, $csrf, $uloziste, $WEB, $ADMIN, $limit…). Každé uložení = jeden commit
 * do main přes GitHub API, web se pak sestaví a nasadí jako po každém pushi (cca 3–5 minut).
 */
declare(strict_types=1);
require_once __DIR__ . '/_github.php';

const OBSAH_ZALOZKY = ['podniky', 'podnik', 'clanky', 'clanek', 'nastaveni'];
const CESTA_PODNIKY = 'src/data/podniky.json';
const CESTA_LOKALITY = 'src/data/lokality.json';
const CESTA_SEO = 'src/data/seo-wp.json';
const CESTA_BLOG = 'src/content/blog/';
const KUCHYNE = ['Klasická', 'Zdravá/fitness', 'Veganská', 'Orientální'];
const ATMOSFERA = ['Sezení uvnitř', 'Zahrádka', 'Instagram-friendly místo', 'Pet-friendly'];
const SEZENI = ['Uvnitř', 'Zahrádka'];
const PLATBA = ['Kartou', 'Hotově'];
const OBDOBI = ['jaro' => 'jaro', 'leto' => 'léto', 'podzim' => 'podzim', 'zima' => 'zima'];

$tokenSoubor = "$uloziste/github-token.txt";
$gh = function () use ($tokenSoubor, $uloziste): ?GitHub {
    $t = is_file($tokenSoubor) ? trim((string)file_get_contents($tokenSoubor)) : '';
    if ($t === '') return null;
    $api = is_file("$uloziste/github-api.txt") ? rtrim(trim((string)file_get_contents("$uloziste/github-api.txt")), '/') : 'https://api.github.com';
    return new GitHub($t, $api);
};
$ted = fn(): DateTime => new DateTime('now', new DateTimeZone('Europe/Prague'));

/** Nahraná fotka → WebP 1400 + 640 px; vrací [cesta v datech, [soubory k commitu]] */
$zpracujFoto = function (array $f, string $zaklad) use ($ted): array {
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Fotka „' . $f['name'] . '“ je moc velká pro server (max. ' . ini_get('upload_max_filesize') . ').');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Fotku „' . $f['name'] . '“ se nepodařilo nahrát.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('„' . $f['name'] . '“: podporované formáty jsou JPG, PNG a WebP.');
    if (!function_exists('imagewebp')) throw new RuntimeException('Server neumí převést fotku do WebP.');
    $img = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']), default => @imagecreatefromwebp($f['tmp_name']) };
    if (!$img) throw new RuntimeException('Fotku „' . $f['name'] . '“ se nepodařilo přečíst.');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        if ($o === 6) $img = imagerotate($img, -90, 0);
        if ($o === 8) $img = imagerotate($img, 90, 0);
    }
    $mesic = $ted()->format('Y/m');
    $jm = substr(nb_slug($zaklad) ?: 'foto', 0, 50) . '-' . bin2hex(random_bytes(3));
    $soubory = [];
    foreach (['obrazky' => [1400, 82], 'nahledy' => [640, 78]] as $slozka => [$sirka, $q]) {
        $w = imagesx($img);
        $h = imagesy($img);
        $nw = min($sirka, $w);
        $nh = (int)round($h * $nw / $w);
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagewebp($out, null, $q);
        $soubory["public/$slozka/$mesic/$jm.webp"] = (string)ob_get_clean();
    }
    return ["/wp-content/uploads/$mesic/$jm.jpg", $soubory];
};
/** Všechny nahrané soubory z pole <input type=file multiple> */
$nahrane = function (string $pole): array {
    $f = $_FILES[$pole] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i]];
    }
    return $out;
};
$nahled = fn(string $cesta): string => preg_replace('~\.(jpe?g|png|webp|gif)$~i', '.webp', preg_replace('~^/wp-content/uploads/~', '/nahledy/', $cesta));
$url = fn(?string $x): ?string => ($x = trim((string)$x)) === '' ? null : (preg_match('~^https?://~i', $x) ? $x : 'https://' . $x);
$platnaUrl = fn(?string $x): bool => $x === null || (bool)filter_var($x, FILTER_VALIDATE_URL);
$radky = fn(string $x): array => array_values(array_filter(array_map('trim', preg_split('/\R/u', $x)), 'strlen'));
$text = fn(string $k, int $max = 300): string => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max);
/** Delší text z formuláře (HTML) – prohlížeč posílá konce řádků jako \r\n, v repu jsou \n */
$html = fn(string $k): string => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
$seznam = fn(string $k): array => array_values(array_filter(array_map('trim', (array)($_POST[$k] ?? [])), 'strlen'));

// ---------------------------------------------------------------- frontmatter článku
/** Rozdělí .md na [[klíč, celý blok řádků], …] a tělo */
function fm_rozdel(string $md): array
{
    if (!preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $md, $m)) return [[], $md];
    $bloky = [];
    foreach (preg_split('/\R/', $m[1]) as $r) {
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*):/', $r, $k)) $bloky[] = [$k[1], $r];
        elseif ($bloky) $bloky[count($bloky) - 1][1] .= "\n" . $r;
    }
    return [$bloky, ltrim($m[2], "\r\n")];
}
function fm_hodnota(array $bloky, string $klic): ?string
{
    foreach ($bloky as [$k, $b]) {
        if ($k !== $klic) continue;
        $v = trim(substr($b, strlen($k) + 1));
        if (str_starts_with($v, '"')) return json_decode($v) ?? trim($v, '"');
        if (str_starts_with($v, "'")) return str_replace("''", "'", substr($v, 1, -1));
        return $v;
    }
    return null;
}
function fm_obdobi(array $bloky): array
{
    foreach ($bloky as [$k, $b]) {
        if ($k === 'obdobi') {
            preg_match_all('/(jaro|leto|podzim|zima)/', $b, $m);
            return $m[1];
        }
    }
    return [];
}
function fm_sestav(array $bloky, array $nastav): string
{
    $q = fn(string $s): string => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hotovo = [];
    $out = [];
    foreach ($bloky as [$k, $b]) {
        if (array_key_exists($k, $nastav)) {
            if (!isset($hotovo[$k]) && $nastav[$k] !== null) $out[] = $k . ': ' . (is_string($nastav[$k]) ? $q($nastav[$k]) : $nastav[$k]['raw']);
            $hotovo[$k] = true;
            continue;
        }
        $out[] = $b;
    }
    foreach ($nastav as $k => $v) {
        if (!isset($hotovo[$k]) && $v !== null) $out[] = $k . ': ' . (is_string($v) ? $q($v) : $v['raw']);
    }
    return "---\n" . implode("\n", $out) . "\n---\n";
}

// ---------------------------------------------------------------- POST
$obsahChyby = [];
$obsahPost = function (string $a) use (&$obsahChyby, $gh, $tokenSoubor, $zpracujFoto, $nahrane, $url, $platnaUrl, $radky, $text, $html, $seznam, $ted, $ADMIN, $limit, $uloziste): void {
    $hotovo = function (string $z, string $zprava, ?string $sha = null) use ($ADMIN): void {
        header('Location: ' . $ADMIN . '?' . http_build_query(['z' => $z, 'hotovo' => $zprava . ($sha ? ' Web se nasadí do 5 minut.' : '')]), true, 303);
        exit;
    };
    if ($a === 'obsah-token') {
        $limit('token', 10);
        $t = trim((string)($_POST['token'] ?? ''));
        if (!preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', $t)) { $obsahChyby[] = 'Tohle nevypadá jako GitHub token (začíná github_pat_…).'; return; }
        $api = is_file("$uloziste/github-api.txt") ? rtrim(trim((string)file_get_contents("$uloziste/github-api.txt")), '/') : 'https://api.github.com';
        if ($ch = (new GitHub($t, $api))->overit()) { $obsahChyby[] = $ch; return; }
        file_put_contents($tokenSoubor, $t);
        @chmod($tokenSoubor, 0600);
        $hotovo('nastaveni', 'Token uložen a ověřen.');
    }
    $g = $gh();
    if (!$g) { $obsahChyby[] = 'Chybí GitHub token – nastavte ho na záložce Nastavení.'; return; }

    // ---- podnik
    if ($a === 'obsah-podnik') {
        $id = (string)($_POST['id'] ?? '');
        $novy = $id === 'novy';
        $nazev = $text('nazev', 120);
        $lokality = $seznam('lokality');
        foreach (preg_split('/\s*,\s*/u', $text('nova_lokalita', 200)) as $l) if ($l !== '' && !in_array($l, $lokality, true)) $lokality[] = $l;
        $lat = trim((string)($_POST['lat'] ?? '')) === '' ? null : (float)str_replace(',', '.', (string)$_POST['lat']);
        $lon = trim((string)($_POST['lon'] ?? '')) === '' ? null : (float)str_replace(',', '.', (string)$_POST['lon']);
        $web = $url($_POST['web'] ?? '');
        $rez = $url($_POST['rezervace'] ?? '');
        $gm = $url($_POST['googleMaps'] ?? '');
        $ig = trim(preg_replace('~^(https?://)?(www\.)?instagram\.com/~i', '', $text('instagram', 120)), "@/ \t");
        $telCisla = preg_replace('/\D/', '', $text('telefon', 30));
        if (strlen($telCisla) === 9) $telCisla = '420' . $telCisla;
        $adresa = $text('adresa', 200);
        $chyby = [];
        if (mb_strlen($nazev) < 2) $chyby[] = 'Vyplňte název.';
        if (!$lokality) $chyby[] = 'Vyberte aspoň jednu lokalitu.';
        if (($lat === null) !== ($lon === null) || ($lat !== null && ($lat < 48.5 || $lat > 51.1 || $lon < 12 || $lon > 18.9))) $chyby[] = 'Souřadnice musí být obě a v Česku (např. 50.0755 a 14.4378).';
        foreach (['Web' => $web, 'Rezervace' => $rez, 'Google Maps' => $gm] as $n => $u) if (!$platnaUrl($u)) $chyby[] = "$n: neplatná adresa.";
        if ($telCisla !== '' && strlen($telCisla) !== 12) $chyby[] = 'Telefon zadejte jako +420 777 123 456.';
        if ($ig !== '' && !preg_match('/^[A-Za-z0-9._]{1,30}$/', $ig)) $chyby[] = 'Instagram zadejte jako @ucet nebo odkaz.';
        $fotky = [];
        foreach ($seznam('fotky') as $f) if (preg_match('~^/wp-content/uploads/[\w/.-]+$~', $f) && !in_array($f, $seznam('odebrat'), true)) $fotky[] = $f;
        $hlavni = (string)($_POST['hlavni'] ?? '');
        $soubory = [];
        try {
            foreach ($nahrane('nove_fotky') as $f) {
                [$cesta, $s] = $zpracujFoto($f, $nazev);
                $fotky[] = $cesta;
                $soubory += $s;
                if ($hlavni === '' || $hlavni === 'nova') $hlavni = $cesta;
            }
        } catch (RuntimeException $ex) {
            $chyby[] = $ex->getMessage();
        }
        if (!$fotky) $chyby[] = 'Podnik potřebuje aspoň jednu fotku.';
        if ($chyby) { $obsahChyby = $chyby; return; }
        if (!in_array($hlavni, $fotky, true)) $hlavni = $fotky[0];
        $galerie = array_values(array_filter($fotky, fn($f) => $f !== $hlavni));
        $tri = fn(string $k): ?bool => ['ano' => true, 'ne' => false][$_POST[$k] ?? ''] ?? null;

        $seoT = $text('seo_t', 200);
        $seoD = $text('seo_d', 400);
        $slugNovy = nb_slug($text('slug', 80) ?: $nazev);
        $vysledek = ['slug' => ''];
        try {
            $sha = $g->uprav(($novy ? 'Přidán podnik ' : 'Upraven podnik ') . $nazev . ' (administrace)', function (callable $cti) use (&$vysledek, $novy, $id, $nazev, $lokality, $adresa, $lat, $lon, $web, $rez, $gm, $ig, $telCisla, $hlavni, $galerie, $soubory, $tri, $seoT, $seoD, $slugNovy, $radky, $seznam, $text, $html, $ted): array {
                $podniky = json_decode((string)$cti(CESTA_PODNIKY), true);
                if (!is_array($podniky)) throw new GitHubChyba('Nepodařilo se načíst podniky.json.');
                $i = null;
                foreach ($podniky as $k => $p) if ((string)$p['id'] === $id) $i = $k;
                if (!$novy && $i === null) throw new GitHubChyba('Podnik v datech nenalezen (mezitím smazán?).');
                if ($novy) {
                    $slug = $slugNovy;
                    $obsazene = array_column($podniky, 'slug');
                    for ($n = 2; in_array($slug, $obsazene, true); $n++) $slug = "$slugNovy-$n";
                    $p = ['id' => max(array_column($podniky, 'id')) + 1, 'slug' => $slug, 'nazev' => '', 'datum' => $ted()->format('Y-m-d H:i:s'), 'doporuceny' => false];
                } else {
                    $p = $podniky[$i];
                }
                $p = array_replace(array_fill_keys(['id', 'slug', 'nazev', 'datum', 'doporuceny', 'lokality', 'adresa', 'lat', 'lon', 'oteviraciDoba', 'web', 'instagram', 'instagramUrl', 'telefon', 'telefonText', 'googleMaps', 'rezervace', 'platba', 'wifi', 'wc', 'kuchyne', 'atmosfera', 'sezeni', 'tipNadpis', 'tip', 'popis', 'seoTitulek', 'seoPopis', 'foto', 'galerie'], null), $p, [
                    'nazev' => $nazev,
                    'doporuceny' => !empty($_POST['doporuceny']),
                    'lokality' => $lokality,
                    'adresa' => $adresa,
                    'lat' => $lat,
                    'lon' => $lon,
                    'oteviraciDoba' => $radky((string)($_POST['oteviraciDoba'] ?? '')),
                    'web' => $web,
                    'instagram' => $ig !== '' ? "@$ig" : null,
                    'instagramUrl' => $ig !== '' ? "https://www.instagram.com/$ig/" : null,
                    'telefon' => $telCisla !== '' ? "+$telCisla" : null,
                    'telefonText' => $telCisla !== '' ? '+' . substr($telCisla, 0, 3) . ' ' . implode(' ', str_split(substr($telCisla, 3), 3)) : null,
                    'googleMaps' => $gm ?? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($nazev . ' ' . $adresa),
                    'rezervace' => $rez,
                    'platba' => array_values(array_intersect(PLATBA, $seznam('platba'))),
                    'wifi' => $tri('wifi'),
                    'wc' => $tri('wc'),
                    'kuchyne' => array_values(array_unique(array_merge(array_intersect(KUCHYNE, $seznam('kuchyne')), array_filter(array_map('trim', explode(',', $text('kuchyne_jine', 200))), 'strlen')))),
                    'atmosfera' => array_values(array_intersect(ATMOSFERA, $seznam('atmosfera'))),
                    'sezeni' => array_values(array_intersect(SEZENI, $seznam('sezeni'))),
                    'tipNadpis' => $text('tipNadpis', 200) ?: null,
                    'tip' => $html('tip') ?: null,
                    'popis' => $html('popis'),
                    'foto' => $hlavni,
                    'galerie' => $galerie,
                ]);
                $cesta = '/podniky/' . $p['slug'] . '/';
                $vysledek['slug'] = $p['slug'];
                $out = $soubory;
                // SEO: záznam v seo-wp.json má přednost – když existuje, upraví se tam
                $seo = json_decode((string)$cti(CESTA_SEO), true) ?: [];
                if (isset($seo[$cesta])) {
                    $seo[$cesta] = array_filter(['t' => $seoT, 'd' => $seoD], 'strlen');
                    if (!$seo[$cesta]) unset($seo[$cesta]);
                    $out[CESTA_SEO] = nb_json($seo) . "\n";
                } else {
                    $p['seoTitulek'] = $seoT ?: null;
                    $p['seoPopis'] = $seoD ?: null;
                }
                if ($novy) array_unshift($podniky, $p); else $podniky[$i] = $p;
                $out[CESTA_PODNIKY] = nb_json($podniky) . "\n";
                // Nové lokality do lokality.json
                $lok = json_decode((string)$cti(CESTA_LOKALITY), true) ?: [];
                $zmena = false;
                foreach ($lokality as $l) if (!isset($lok[$l])) { $lok[$l] = nb_slug($l); $zmena = true; }
                if ($zmena) {
                    if (class_exists('Collator')) { $col = new Collator('cs_CZ'); uksort($lok, fn($a, $b) => $col->compare((string)$a, (string)$b)); }
                    $out[CESTA_LOKALITY] = nb_json($lok);
                }
                return $out;
            });
        } catch (GitHubChyba $ex) {
            $obsahChyby[] = $ex->getMessage();
            return;
        }
        $hotovo('podniky', ($novy ? 'Podnik přidán' : 'Podnik uložen') . " (/podniky/{$vysledek['slug']}/).", $sha);
    }

    // ---- článek
    if ($a === 'obsah-clanek') {
        $slug = (string)($_POST['slug_puvodni'] ?? '');
        $novy = $slug === '';
        $titulek = $text('title', 200);
        $datum = (string)($_POST['date'] ?? '');
        $chyby = [];
        if (mb_strlen($titulek) < 3) $chyby[] = 'Vyplňte nadpis.';
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(:\d\d)?$/', $datum)) $chyby[] = 'Neplatné datum.';
        if ($novy) {
            $slug = nb_slug($text('slug', 80) ?: $titulek);
            if (strlen($slug) < 3) $chyby[] = 'Adresa článku je moc krátká.';
            elseif (is_dir(dirname(__DIR__) . "/$slug") || in_array($slug, ['api', 'podniky', 'kavarny', 'lokalita', 'blog', 'prihlaseni', 'sprava', 'admin'], true)) $chyby[] = "Adresa /$slug/ už na webu existuje, zvolte jinou.";
        } elseif (!preg_match('/^[a-z0-9-]+$/', $slug)) $chyby[] = 'Neplatný článek.';
        $obrazek = null;
        $soubory = [];
        try {
            foreach ($nahrane('obrazek') as $f) [$obrazek, $soubory] = $zpracujFoto($f, $slug);
        } catch (RuntimeException $ex) {
            $chyby[] = $ex->getMessage();
        }
        if ($chyby) { $obsahChyby = $chyby; return; }
        $obrazek ??= preg_match('~^/wp-content/uploads/[\w/.-]+$~', (string)($_POST['image'] ?? '')) ? (string)$_POST['image'] : null;
        $obdobi = array_values(array_intersect(array_keys(OBDOBI), $seznam('obdobi')));
        $telo = str_replace("\r\n", "\n", (string)($_POST['telo'] ?? ''));
        $seoT = $text('seo_t', 200);
        $seoD = $text('seo_d', 400);
        try {
            $sha = $g->uprav(($novy ? 'Nový článek: ' : 'Upraven článek: ') . $titulek . ' (administrace)', function (callable $cti) use ($novy, $slug, $titulek, $datum, $obrazek, $soubory, $obdobi, $telo, $seoT, $seoD, $text): array {
                $cesta = CESTA_BLOG . "$slug.md";
                $puvodni = $cti($cesta);
                if ($novy && $puvodni !== null) throw new GitHubChyba("Článek /$slug/ už existuje.");
                if (!$novy && $puvodni === null) throw new GitHubChyba('Článek v repozitáři nenalezen.');
                [$bloky] = $puvodni !== null ? fm_rozdel($puvodni) : [[]];
                $out = $soubory;
                $seo = json_decode((string)$cti(CESTA_SEO), true) ?: [];
                $seoKlic = "/$slug/";
                $nastav = [
                    'title' => $titulek,
                    'description' => $text('description', 300) ?: null,
                    'seoTitle' => $text('seoTitle', 200) ?: null,
                    'date' => strlen($datum) === 16 ? "$datum:00" : $datum,
                    'image' => $obrazek,
                    'obdobi' => $obdobi ? ['raw' => '[' . implode(', ', $obdobi) . ']'] : null,
                    'naHp' => empty($_POST['naHp']) ? ['raw' => 'false'] : null,
                ];
                if (isset($seo[$seoKlic])) {
                    $seo[$seoKlic] = array_filter(['t' => $seoT, 'd' => $seoD], 'strlen');
                    if (!$seo[$seoKlic]) unset($seo[$seoKlic]);
                    $out[CESTA_SEO] = nb_json($seo) . "\n";
                }
                $out[$cesta] = fm_sestav($bloky, $nastav) . "\n" . rtrim($telo) . "\n";
                return $out;
            });
        } catch (GitHubChyba $ex) {
            $obsahChyby[] = $ex->getMessage();
            return;
        }
        $hotovo('clanky', ($novy ? 'Článek přidán' : 'Článek uložen') . " (/$slug/).", $sha);
    }
};

// ---------------------------------------------------------------- stránky
$obsahStranka = function (string $zal, string $nav, string $hotovoHtml) use (&$obsahChyby, $gh, $e, $csrf, $nahled, $tokenSoubor, $WEB): void {
    $h = $nav . $hotovoHtml;
    if ($obsahChyby) $h .= '<p class="varovani" role="alert">' . $e(implode(' ', $obsahChyby)) . '</p>';
    $pole = fn(string $jm, string $popisek, $hodnota, string $typ = 'text', string $extra = '', string $pozn = '') =>
        '<label><span>' . $e($popisek) . ($pozn ? ' <small>' . $e($pozn) . '</small>' : '') . '</span><input type="' . $typ . '" name="' . $jm . '" value="' . $e($hodnota ?? '') . '"' . $extra . '></label>';
    $oblast = fn(string $jm, string $popisek, $hodnota, int $rows = 6, string $pozn = '') =>
        '<label><span>' . $e($popisek) . ($pozn ? ' <small>' . $e($pozn) . '</small>' : '') . '</span><textarea name="' . $jm . '" rows="' . $rows . '">' . $e($hodnota ?? '') . '</textarea></label>';
    $zaskrt = function (string $jm, array $moznosti, array $vybrane) use ($e): string {
        $h = '<div class="vlastnosti">';
        foreach ($moznosti as $k => $n) {
            $hod = is_int($k) ? $n : $k;
            $h .= '<label><input type="checkbox" name="' . $jm . '[]" value="' . $e($hod) . '"' . (in_array($hod, $vybrane, true) ? ' checked' : '') . '> ' . $e($n) . '</label>';
        }
        return $h . '</div>';
    };
    $formZac = '<form method="post" enctype="multipart/form-data" class="uprava"><input type="hidden" name="csrf" value="' . $e($csrf) . '">';
    $po = $_SERVER['REQUEST_METHOD'] === 'POST';
    $g = $gh();

    if ($zal === 'nastaveni') {
        $stav = $g ? (($ch = $g->overit()) ? '<p class="varovani">Token je uložený, ale nefunguje: ' . $e($ch) . '</p>' : '<p class="ok">GitHub token je nastavený a funguje. Editor podniků a článků je připravený.</p>')
            : '<p class="varovani">GitHub token zatím není nastavený – bez něj editor podniků a článků nemůže ukládat.</p>';
        $h .= '<h2>Napojení na GitHub</h2>' . $stav
            . '<p class="pozn">Editor ukládá změny přímo do repozitáře <strong>' . GH_REPO . '</strong> (větev main) a web se pak sám sestaví a nasadí.</p>'
            . '<ol class="pozn"><li>Na GitHubu otevřete <strong>Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token</strong>.</li>'
            . '<li>Resource owner: <strong>webovybalicek-creator</strong>, Repository access: <strong>Only select repositories → nabrunch.cz</strong>.</li>'
            . '<li>Permissions → Repository permissions → <strong>Contents: Read and write</strong> (nic jiného není potřeba). Platnost klidně 1 rok.</li>'
            . '<li>Token (začíná <code>github_pat_</code>) vložte sem. Uloží se jen na server, do repa nejde.</li></ol>'
            . $formZac . '<input type="hidden" name="akce" value="obsah-token"><fieldset><legend>' . ($g ? 'Vyměnit token' : 'Vložit token') . '</legend>'
            . '<label><span>GitHub token</span><input type="password" name="token" autocomplete="off" required placeholder="github_pat_…"></label></fieldset>'
            . '<div class="akce"><button class="hlavni">Uložit a ověřit</button></div></form>';
        stranka('Administrace', $h);
    }
    if (!$g) {
        stranka('Administrace', $h . '<p class="varovani">Pro úpravy podniků a článků nejdřív nastavte GitHub token na záložce <a href="?z=nastaveni">Nastavení</a>.</p>');
    }

    // ---- seznam podniků
    if ($zal === 'podniky') {
        try {
            $podniky = json_decode((string)$g->soubor(CESTA_PODNIKY, $g->hlava()), true) ?: [];
        } catch (GitHubChyba $ex) {
            stranka('Administrace', $h . '<p class="varovani">' . $e($ex->getMessage()) . '</p>');
        }
        usort($podniky, fn($a, $b) => strcmp($b['datum'], $a['datum']));
        $h .= '<div class="hlavicka"><h2>Podniky (' . count($podniky) . ')</h2><a class="tlacitko hlavni-odkaz" href="?z=podnik&id=novy">+ Přidat podnik</a></div>'
            . '<input type="search" class="hledat" placeholder="Hledat podnik nebo město…" data-hledat-v=".radky tr" aria-label="Hledat podnik">'
            . '<div class="tabulka"><table><thead><tr><th>Podnik</th><th>Lokalita</th><th>Přidán</th><th></th></tr></thead><tbody class="radky">';
        foreach ($podniky as $p) {
            $h .= '<tr><td><img class="mini" src="' . $e($nahled($p['foto'])) . '" alt="" loading="lazy"><a href="?z=podnik&id=' . $p['id'] . '"><strong>' . $e($p['nazev']) . '</strong></a>'
                . ($p['doporuceny'] ? ' <span class="stav stav-ok">srdcovka</span>' : '') . '</td><td>' . $e(implode(', ', $p['lokality'])) . '</td>'
                . '<td><small>' . $e(substr($p['datum'], 0, 10)) . '</small></td><td class="akce"><a class="tlacitko" href="?z=podnik&id=' . $p['id'] . '">Upravit</a>'
                . '<a class="tlacitko" href="' . $e($WEB . '/podniky/' . $p['slug'] . '/') . '" target="_blank" rel="noopener">Na webu</a></td></tr>';
        }
        stranka('Administrace', $h . '</tbody></table></div>');
    }

    // ---- formulář podniku
    if ($zal === 'podnik') {
        $id = (string)($_GET['id'] ?? $_POST['id'] ?? 'novy');
        try {
            $hlava = $g->hlava();
            $podniky = json_decode((string)$g->soubor(CESTA_PODNIKY, $hlava), true) ?: [];
            $lokality = array_keys(json_decode((string)$g->soubor(CESTA_LOKALITY, $hlava), true) ?: []);
            foreach ($podniky as $x) foreach ($x['lokality'] as $l) if (!in_array($l, $lokality, true)) $lokality[] = $l;
            if (class_exists('Collator')) (new Collator('cs_CZ'))->sort($lokality); else sort($lokality);
            $seo = json_decode((string)$g->soubor(CESTA_SEO, $hlava), true) ?: [];
        } catch (GitHubChyba $ex) {
            stranka('Administrace', $h . '<p class="varovani">' . $e($ex->getMessage()) . '</p>');
        }
        $p = null;
        foreach ($podniky as $x) if ((string)$x['id'] === $id) $p = $x;
        if ($id !== 'novy' && !$p) stranka('Administrace', $h . '<p class="varovani">Podnik nenalezen.</p>');
        $p ??= ['nazev' => '', 'slug' => '', 'lokality' => [], 'adresa' => '', 'lat' => null, 'lon' => null, 'oteviraciDoba' => [], 'web' => null, 'instagram' => null,
            'telefonText' => null, 'googleMaps' => null, 'rezervace' => null, 'platba' => ['Kartou', 'Hotově'], 'wifi' => null, 'wc' => null, 'kuchyne' => [], 'atmosfera' => ['Sezení uvnitř'],
            'sezeni' => [], 'tipNadpis' => 'Náš brunch tip', 'tip' => '', 'popis' => '', 'seoTitulek' => null, 'seoPopis' => null, 'foto' => '', 'galerie' => [], 'doporuceny' => false];
        $seoKlic = '/podniky/' . $p['slug'] . '/';
        $seoWp = $p['slug'] !== '' && isset($seo[$seoKlic]);
        $seoT = $seoWp ? ($seo[$seoKlic]['t'] ?? '') : ($p['seoTitulek'] ?? '');
        $seoD = $seoWp ? ($seo[$seoKlic]['d'] ?? '') : ($p['seoPopis'] ?? '');
        if ($po) { // po chybě vrátit, co bylo vyplněno
            $v = fn($k, $d = '') => $_POST[$k] ?? $d;
            $p = array_merge($p, ['nazev' => $v('nazev'), 'lokality' => (array)$v('lokality', []), 'adresa' => $v('adresa'), 'lat' => $v('lat'), 'lon' => $v('lon'),
                'oteviraciDoba' => preg_split('/\R/', (string)$v('oteviraciDoba')), 'web' => $v('web'), 'instagram' => $v('instagram'), 'telefonText' => $v('telefon'), 'googleMaps' => $v('googleMaps'),
                'rezervace' => $v('rezervace'), 'platba' => (array)$v('platba', []), 'kuchyne' => (array)$v('kuchyne', []), 'atmosfera' => (array)$v('atmosfera', []), 'sezeni' => (array)$v('sezeni', []),
                'tipNadpis' => $v('tipNadpis'), 'tip' => $v('tip'), 'popis' => $v('popis'), 'doporuceny' => !empty($_POST['doporuceny'])]);
            $seoT = $v('seo_t');
            $seoD = $v('seo_d');
        }
        $troj = fn(string $jm, $hod) => '<select name="' . $jm . '"><option value="">neuvedeno</option><option value="ano"' . ($hod === true || $hod === 'ano' ? ' selected' : '') . '>ano</option><option value="ne"' . ($hod === false || $hod === 'ne' ? ' selected' : '') . '>ne</option></select>';
        $jine = array_diff($p['kuchyne'], KUCHYNE);
        $fotky = array_values(array_filter([$p['foto'], ...$p['galerie']]));
        $h .= '<p><a href="?z=podniky">← Všechny podniky</a>' . ($id !== 'novy' ? ' · <a href="' . $e($WEB . '/podniky/' . $p['slug'] . '/') . '" target="_blank" rel="noopener">Otevřít na webu</a>' : '') . '</p>'
            . $formZac . '<input type="hidden" name="akce" value="obsah-podnik"><input type="hidden" name="id" value="' . $e($id) . '">'
            . '<fieldset><legend>Základ</legend>' . $pole('nazev', 'Název', $p['nazev'], 'text', ' required maxlength="120"')
            . ($id === 'novy' ? $pole('slug', 'Adresa stránky', '', 'text', ' pattern="[a-z0-9-]*" placeholder="vytvoří se z názvu"', '/podniky/…/ – později už nejde měnit') : '<p class="pozn">Adresa: /podniky/' . $e($p['slug']) . '/ (nemění se, zná ji Google)</p>')
            . '<label class="radek-check"><input type="checkbox" name="doporuceny" value="1"' . ($p['doporuceny'] ? ' checked' : '') . '> Naše srdcovka (doporučený podnik)</label>'
            . '<span class="popisek">Lokality</span>' . $zaskrt('lokality', $lokality, $p['lokality'])
            . $pole('nova_lokalita', 'Nová lokalita', '', 'text', ' placeholder="např. Praha 6, Kolín"', 'u Prahy obvod i „Praha“; víc oddělte čárkou') . '</fieldset>'
            . '<fieldset><legend>Adresa a kontakty</legend>' . $pole('adresa', 'Adresa', $p['adresa'], 'text', ' maxlength="200"')
            . '<div class="radek">' . $pole('lat', 'Zeměpisná šířka', $p['lat'], 'text', ' inputmode="decimal" placeholder="50.0755"') . $pole('lon', 'Zeměpisná délka', $p['lon'], 'text', ' inputmode="decimal" placeholder="14.4378"') . '</div>'
            . '<p class="pozn">Souřadnice: v Google Maps pravým tlačítkem na místo → první řádek (čísla zkopírujete kliknutím).</p>'
            . $oblast('oteviraciDoba', 'Otevírací doba', implode("\n", array_filter($p['oteviraciDoba'])), 4, 'každý řádek zvlášť, např. „Po - Pá: 8:00 - 18:00“, „So - Ne: 9:00 - 16:00“')
            . '<div class="radek">' . $pole('telefon', 'Telefon', $p['telefonText'], 'tel') . $pole('instagram', 'Instagram', $p['instagram'], 'text', ' placeholder="@ucet"') . '</div>'
            . '<div class="radek">' . $pole('web', 'Web', $p['web']) . $pole('rezervace', 'Rezervace', $p['rezervace']) . '</div>'
            . $pole('googleMaps', 'Odkaz do Google Maps', $p['googleMaps'], 'text', '', 'prázdné = vytvoří se z názvu a adresy') . '</fieldset>'
            . '<fieldset><legend>Vlastnosti</legend><span class="popisek">Kuchyně</span>' . $zaskrt('kuchyne', KUCHYNE, $p['kuchyne'])
            . $pole('kuchyne_jine', 'Jiná kuchyně', implode(', ', $jine), 'text', '', 'oddělte čárkou')
            . '<span class="popisek">Atmosféra</span>' . $zaskrt('atmosfera', ATMOSFERA, $p['atmosfera'])
            . '<span class="popisek">Sezení</span>' . $zaskrt('sezeni', SEZENI, $p['sezeni'])
            . '<span class="popisek">Platba</span>' . $zaskrt('platba', PLATBA, $p['platba'])
            . '<div class="radek"><label><span>Wi-Fi</span>' . $troj('wifi', $po ? ($_POST['wifi'] ?? '') : $p['wifi']) . '</label><label><span>WC</span>' . $troj('wc', $po ? ($_POST['wc'] ?? '') : $p['wc']) . '</label></div></fieldset>'
            . '<fieldset><legend>Fotky</legend><div class="fotky-edit">';
        foreach ($fotky as $i => $f) {
            $h .= '<figure><img src="' . $e($nahled($f)) . '" alt="" loading="lazy"><input type="hidden" name="fotky[]" value="' . $e($f) . '">'
                . '<label><input type="radio" name="hlavni" value="' . $e($f) . '"' . ($i === 0 ? ' checked' : '') . '> hlavní</label>'
                . '<label><input type="checkbox" name="odebrat[]" value="' . $e($f) . '"> odebrat</label></figure>';
        }
        $h .= '</div><label><span>Přidat fotky <small>JPG, PNG nebo WebP; převedou se na WebP. ' . (!$fotky ? 'První nahraná bude hlavní.' : '') . '</small></span>'
            . '<input type="file" name="nove_fotky[]" accept="image/jpeg,image/png,image/webp" multiple' . (!$fotky ? ' required' : '') . '></label>'
            . ($fotky ? '<label class="radek-check"><input type="radio" name="hlavni" value="nova"> první nově nahraná bude hlavní</label>' : '') . '</fieldset>'
            . '<fieldset><legend>Texty</legend>' . $oblast('popis', 'Popis', $p['popis'], 14, 'HTML: <p>, <h2>, <strong>, <a href="…">')
            . $pole('tipNadpis', 'Nadpis tipu', $p['tipNadpis']) . $oblast('tip', 'Náš tip', $p['tip'], 3, 'HTML') . '</fieldset>'
            . '<fieldset><legend>Vyhledávače</legend>' . ($seoWp ? '<p class="pozn">Titulek a popis jsou v <code>seo-wp.json</code> (převzaté z WordPressu) – upravují se tam.</p>' : '')
            . $pole('seo_t', 'Titulek pro Google', $seoT, 'text', ' maxlength="200"', 'prázdné = „Název – snídaně a brunch v …“')
            . $oblast('seo_d', 'Popis pro Google', $seoD, 2, 'cca 150 znaků') . '</fieldset>'
            . '<div class="akce lepkave"><button class="hlavni">' . ($id === 'novy' ? 'Přidat podnik' : 'Uložit a nasadit') . '</button><a class="tlacitko" href="?z=podniky">Zrušit</a></div></form>';
        stranka($id === 'novy' ? 'Nový podnik' : 'Upravit: ' . $p['nazev'], $h);
    }

    // ---- seznam článků
    if ($zal === 'clanky') {
        $clanky = is_file(__DIR__ . '/_clanky-seznam.php') ? (array)require __DIR__ . '/_clanky-seznam.php' : [];
        $h .= '<div class="hlavicka"><h2>Články (' . count($clanky) . ')</h2><a class="tlacitko hlavni-odkaz" href="?z=clanek&slug=">+ Nový článek</a></div>'
            . '<input type="search" class="hledat" placeholder="Hledat článek…" data-hledat-v=".radky tr" aria-label="Hledat článek">'
            . '<div class="tabulka"><table><thead><tr><th>Článek</th><th>Datum</th><th></th></tr></thead><tbody class="radky">';
        foreach ($clanky as $c) {
            $h .= '<tr><td><a href="?z=clanek&slug=' . $e($c['slug']) . '"><strong>' . $e($c['title']) . '</strong></a><br><small>/' . $e($c['slug']) . '/</small></td>'
                . '<td><small>' . $e(substr($c['date'], 0, 10)) . '</small></td><td class="akce"><a class="tlacitko" href="?z=clanek&slug=' . $e($c['slug']) . '">Upravit</a>'
                . '<a class="tlacitko" href="' . $e($WEB . '/' . $c['slug'] . '/') . '" target="_blank" rel="noopener">Na webu</a></td></tr>';
        }
        stranka('Administrace', $h . '</tbody></table></div><p class="pozn">Nově uložené články se v seznamu objeví po nasazení webu.</p>');
    }

    // ---- formulář článku
    if ($zal === 'clanek') {
        $slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['slug'] ?? $_POST['slug_puvodni'] ?? ''));
        $bloky = [];
        $telo = '';
        $seo = [];
        try {
            $hlava = $g->hlava();
            if ($slug !== '') {
                $md = $g->soubor(CESTA_BLOG . "$slug.md", $hlava);
                if ($md === null) stranka('Administrace', $h . '<p class="varovani">Článek nenalezen.</p>');
                [$bloky, $telo] = fm_rozdel($md);
            }
            $seo = json_decode((string)$g->soubor(CESTA_SEO, $hlava), true) ?: [];
        } catch (GitHubChyba $ex) {
            stranka('Administrace', $h . '<p class="varovani">' . $e($ex->getMessage()) . '</p>');
        }
        $c = [
            'title' => fm_hodnota($bloky, 'title') ?? '', 'description' => fm_hodnota($bloky, 'description') ?? '', 'seoTitle' => fm_hodnota($bloky, 'seoTitle') ?? '',
            'date' => substr(fm_hodnota($bloky, 'date') ?? (new DateTime('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d\TH:i:s'), 0, 19),
            'image' => fm_hodnota($bloky, 'image') ?? '', 'naHp' => fm_hodnota($bloky, 'naHp') !== 'false', 'obdobi' => fm_obdobi($bloky), 'telo' => $telo,
        ];
        $seoWp = $slug !== '' && isset($seo["/$slug/"]);
        $seoT = $seoWp ? ($seo["/$slug/"]['t'] ?? '') : '';
        $seoD = $seoWp ? ($seo["/$slug/"]['d'] ?? '') : '';
        if ($po) {
            foreach (['title', 'description', 'seoTitle', 'date', 'image'] as $k) $c[$k] = (string)($_POST[$k] ?? '');
            $c['telo'] = (string)($_POST['telo'] ?? '');
            $c['naHp'] = !empty($_POST['naHp']);
            $c['obdobi'] = (array)($_POST['obdobi'] ?? []);
            $seoT = (string)($_POST['seo_t'] ?? '');
            $seoD = (string)($_POST['seo_d'] ?? '');
        }
        $h .= '<p><a href="?z=clanky">← Všechny články</a>' . ($slug !== '' ? ' · <a href="' . $e("$WEB/$slug/") . '" target="_blank" rel="noopener">Otevřít na webu</a>' : '') . '</p>'
            . $formZac . '<input type="hidden" name="akce" value="obsah-clanek"><input type="hidden" name="slug_puvodni" value="' . $e($slug) . '">'
            . '<fieldset><legend>Článek</legend>' . $pole('title', 'Nadpis', $c['title'], 'text', ' required maxlength="200"')
            . ($slug === '' ? $pole('slug', 'Adresa', '', 'text', ' pattern="[a-z0-9-]*" placeholder="vytvoří se z nadpisu"', 'nabrunch.cz/…/ – později už nejde měnit') : '<p class="pozn">Adresa: /' . $e($slug) . '/</p>')
            . '<div class="radek">' . $pole('date', 'Datum', $c['date'], 'datetime-local', ' required step="1"') . '</div>'
            . '<label class="radek-check"><input type="checkbox" name="naHp" value="1"' . ($c['naHp'] ? ' checked' : '') . '> Zobrazovat v „Brunch tipech“ na úvodní stránce</label>'
            . '<span class="popisek">Hodí se hlavně v období <small>(bonus v Brunch tipech)</small></span>' . $zaskrt('obdobi', OBDOBI, $c['obdobi'])
            . ($bloky && array_filter($bloky, fn($b) => $b[0] === 'sezona') ? '<p class="pozn">Článek má sezónní okno (sezona) – zůstává beze změny.</p>' : '') . '</fieldset>'
            . '<fieldset><legend>Úvodní obrázek</legend>' . ($c['image'] ? '<img class="nahled-clanku" src="' . $e($nahled($c['image'])) . '" alt="">' : '')
            . '<input type="hidden" name="image" value="' . $e($c['image']) . '"><label><span>' . ($c['image'] ? 'Vyměnit obrázek' : 'Nahrát obrázek') . ' <small>JPG, PNG nebo WebP</small></span>'
            . '<input type="file" name="obrazek" accept="image/jpeg,image/png,image/webp"></label></fieldset>'
            . '<fieldset><legend>Text</legend>' . $oblast('telo', 'Obsah článku', $c['telo'], 24, 'HTML (<p>, <h2>, <ul><li>, <a href="…">, <strong>) nebo Markdown') . '</fieldset>'
            . '<fieldset><legend>Vyhledávače</legend>' . $oblast('description', 'Popis pro Google', $c['description'], 2, 'cca 150 znaků')
            . $pole('seoTitle', 'Titulek pro Google', $c['seoTitle'], 'text', ' maxlength="200"', 'nepovinné, jinak nadpis')
            . ($seoWp ? '<p class="pozn">Pro tuto stránku platí titulek a popis ze <code>seo-wp.json</code> (mají přednost):</p>' . $pole('seo_t', 'Titulek (seo-wp.json)', $seoT) . $oblast('seo_d', 'Popis (seo-wp.json)', $seoD, 2) : '')
            . '</fieldset><div class="akce lepkave"><button class="hlavni">' . ($slug === '' ? 'Přidat článek' : 'Uložit a nasadit') . '</button><a class="tlacitko" href="?z=clanky">Zrušit</a></div></form>';
        stranka($slug === '' ? 'Nový článek' : 'Upravit: ' . $c['title'], $h);
    }
};
