<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/** Aktualizacja modułu z wydań na GitHubie (paczka menedzer_crm_ai-X.Y.Z.zip). */
final class Updater
{
    public const REPO = 'DariuszTrachimowicz/menedzer-crm-ai';
    private const MAX_ZIP = 10485760;

    private static function get(string $url, bool $follow, ?int &$status = null, ?string &$location = null): ?string
    {
        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'menedzer-crm-ai/' . MCAI_VERSION,
            CURLOPT_MAXFILESIZE => self::MAX_ZIP,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (stripos($line, 'location:') === 0) {
                    $headers['location'] = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $location = $headers['location'] ?? null;
        return is_string($body) ? $body : null;
    }

    /** Najnowsza wersja z przekierowania releases/latest (bez API i jego limitu). Pamięć 12 godzin. */
    public static function latest(bool $force = false): ?string
    {
        $cached = json_decode((string) get_option('mcai_latest'), true);
        if (!$force && is_array($cached) && ($cached['checked'] ?? 0) > time() - 43200) {
            return $cached['version'] ?: null;
        }
        self::get('https://github.com/' . self::REPO . '/releases/latest', false, $status, $location);
        $version = $location && preg_match('~/releases/tag/v(\d+\.\d+\.\d+)$~', $location, $m) ? $m[1] : '';
        $value = Store::json(['checked' => time(), 'version' => $version]);
        if (get_option('mcai_latest') === '') {
            add_option('mcai_latest', $value, 0);
        } else {
            update_option('mcai_latest', $value);
        }
        return $version ?: null;
    }

    public static function available(): ?string
    {
        $latest = self::latest();
        return $latest && version_compare($latest, MCAI_VERSION, '>') ? $latest : null;
    }

    /** Pobiera paczkę, sprawdza jej zawartość i podmienia katalog modułu w dwóch szybkich krokach rename. */
    public static function update(string $version): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !class_exists('ZipArchive')) {
            throw new Failure('update_failed', _l('mcai_update_failed'));
        }
        $zip = self::get('https://github.com/' . self::REPO . "/releases/download/v$version/menedzer_crm_ai-$version.zip", true, $status);
        if ($status !== 200 || $zip === null || strlen($zip) < 100) {
            throw new Failure('update_failed', _l('mcai_update_failed'));
        }
        $parent = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'mcai');
        file_put_contents($tmp, $zip);
        $new = $parent . '/menedzer_crm_ai.new-' . bin2hex(random_bytes(4));
        $old = $parent . '/menedzer_crm_ai.old-' . date('YmdHis');
        try {
            $za = new \ZipArchive();
            if ($za->open($tmp) !== true) {
                throw new Failure('update_failed', _l('mcai_update_failed'));
            }
            $hasMain = false;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $name = $za->getNameIndex($i);
                if (!str_starts_with($name, 'menedzer_crm_ai/') || str_contains($name, '..') || str_contains($name, '\\')) {
                    throw new Failure('update_failed', _l('mcai_update_failed'));
                }
                $hasMain = $hasMain || $name === 'menedzer_crm_ai/menedzer_crm_ai.php';
            }
            $main = $za->getFromName('menedzer_crm_ai/menedzer_crm_ai.php');
            if (!$hasMain || !is_string($main) || !preg_match('/^Version:\s*' . preg_quote($version, '/') . '\s*$/m', $main)) {
                throw new Failure('update_failed', _l('mcai_update_failed'));
            }
            mkdir($new, 0755);
            if (!$za->extractTo($new)) {
                throw new Failure('update_failed', _l('mcai_update_failed'));
            }
            $za->close();
            if (!rename(dirname(__DIR__), $old)) {
                throw new Failure('update_failed', _l('mcai_update_failed'));
            }
            if (!rename($new . '/menedzer_crm_ai', dirname(__DIR__))) {
                rename($old, dirname(__DIR__)); // przywrócenie starej wersji
                throw new Failure('update_failed', _l('mcai_update_failed'));
            }
        } finally {
            @unlink($tmp);
            self::remove($new);
        }
        self::remove($old);
        // Nowe tabele (CREATE IF NOT EXISTS) i numer wersji w Perfexie.
        require dirname(__DIR__) . '/install.php';
        Store::db()->where('module_name', MCAI_MODULE)->update(db_prefix() . 'modules', ['installed_version' => $version]);
        update_option('mcai_latest', Store::json(['checked' => 0, 'version' => '']));
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
