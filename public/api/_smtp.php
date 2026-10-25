<?php
/**
 * Minimální odesílání e-mailu přes SMTP (SSL, AUTH LOGIN) – stejné nastavení jako WP Mail SMTP na WordPressu.
 * Heslo není v repozitáři: deploy ho ze secretu SMTP_PASSWORD zapíše do api/_konfig.php.
 * Přímý přístup k souborům _*.php v /api/ zakazuje .htaccess.
 */
declare(strict_types=1);

const SMTP_HOST = 'ssl://smtp.websupport.cz';
const SMTP_PORT = 465;
const SMTP_UZIVATEL = 'karolina@nabrunch.cz';

function nb_konfig(): array
{
    static $k = null;
    if ($k === null) $k = is_file(__DIR__ . '/_konfig.php') ? (array)require __DIR__ . '/_konfig.php' : [];
    return $k;
}

/**
 * Odešle hotovou zprávu (hlavičky + tělo) přes SMTP. Bez hesla použije PHP mail().
 * @param string $komu příjemce
 * @param string $predmet zakódovaný předmět (=?UTF-8?B?…?=)
 * @param string $telo tělo zprávy
 * @param string $hlavicky hlavičky oddělené \r\n (bez To/Subject)
 */
function nb_odesli(string $komu, string $predmet, string $telo, string $hlavicky): bool
{
    $heslo = nb_konfig()['smtp_heslo'] ?? '';
    if ($heslo === '') return @mail($komu, $predmet, $telo, $hlavicky, '-f' . SMTP_UZIVATEL);

    $s = @stream_socket_client(SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!$s) {
        error_log("nabrunch smtp: spojení selhalo $errno $errstr");
        return false;
    }
    stream_set_timeout($s, 20);
    $cti = function () use ($s): string {
        $out = '';
        while (($r = fgets($s, 515)) !== false) {
            $out .= $r;
            if (isset($r[3]) && $r[3] === ' ') break;
        }
        return $out;
    };
    $prikaz = function (string $c, array $ocekavam) use ($s, $cti): bool {
        if ($c !== '') fwrite($s, $c . "\r\n");
        $r = $cti();
        $ok = in_array((int)substr($r, 0, 3), $ocekavam, true);
        if (!$ok) error_log('nabrunch smtp: ' . (str_starts_with($c, 'AUTH') || strlen($c) > 60 ? '[auth]' : $c) . ' → ' . trim($r));
        return $ok;
    };
    $ok = $prikaz('', [220])
        && $prikaz('EHLO nabrunch.cz', [250])
        && $prikaz('AUTH LOGIN', [334])
        && $prikaz(base64_encode(SMTP_UZIVATEL), [334])
        && $prikaz(base64_encode($heslo), [235])
        && $prikaz('MAIL FROM:<' . SMTP_UZIVATEL . '>', [250])
        && $prikaz('RCPT TO:<' . $komu . '>', [250, 251])
        && $prikaz('DATA', [354]);
    if ($ok) {
        $zprava = "To: <$komu>\r\nSubject: $predmet\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@nabrunch.cz>\r\n"
            . $hlavicky . "\r\n\r\n" . $telo;
        $zprava = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $zprava));
        $ok = $prikaz($zprava . "\r\n.", [250]);
    }
    @fwrite($s, "QUIT\r\n");
    fclose($s);
    return $ok;
}
