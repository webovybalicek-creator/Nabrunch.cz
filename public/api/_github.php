<?php
/**
 * Zápis do repozitáře přes GitHub API (pro editor obsahu v administraci).
 * Změny jdou jedním commitem do main → GitHub Action „Build a deploy“ web sestaví a nasadí jako po každém pushi.
 *
 * Token (fine-grained, jen repo nabrunch.cz, oprávnění Contents: Read and write) se vkládá v administraci
 * na záložce Nastavení a ukládá do /_data/github-token.txt (mimo repo, přímý přístup zakazuje .htaccess).
 */
declare(strict_types=1);

const GH_REPO = 'webovybalicek-creator/nabrunch.cz';
const GH_VETEV = 'main';

final class GitHubChyba extends RuntimeException {}

final class GitHub
{
    public function __construct(private string $token, private string $api = 'https://api.github.com') {}

    /** @return array{0:int,1:mixed} [HTTP kód, tělo (pole z JSON, nebo surový text)] */
    public function pozadavek(string $metoda, string $cesta, ?array $data = null, bool $surove = false): array
    {
        $ch = curl_init($this->api . $cesta);
        $hlavicky = [
            'Authorization: Bearer ' . $this->token,
            'Accept: ' . ($surove ? 'application/vnd.github.raw' : 'application/vnd.github+json'),
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: nabrunch.cz-administrace',
        ];
        if ($data !== null) $hlavicky[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metoda,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hlavicky,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $telo = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $chyba = curl_error($ch);
        curl_close($ch);
        if ($telo === false) throw new GitHubChyba('GitHub není dostupný: ' . $chyba);
        return [$kod, $surove && $kod === 200 ? $telo : json_decode((string)$telo, true)];
    }

    private function musi(array $odpoved, int $ocekavam, string $co): array
    {
        [$kod, $telo] = $odpoved;
        if ($kod !== $ocekavam) {
            $zprava = is_array($telo) ? ($telo['message'] ?? '') : '';
            throw new GitHubChyba("GitHub odmítl $co (HTTP $kod" . ($zprava ? ": $zprava" : '') . ').');
        }
        return $telo;
    }

    /** Ověří token: vrací null, nebo popis chyby */
    public function overit(): ?string
    {
        try {
            [$kod, $telo] = $this->pozadavek('GET', '/repos/' . GH_REPO);
        } catch (GitHubChyba $e) {
            return $e->getMessage();
        }
        if ($kod === 401) return 'Token je neplatný nebo vypršel.';
        if ($kod === 404) return 'Token nemá přístup k repozitáři ' . GH_REPO . '.';
        if ($kod !== 200) return "GitHub vrátil HTTP $kod.";
        if (empty($telo['permissions']['push'])) return 'Token nemá právo zápisu (Contents: Read and write).';
        return null;
    }

    public function hlava(): string
    {
        return $this->musi($this->pozadavek('GET', '/repos/' . GH_REPO . '/git/ref/heads/' . GH_VETEV), 200, 'čtení větve')['object']['sha'];
    }

    /** Obsah souboru v daném commitu, null když neexistuje */
    public function soubor(string $cesta, string $commit): ?string
    {
        [$kod, $telo] = $this->pozadavek('GET', '/repos/' . GH_REPO . '/contents/' . str_replace('%2F', '/', rawurlencode($cesta)) . '?ref=' . $commit, null, true);
        if ($kod === 404) return null;
        if ($kod !== 200) throw new GitHubChyba("Soubor $cesta se nepodařilo načíst (HTTP $kod).");
        return (string)$telo;
    }

    /**
     * Jeden commit se všemi změnami. $soubory: cesta => obsah (string), nebo null = smazat.
     * Vrací sha nového commitu; když mezitím přibyl jiný commit, vyhodí GitHubKonflikt.
     */
    public function commit(string $zakladni, array $soubory, string $zprava): string
    {
        $base = $this->musi($this->pozadavek('GET', '/repos/' . GH_REPO . '/git/commits/' . $zakladni), 200, 'čtení commitu');
        $strom = [];
        foreach ($soubory as $cesta => $obsah) {
            if ($obsah === null) {
                $strom[] = ['path' => $cesta, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                continue;
            }
            $blob = $this->musi($this->pozadavek('POST', '/repos/' . GH_REPO . '/git/blobs', ['content' => base64_encode($obsah), 'encoding' => 'base64']), 201, 'uložení souboru');
            $strom[] = ['path' => $cesta, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
        }
        $tree = $this->musi($this->pozadavek('POST', '/repos/' . GH_REPO . '/git/trees', ['base_tree' => $base['tree']['sha'], 'tree' => $strom]), 201, 'vytvoření stromu');
        $c = $this->musi($this->pozadavek('POST', '/repos/' . GH_REPO . '/git/commits', ['message' => $zprava, 'tree' => $tree['sha'], 'parents' => [$zakladni]]), 201, 'vytvoření commitu');
        [$kod, $telo] = $this->pozadavek('PATCH', '/repos/' . GH_REPO . '/git/refs/heads/' . GH_VETEV, ['sha' => $c['sha'], 'force' => false]);
        if ($kod === 422) throw new GitHubKonflikt('Mezitím přibyla jiná změna.');
        $this->musi([$kod, $telo], 200, 'zápis do větve');
        return $c['sha'];
    }

    /**
     * Načte aktuální stav, nechá $zmeny vrátit soubory k zápisu a udělá commit.
     * Při souběžné změně (např. synchronizace kaváren) to zkusí znovu nad novým stavem.
     * $zmeny(callable $cti): array  – $cti(cesta) vrací obsah souboru v aktuálním commitu.
     */
    public function uprav(string $zprava, callable $zmeny): string
    {
        for ($pokus = 1; ; $pokus++) {
            $hlava = $this->hlava();
            $cache = [];
            $cti = function (string $cesta) use (&$cache, $hlava): ?string {
                return $cache[$cesta] ??= $this->soubor($cesta, $hlava);
            };
            $soubory = $zmeny($cti);
            if (!$soubory) return $hlava;
            try {
                return $this->commit($hlava, $soubory, $zprava);
            } catch (GitHubKonflikt $e) {
                if ($pokus >= 3) throw new GitHubChyba('Do repozitáře se právě zapisuje něco jiného, zkuste to prosím za chvíli znovu.');
                usleep(700000);
            }
        }
    }
}
final class GitHubKonflikt extends RuntimeException {}

/** JSON ve stejném formátu jako soubory v repu (Python json.dumps(ensure_ascii=False, indent=1)) */
function nb_json($v, int $odsazeni = 1, int $uroven = 0): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION;
    if (!is_array($v)) return json_encode($v, $flags);
    if ($v === []) return '[]';
    $odsaz = str_repeat(' ', $odsazeni * ($uroven + 1));
    $konec = str_repeat(' ', $odsazeni * $uroven);
    $polozky = [];
    if (array_is_list($v)) {
        foreach ($v as $x) $polozky[] = $odsaz . nb_json($x, $odsazeni, $uroven + 1);
        return "[\n" . implode(",\n", $polozky) . "\n$konec]";
    }
    foreach ($v as $k => $x) $polozky[] = $odsaz . json_encode((string)$k, $flags) . ': ' . nb_json($x, $odsazeni, $uroven + 1);
    return "{\n" . implode(",\n", $polozky) . "\n$konec}";
}

/** Slug jako src/lib/slug.ts */
function nb_slug(string $s): string
{
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($s, Normalizer::FORM_D) ?: $s);
    } else {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    }
    $s = strtolower($s);
    $s = str_replace('&', ' a ', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim($s, '-'), 0, 80);
}
