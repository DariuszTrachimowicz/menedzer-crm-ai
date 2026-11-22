<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Oauth.php';
require_once __DIR__ . '/Resources.php';
require_once __DIR__ . '/Operations.php';
require_once __DIR__ . '/Tools.php';
require_once __DIR__ . '/Server.php';
require_once __DIR__ . '/Updater.php';

final class Failure extends \RuntimeException
{
    public function __construct(public string $codeName, string $message = '', public int $status = 400)
    {
        parent::__construct($message !== '' ? $message : $codeName);
    }
}

final class Store
{
    public const TABLES = ['operators', 'connections', 'oauth_clients', 'oauth_credentials', 'oauth_consents', 'limits', 'operations', 'audit'];

    public static function table(string $name): string
    {
        return db_prefix() . 'mcai_' . $name;
    }

    public static function db(): \CI_DB_query_builder
    {
        return get_instance()->db;
    }

    /** Ustawia limit w oknie czasowym. Zwraca false po przekroczeniu albo przy błędzie bazy. */
    public static function rate(string $bucket, int $limit, int $seconds = 60): bool
    {
        $db = self::db();
        $key = hash('sha256', $bucket);
        $window = intdiv(time(), $seconds) * $seconds;
        $t = self::table('limits');
        $ok = $db->query("INSERT INTO `$t` (bucket,`window`,count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE count=IF(count<?,count+1,count)", [$key, $window, $limit + 1]);
        if (!$ok) {
            return false;
        }
        $row = $db->query("SELECT count FROM `$t` WHERE bucket=? AND `window`=?", [$key, $window])->row_array();
        return $row !== null && (int) $row['count'] <= $limit;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function lock(string $name, int $timeout = 3): bool
    {
        $row = self::db()->query('SELECT GET_LOCK(?,?) AS l', ['mcai:' . substr(hash('sha256', APP_DB_NAME . $name), 0, 40), $timeout])->row_array();
        return (string) ($row['l'] ?? '') === '1';
    }

    public static function unlock(string $name): void
    {
        self::db()->query('SELECT RELEASE_LOCK(?)', ['mcai:' . substr(hash('sha256', APP_DB_NAME . $name), 0, 40)]);
    }

    public static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function base(): string
    {
        return rtrim(site_url(), '/');
    }

    public static function secure(): bool
    {
        $url = parse_url(self::base());
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $local = in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true);
        return (($url['scheme'] ?? '') === 'https' && $https) || $local;
    }

    public static function cleanup(): void
    {
        $db = self::db();
        $now = time();
        $db->query('DELETE FROM `' . self::table('audit') . '` WHERE created_at<?', [$now - 30 * 86400]);
        $db->query('DELETE FROM `' . self::table('limits') . '` WHERE `window`<?', [$now - 2 * 86400]);
        $db->query('DELETE FROM `' . self::table('oauth_consents') . '` WHERE expires_at<?', [$now - 600]);
        $db->query("DELETE FROM `" . self::table('operations') . "` WHERE created_at<? AND status IN ('verified','rolled_back','expired','failed','denied')", [$now - 30 * 86400]);
        OAuth::cleanup();
    }
}

/** Poziomy dostępu. Wyższy obejmuje niższe. */
final class Levels
{
    public const ORDER = ['read' => 1, 'work' => 2, 'full' => 3];

    public static function valid(string $level): bool
    {
        return isset(self::ORDER[$level]);
    }

    public static function atLeast(string $have, string $need): bool
    {
        return (self::ORDER[$have] ?? 0) >= (self::ORDER[$need] ?? 99);
    }

    public static function min(string $a, string $b): string
    {
        return (self::ORDER[$a] ?? 0) <= (self::ORDER[$b] ?? 0) ? $a : $b;
    }

    public static function label(string $level): string
    {
        return _l('mcai_level_' . $level);
    }
}

final class Operators
{
    private static function staff(int $id): ?array
    {
        $row = Store::db()->select('staffid,firstname,lastname,email,admin,active')->where('staffid', $id)->get(db_prefix() . 'staff')->row_array();
        return $row ?: null;
    }

    /** Aktualny poziom pracownika albo null, gdy nie jest uprawniony. Administratorzy mają zawsze poziom pełny. */
    public static function level(int $staffId): ?string
    {
        $staff = self::staff($staffId);
        if (!$staff || (int) $staff['active'] !== 1) {
            return null;
        }
        if ((int) $staff['admin'] === 1) {
            return 'full';
        }
        $row = Store::db()->where('staff_id', $staffId)->get(Store::table('operators'))->row_array();
        return $row && Levels::valid($row['level']) ? $row['level'] : null;
    }

    public static function all(): array
    {
        $s = db_prefix() . 'staff';
        $o = Store::table('operators');
        return Store::db()->query("SELECT s.staffid,s.firstname,s.lastname,s.email,s.admin,s.active,o.level,o.created_at FROM `$s` s LEFT JOIN `$o` o ON o.staff_id=s.staffid WHERE s.admin=1 OR o.staff_id IS NOT NULL ORDER BY s.admin DESC,s.firstname")->result_array();
    }

    public static function candidates(): array
    {
        $s = db_prefix() . 'staff';
        $o = Store::table('operators');
        return Store::db()->query("SELECT s.staffid,s.firstname,s.lastname,s.email FROM `$s` s LEFT JOIN `$o` o ON o.staff_id=s.staffid WHERE s.admin=0 AND s.active=1 AND o.staff_id IS NULL ORDER BY s.firstname")->result_array();
    }

    public static function set(int $staffId, string $level, int $by): void
    {
        $staff = self::staff($staffId);
        if (!$staff || (int) $staff['admin'] === 1 || !Levels::valid($level)) {
            throw new Failure('invalid_operator', _l('mcai_err_operator'));
        }
        $db = Store::db();
        $exists = $db->where('staff_id', $staffId)->count_all_results(Store::table('operators')) > 0;
        if ($exists) {
            $db->where('staff_id', $staffId)->update(Store::table('operators'), ['level' => $level]);
        } else {
            $db->insert(Store::table('operators'), ['staff_id' => $staffId, 'level' => $level, 'added_by' => $by, 'created_at' => time()]);
        }
    }

    /** Usunięcie z listy od razu odcina wszystkie połączenia tej osoby. */
    public static function remove(int $staffId): void
    {
        $db = Store::db();
        $db->where('staff_id', $staffId)->delete(Store::table('operators'));
        $db->where('staff_id', $staffId)->where('revoked', 0)->update(Store::table('connections'), ['revoked' => 1, 'revoked_at' => time()]);
    }
}

final class Connections
{
    public const CLIENTS = ['claude', 'claude-code', 'codex', 'chatgpt', 'gemini', 'other'];

    public static function fresh(string $id): ?array
    {
        $row = Store::db()->where('id', $id)->get(Store::table('connections'))->row_array();
        return $row ?: null;
    }

    /** Efektywny poziom połączenia: zakres zgody, ale nigdy więcej niż obecny poziom osoby. */
    public static function effective(array $c): ?string
    {
        if ((int) $c['revoked'] || (int) $c['deleted_at'] || ((int) $c['expires_at'] && (int) $c['expires_at'] < time())) {
            return null;
        }
        $level = Operators::level((int) $c['staff_id']);
        return $level === null ? null : Levels::min($level, $c['scope']);
    }

    public static function create(int $staffId, string $name, string $client, string $scope, int $expires, bool $manual): array
    {
        $level = Operators::level($staffId);
        if ($level === null || !Levels::valid($scope) || !Levels::atLeast($level, $scope)) {
            throw new Failure('access_denied', _l('mcai_err_level'), 403);
        }
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $row = [
            'id' => $id, 'staff_id' => $staffId, 'name' => mb_substr(trim(strip_tags($name)) ?: 'MCP', 0, 100),
            'client' => in_array($client, self::CLIENTS, true) ? $client : 'other', 'scope' => $scope,
            'token_hash' => $manual ? hash('sha256', $secret) : '', 'created_at' => time(), 'expires_at' => $expires,
        ];
        if (!Store::db()->insert(Store::table('connections'), $row)) {
            throw new Failure('server_error', '', 503);
        }
        self::audit($row, 'connection.create', 'created');
        return ['connection' => $row, 'token' => $manual ? 'mc.' . $id . '.' . $secret : null];
    }

    /** Ręczne poświadczenie Bearer (Codex, Gemini CLI, inne programy bez OAuth). */
    public static function authenticateManual(string $header): ?array
    {
        if (!preg_match('/^Bearer mc\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $header, $m)) {
            return null;
        }
        $c = self::fresh($m[1]);
        if (!$c || $c['token_hash'] === '' || !hash_equals($c['token_hash'], hash('sha256', $m[2]))) {
            return null;
        }
        return $c;
    }

    /** 120 żądań na minutę na połączenie. */
    public static function rate(array $c): bool
    {
        $window = intdiv(time(), 60) * 60;
        $db = Store::db();
        $t = Store::table('connections');
        $db->query("UPDATE `$t` SET rate_count=IF(rate_window=?,rate_count+1,1), rate_window=? WHERE id=?", [$window, $window, $c['id']]);
        $row = $db->query("SELECT rate_count FROM `$t` WHERE id=?", [$c['id']])->row_array();
        return $row && (int) $row['rate_count'] <= 120;
    }

    public static function used(array $c): void
    {
        Store::db()->where('id', $c['id'])->update(Store::table('connections'), ['last_used' => time()]);
    }

    public static function revoke(string $id, ?int $staffId = null): bool
    {
        $db = Store::db()->where('id', $id)->where('revoked', 0);
        if ($staffId !== null) {
            $db->where('staff_id', $staffId);
        }
        $db->update(Store::table('connections'), ['revoked' => 1, 'revoked_at' => time()]);
        $changed = Store::db()->affected_rows() === 1;
        if ($changed && ($c = self::fresh($id))) {
            self::audit($c, 'connection.revoke', 'revoked');
        }
        return $changed;
    }

    public static function revokeAll(): void
    {
        Store::db()->where('revoked', 0)->update(Store::table('connections'), ['revoked' => 1, 'revoked_at' => time()]);
    }

    public static function forStaff(?int $staffId): array
    {
        $db = Store::db()->where('deleted_at', 0);
        if ($staffId !== null) {
            $db->where('staff_id', $staffId);
        }
        return $db->order_by('revoked', 'ASC')->order_by('created_at', 'DESC')->get(Store::table('connections'))->result_array();
    }

    public static function audit(array $c, string $event, string $result, string $operation = ''): void
    {
        Store::db()->insert(Store::table('audit'), [
            'connection_id' => $c['id'], 'staff_id' => (int) $c['staff_id'], 'event' => mb_substr($event, 0, 64),
            'result' => $result, 'operation_id' => $operation, 'created_at' => time(),
        ]);
    }

    public static function logs(?string $connectionId, ?int $staffId, int $limit = 50): array
    {
        $db = Store::db();
        if ($connectionId !== null) {
            $db->where('connection_id', $connectionId);
        }
        if ($staffId !== null) {
            $db->where('staff_id', $staffId);
        }
        return $db->order_by('id', 'DESC')->limit(max(1, min(200, $limit)))->get(Store::table('audit'))->result_array();
    }
}
