<?php
/**
 * Přihlášení k odběru newsletteru → Ecomail (seznam ECOMAIL_LIST).
 * POST: email, web2 (past na roboty), t (čas načtení), zdroj (stránka)
 * API klíč není v repozitáři: deploy ho ze secretu ECOMAIL_API_KEY zapíše do api/_konfig.php
 * (přímý přístup zakazuje .htaccess). Ecomail podle nastavení seznamu pošle potvrzovací e-mail (double opt-in).
 */
declare(strict_types=1);

const ECOMAIL_LIST = 1;
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
$cas = (int)($_POST['t'] ?? 0);
if (trim((string)($_POST['web2'] ?? '')) !== '' || ($cas > 0 && time() - $cas < 2)) out(200, ['ok' => true]);

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) out(422, ['ok' => false, 'chyba' => 'Zkontroluj prosím e-mail.']);

$otisk = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('YmdH'), __FILE__);
$limitSoubor = sys_get_temp_dir() . '/nabrunch-odber-' . substr($otisk, 0, 24);
$pocet = is_file($limitSoubor) ? (int)file_get_contents($limitSoubor) : 0;
if ($pocet >= LIMIT_ZA_HODINU) out(429, ['ok' => false, 'chyba' => 'Příliš mnoho pokusů. Zkus to prosím později.']);
@file_put_contents($limitSoubor, (string)($pocet + 1));

$konfig = is_file(__DIR__ . '/_konfig.php') ? (array)require __DIR__ . '/_konfig.php' : [];
$klic = $konfig['ecomail'] ?? '';
if ($klic === '') out(503, ['ok' => false, 'chyba' => 'Odběr je dočasně nedostupný.']);

$zdroj = mb_substr(preg_replace('~[^a-z0-9/_-]~i', '', (string)($_POST['zdroj'] ?? '')), 0, 120);
$data = json_encode([
    'subscriber_data' => ['email' => $email, 'source' => 'web' . ($zdroj ? ":$zdroj" : '')],
    'trigger_autoresponders' => true,
    'update_existing' => true,
    'resubscribe' => false,
    'skip_confirmation' => false,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api2.ecomailapp.cz/lists/' . ECOMAIL_LIST . '/subscribe');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => ['key: ' . $klic, 'Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$odpoved = curl_exec($ch);
$kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($kod >= 200 && $kod < 300) out(200, ['ok' => true]);
error_log("nabrunch odber: Ecomail HTTP $kod " . substr((string)$odpoved, 0, 300));
out(502, ['ok' => false, 'chyba' => 'Přihlášení se nepovedlo. Zkus to prosím za chvíli.']);
