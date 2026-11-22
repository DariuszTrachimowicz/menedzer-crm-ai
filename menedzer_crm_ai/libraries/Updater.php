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
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'menedzer-crm-ai/' . MCAI_VERSION,
            CURLOPT_MAXFILESIZE => self::MAX_ZIP,
            // Liczymy odebrane bajty sami: starsze libcurl nie pilnują limitu bez nagłówka rozmiaru.
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > self::MAX_ZIP) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (stripos($line, 'location:') === 0) {
                    $headers['location'] = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $location = $headers['location'] ?? null;
        return $ok === false ? null : $body;
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

    /**
     * Pobiera paczkę, sprawdza ją i podmienia katalog modułu. Poprzednia wersja zostaje
     * do końca instalacji tabel i wraca na miejsce, gdy którykolwiek krok się nie uda.
     */
    public static function update(string $version): void
    {
        $fail = fn () => new Failure('update_failed', _l('mcai_update_failed'));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !class_exists('ZipArchive')) {
            throw $fail();
        }
        if (!Store::lock('update', 0)) {
            throw $fail(); // trwa inna aktualizacja
        }
        $dir = dirname(__DIR__);
        $parent = dirname($dir);
        $new = $parent . '/menedzer_crm_ai.new-' . bin2hex(random_bytes(4));
        $old = $parent . '/menedzer_crm_ai.old-' . date('YmdHis');
        $tmp = tempnam(sys_get_temp_dir(), 'mcai');
        $swapped = false;
        // Do końca aktualizacji i ewentualnego przywracania błąd SQL wraca jako false, a nie kończy procesu.
        $db = Store::db();
        $debug = $db->db_debug;
        $db->db_debug = false;
        try {
            $zip = self::get('https://github.com/' . self::REPO . "/releases/download/v$version/menedzer_crm_ai-$version.zip", true, $status);
            if ($status !== 200 || $zip === null || strlen($zip) < 100) {
                throw $fail();
            }
            file_put_contents($tmp, $zip);
            $za = new \ZipArchive();
            if ($za->open($tmp) !== true || $za->numFiles < 1 || $za->numFiles > 500) {
                throw $fail();
            }
            $total = 0;
            $hasMain = false;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $st = $za->statIndex($i);
                $name = $st['name'];
                $total += $st['size'];
                if (!str_starts_with($name, 'menedzer_crm_ai/') || str_contains($name, '..') || str_contains($name, '\\') || $st['size'] > 5242880 || $total > 20971520) {
                    throw $fail();
                }
                $hasMain = $hasMain || $name === 'menedzer_crm_ai/menedzer_crm_ai.php';
            }
            $main = $hasMain ? $za->getFromName('menedzer_crm_ai/menedzer_crm_ai.php') : false;
            if (!is_string($main) || !preg_match('/^Version:\s*' . preg_quote($version, '/') . '\s*$/m', $main)) {
                throw $fail();
            }
            if (!mkdir($new, 0755) || !$za->extractTo($new)) {
                throw $fail();
            }
            $za->close();
            if (!rename($dir, $old)) {
                throw $fail();
            }
            $swapped = true;
            if (!rename($new . '/menedzer_crm_ai', $dir)) {
                throw $fail();
            }
            // Nowe tabele (CREATE IF NOT EXISTS) z kodu nowej wersji; install.php rzuca wyjątek przy błędzie SQL.
            // db_debug wyłączony, żeby błąd SQL wrócił jako false, a nie zakończył procesu przed przywróceniem.
            require $dir . '/install.php';
            if (!$db->where('module_name', MCAI_MODULE)->update(db_prefix() . 'modules', ['installed_version' => $version])) {
                throw $fail();
            }
            $saved = $db->select('installed_version')->where('module_name', MCAI_MODULE)->get(db_prefix() . 'modules')->row_array();
            if (($saved['installed_version'] ?? '') !== $version) {
                throw $fail();
            }
            update_option('mcai_latest', Store::json(['checked' => 0, 'version' => '']));
            $swapped = false;
            self::remove($old);
        } catch (\Throwable $e) {
            if ($swapped) {
                // Przywrócenie poprzedniej wersji w miejsce nieudanej: najpierw pliki, potem numer w bazie.
                $failed = $new . '-failed';
                if (is_dir($dir) && !rename($dir, $failed)) {
                    log_message('error', 'Menedzer CRM AI: nie można odsunąć nieudanej wersji; poprzednia jest w ' . $old);
                    throw new Failure('update_failed', _l('mcai_update_restore') . ' ' . $old);
                }
                if (!rename($old, $dir)) {
                    log_message('error', 'Menedzer CRM AI: nie można przywrócić poprzedniej wersji z ' . $old);
                    throw new Failure('update_failed', _l('mcai_update_restore') . ' ' . $old);
                }
                self::remove($failed);
                if (!$db->where('module_name', MCAI_MODULE)->update(db_prefix() . 'modules', ['installed_version' => MCAI_VERSION])) {
                    log_message('error', 'Menedzer CRM AI: pliki przywrócone, ale numer wersji w bazie nie; ustaw installed_version=' . MCAI_VERSION);
                }
            }
            throw $e instanceof Failure ? $e : $fail();
        } finally {
            $db->db_debug = $debug;
            @unlink($tmp);
            self::remove($new);
            Store::unlock('update');
        }
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
