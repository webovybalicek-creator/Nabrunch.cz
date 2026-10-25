<?php
/**
 * Formulář „Přidat podnik“ → e-mail redakci s fotkami v příloze.
 * POST: nazev, popis, adresa, googlemaps, doba, telefon, email, web, instagram, rezervace,
 *       platba[], wifi, wc, kuchyne[], atmosfera[], tipnadpis, tip, foto (soubor), galerie[] (soubory)
 *       web2 (past na roboty), t (čas načtení formuláře)
 * Odpověď JSON {ok: true} nebo {ok: false, chyba: "…"}.
 * Limit: 5 odeslání za hodinu z jedné IP (ukládá se jen HMAC otisk IP na hodinu, v dočasné složce).
 */
declare(strict_types=1);

const PRIJEMCE = 'karolina@nabrunch.cz';
const ODESILATEL = 'no-reply@nabrunch.cz';
const MAX_SOUBOR = 3 * 1024 * 1024;
const MAX_GALERIE = 6;
const LIMIT_ZA_HODINU = 5;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'chyba' => 'Použijte formulář.']);

// Roboti: vyplněná past nebo odeslání dřív než za 5 s → tváříme se, že je vše v pořádku
$cas = (int)($_POST['t'] ?? 0);
if (trim((string)($_POST['web2'] ?? '')) !== '' || ($cas > 0 && time() - $cas < 5)) out(200, ['ok' => true]);

// Limit odeslání
$otisk = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'), __FILE__);
$limitSoubor = sys_get_temp_dir() . '/nabrunch-podnik-' . substr($otisk, 0, 24);
$pocet = is_file($limitSoubor) ? (int)file_get_contents($limitSoubor) : 0;
if ($pocet >= LIMIT_ZA_HODINU) out(429, ['ok' => false, 'chyba' => 'Příliš mnoho odeslání. Zkuste to prosím za hodinu.']);

$t = fn(string $k, int $max = 2000): string => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $max);
$pole = fn(string $k): array => array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 60), (array)($_POST[$k] ?? []))));

$d = [
    'Název podniku' => $t('nazev', 120),
    'Adresa' => $t('adresa', 200),
    'Google Maps' => $t('googlemaps', 300),
    'Otevírací doba' => $t('doba', 500),
    'Telefon' => $t('telefon', 40),
    'E-mail' => $t('email', 120),
    'Web' => $t('web', 300),
    'Instagram' => $t('instagram', 120),
    'Rezervace' => $t('rezervace', 200),
    'Platba' => implode(', ', $pole('platba')),
    'Wi-Fi' => $t('wifi', 10),
    'WC' => $t('wc', 10),
    'Kuchyně' => implode(', ', $pole('kuchyne')),
    'Atmosféra' => implode(', ', $pole('atmosfera')),
    'Tip – nadpis' => $t('tipnadpis', 80),
    'Tip – text' => $t('tip', 600),
    'Popis' => $t('popis', 4000),
];
foreach (['Název podniku', 'Adresa', 'Google Maps', 'Otevírací doba', 'Telefon', 'E-mail', 'Popis'] as $povinne) {
    if ($d[$povinne] === '') out(422, ['ok' => false, 'chyba' => "Vyplňte prosím pole „{$povinne}“."]);
}
if (!filter_var($d['E-mail'], FILTER_VALIDATE_EMAIL)) out(422, ['ok' => false, 'chyba' => 'Zkontrolujte prosím e-mail.']);

// Fotky: jen obrázky do 3 MB
$prilohy = [];
$pridej = function (string $tmp, string $jmeno, int $chyba, int $velikost) use (&$prilohy): void {
    if ($chyba === UPLOAD_ERR_NO_FILE) return;
    if ($chyba !== UPLOAD_ERR_OK || $velikost > MAX_SOUBOR) out(422, ['ok' => false, 'chyba' => 'Některou fotku se nepodařilo nahrát (max. 3 MB).']);
    $typ = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($typ, ['image/jpeg', 'image/png', 'image/webp'], true)) out(422, ['ok' => false, 'chyba' => 'Fotky nahrajte prosím jako JPG, PNG nebo WebP.']);
    $bezpecne = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($jmeno)) ?: 'foto';
    $prilohy[] = ['jmeno' => $bezpecne, 'typ' => $typ, 'data' => file_get_contents($tmp)];
};
if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    out(422, ['ok' => false, 'chyba' => 'Nahrajte prosím náhledový obrázek.']);
}
$pridej($_FILES['foto']['tmp_name'], 'nahled-' . $_FILES['foto']['name'], (int)$_FILES['foto']['error'], (int)$_FILES['foto']['size']);
if (!empty($_FILES['galerie']['name']) && is_array($_FILES['galerie']['name'])) {
    $g = $_FILES['galerie'];
    if (count(array_filter($g['name'])) > MAX_GALERIE) out(422, ['ok' => false, 'chyba' => 'Do galerie můžete nahrát nejvýš 6 fotek.']);
    foreach ($g['name'] as $i => $jmeno) {
        $pridej($g['tmp_name'][$i], 'galerie-' . $jmeno, (int)$g['error'][$i], (int)$g['size'][$i]);
    }
}

// E-mail (multipart s přílohami)
$text = "Nový podnik z formuláře na nabrunch.cz\n\n";
foreach ($d as $k => $v) $text .= $k . ":\n" . ($v === '' ? '–' : $v) . "\n\n";
$text .= 'Odesláno: ' . date('j. n. Y H:i') . "\n";

$hranice = 'nb' . bin2hex(random_bytes(12));
$telo = "--$hranice\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($text)) . "\r\n";
foreach ($prilohy as $p) {
    $telo .= "--$hranice\r\nContent-Type: {$p['typ']}; name=\"{$p['jmeno']}\"\r\nContent-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"{$p['jmeno']}\"\r\n\r\n" . chunk_split(base64_encode($p['data'])) . "\r\n";
}
$telo .= "--$hranice--\r\n";

$predmet = '=?UTF-8?B?' . base64_encode('[naBrunch] Nový podnik: ' . $d['Název podniku']) . '?=';
$hlavicky = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode('naBrunch.cz') . '?= <' . ODESILATEL . '>',
    'Reply-To: ' . $d['E-mail'],
    'MIME-Version: 1.0',
    "Content-Type: multipart/mixed; boundary=\"$hranice\"",
]);

if (!@mail(PRIJEMCE, $predmet, $telo, $hlavicky, '-f' . ODESILATEL)) {
    out(500, ['ok' => false, 'chyba' => 'E-mail se nepodařilo odeslat. Napište nám prosím přímo na ' . PRIJEMCE . '.']);
}
@file_put_contents($limitSoubor, (string)($pocet + 1));
out(200, ['ok' => true]);
