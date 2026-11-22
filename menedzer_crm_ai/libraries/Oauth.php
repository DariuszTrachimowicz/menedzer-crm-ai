<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * OAuth 2.1 dla MCP: rejestracja klienta (DCR), kod z PKCE, rotowane tokeny odświeżania.
 * W bazie trzymamy wyłącznie hashe sekretów. Wzorzec: Menedżer Strony AI 2.9.0.
 */
final class OAuth
{
    public const CODE_TTL = 120;
    public const ACCESS_TTL = 3600;
    public const MAX_BODY = 8192;
    public const MAX_CLIENTS = 1000;
    private const CONSENT_FIELDS = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'scope', 'state'];

    public static function issuer(): string { return Store::base() . '/' . MCAI_MODULE; }
    public static function resource(): string { return self::issuer() . '/mcp'; }
    public static function authorizeUrl(): string { return self::issuer() . '/oauth/authorize'; }
    public static function resourceMetadataUrl(): string { return self::issuer() . '/oauth/resource'; }

    public static function serverMetadata(): array
    {
        return [
            'issuer' => self::issuer(), 'authorization_endpoint' => self::authorizeUrl(),
            'token_endpoint' => self::issuer() . '/oauth/token', 'registration_endpoint' => self::issuer() . '/oauth/register',
            'revocation_endpoint' => self::issuer() . '/oauth/revoke', 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'], 'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => array_keys(Levels::ORDER), 'authorization_response_iss_parameter_supported' => true,
        ];
    }

    // Część klientów czyta dokument schematem OIDC i wymaga trzech pól poniżej. Tokenów ID nie wydajemy.
    public static function openidMetadata(): array
    {
        return self::serverMetadata() + ['jwks_uri' => self::issuer() . '/oauth/jwks', 'subject_types_supported' => ['public'], 'id_token_signing_alg_values_supported' => ['RS256']];
    }

    public static function resourceMetadata(): array
    {
        return ['resource' => self::resource(), 'authorization_servers' => [self::issuer()], 'bearer_methods_supported' => ['header'], 'scopes_supported' => array_keys(Levels::ORDER)];
    }

    public static function error(string $code = 'invalid_request', int $status = 400): Failure
    {
        return new Failure($code, $code, $status);
    }

    // ---- rejestracja klientów ----

    private static function loopback(array $p): bool
    {
        return in_array(strtolower($p['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function validRedirect(string $uri): bool
    {
        if (strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
            return false;
        }
        $p = parse_url($uri);
        if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || empty($p['host']) || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }
        return $p['scheme'] === 'https' || self::loopback($p);
    }

    public static function redirectMatches(string $registered, string $requested): bool
    {
        if (!self::validRedirect($registered) || !self::validRedirect($requested)) {
            return false;
        }
        if (hash_equals($registered, $requested)) {
            return true;
        }
        // Aplikacje lokalne (Codex, Claude Code) dostają losowy port pętli zwrotnej.
        $a = parse_url($registered);
        $b = parse_url($requested);
        if (!self::loopback($a) || !self::loopback($b) || $a['host'] !== $b['host'] || $a['scheme'] !== $b['scheme']) {
            return false;
        }
        unset($a['port'], $b['port']);
        return $a === $b;
    }

    public static function scopes(string $scope): ?array
    {
        $names = explode(' ', trim($scope));
        return $names && !in_array('', $names, true) && count($names) === count(array_unique($names)) && !array_diff($names, array_keys(Levels::ORDER)) ? $names : null;
    }

    public static function register(array $m): array
    {
        if (!Store::rate('register|' . Store::ip(), 10, 3600) || !Store::rate('register|global', 100, 3600)) {
            throw self::error('temporarily_unavailable', 429);
        }
        $uris = $m['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > 8 || !array_is_list($uris)) {
            throw self::error('invalid_redirect_uri');
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !self::validRedirect($u)) {
                throw self::error('invalid_redirect_uri');
            }
        }
        $name = $m['client_name'] ?? 'MCP';
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f<>]/', $name)) {
            throw self::error('invalid_client_metadata');
        }
        if (($m['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw self::error('invalid_client_metadata');
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $allowed) {
            $v = $m[$field] ?? $allowed;
            if (!is_array($v) || !$v || array_diff($v, $allowed) || !in_array($allowed[0], $v, true)) {
                throw self::error('invalid_client_metadata');
            }
        }
        $scope = $m['scope'] ?? implode(' ', array_keys(Levels::ORDER));
        if (!is_string($scope) || self::scopes($scope) === null) {
            throw self::error('invalid_scope');
        }
        $db = Store::db();
        $t = Store::table('oauth_clients');
        if ($db->count_all($t) >= self::MAX_CLIENTS) {
            // Robimy miejsce kosztem najstarszej, nigdy nieużytej rejestracji sprzed ponad godziny.
            $db->query("DELETE FROM `$t` WHERE last_used=0 AND created_at<? ORDER BY created_at LIMIT 1", [time() - 3600]);
            if ($db->affected_rows() < 1) {
                throw self::error('temporarily_unavailable', 429);
            }
        }
        $client = ['redirect_uris' => array_values(array_unique($uris)), 'client_name' => $name, 'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'scope' => $scope];
        $id = 'mcai_' . bin2hex(random_bytes(24));
        if (!$db->insert($t, ['client_id' => $id, 'metadata' => Store::json($client), 'created_at' => time()])) {
            throw self::error('server_error', 503);
        }
        return $client + ['client_id' => $id, 'client_id_issued_at' => time()];
    }

    public static function client(string $id): array
    {
        if (!preg_match('/^mcai_[a-f0-9]{48}$/D', $id)) {
            throw self::error('invalid_client');
        }
        $row = Store::db()->where('client_id', $id)->get(Store::table('oauth_clients'))->row_array();
        $meta = $row ? json_decode($row['metadata'], true) : null;
        if (!is_array($meta)) {
            throw self::error('invalid_client');
        }
        return $meta;
    }

    // ---- autoryzacja ----

    /** Sprawdza parametry żądania autoryzacji. Zwraca klienta i dozwolone zakresy. */
    public static function validateAuthorization(array $f): array
    {
        $allowed = array_merge(self::CONSENT_FIELDS, ['consent_context', 'selected_scope', 'expiry_mode', 'expiry_date', 'decision', 'csrf_token_name']);
        $csrf = get_instance()->security->get_csrf_token_name();
        unset($f[$csrf]);
        foreach ($f as $k => $v) {
            if (!in_array($k, $allowed, true) || !is_string($v) || strlen($v) > 2048) {
                throw self::error();
            }
        }
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource'] as $k) {
            if (!isset($f[$k])) {
                throw self::error();
            }
        }
        $f['state'] = $f['state'] ?? '';
        if ($f['response_type'] !== 'code' || $f['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $f['code_challenge'])
            || strlen($f['state']) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $f['state']) || $f['resource'] !== self::resource()) {
            throw self::error();
        }
        $client = self::client($f['client_id']);
        $ok = false;
        foreach ($client['redirect_uris'] as $r) {
            $ok = $ok || self::redirectMatches($r, $f['redirect_uri']);
        }
        if (!$ok) {
            throw self::error('invalid_redirect_uri');
        }
        $registered = self::scopes($client['scope']) ?? [];
        $scopes = isset($f['scope']) ? self::scopes($f['scope']) : $registered;
        if ($scopes === null || array_diff($scopes, $registered)) {
            throw self::error('invalid_scope');
        }
        return ['request' => $f, 'client' => $client, 'scopes' => $scopes];
    }

    public static function callbackUrl(array $request, array $values): string
    {
        $values['iss'] = self::issuer();
        if ($request['state'] !== '') {
            $values['state'] = $request['state'];
        }
        return $request['redirect_uri'] . (str_contains($request['redirect_uri'], '?') ? '&' : '?') . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
    }

    private static function fingerprint(array $request): string
    {
        $context = [];
        foreach (self::CONSENT_FIELDS as $k) {
            $context[$k] = $request[$k] ?? ($k === 'scope' ? null : '');
        }
        return hash('sha256', Store::json($context));
    }

    private static function sessionHash(): string
    {
        return hash_hmac('sha256', (string) session_id(), APP_ENC_KEY);
    }

    /** Jednorazowy kontekst zgody związany z sesją i parametrami żądania (10 minut). */
    public static function createConsent(array $request, int $staffId): string
    {
        $id = bin2hex(random_bytes(32));
        Store::db()->insert(Store::table('oauth_consents'), ['hash' => hash('sha256', $id), 'staff_id' => $staffId, 'session_hash' => self::sessionHash(), 'request_hash' => self::fingerprint($request), 'expires_at' => time() + 600]);
        return $id;
    }

    public static function consumeConsent(string $id, array $request, int $staffId): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            return false;
        }
        $t = Store::table('oauth_consents');
        Store::db()->query("UPDATE `$t` SET consumed_at=? WHERE hash=? AND staff_id=? AND session_hash=? AND request_hash=? AND consumed_at=0 AND expires_at>?", [time(), hash('sha256', $id), $staffId, self::sessionHash(), self::fingerprint($request), time()]);
        return Store::db()->affected_rows() === 1;
    }

    private static function insert(string $kind, array $b, int $expires): string
    {
        $secret = ['code' => 'oc.', 'access' => 'oa.', 'refresh' => 'or.'][$kind] . bin2hex(random_bytes(32));
        $ok = Store::db()->insert(Store::table('oauth_credentials'), [
            'hash' => hash('sha256', $secret), 'kind' => $kind, 'connection_id' => $b['connection_id'], 'client_id' => $b['client_id'],
            'family' => $b['family'], 'redirect_uri' => $b['redirect_uri'] ?? '', 'challenge' => $b['challenge'] ?? '',
            'resource' => $b['resource'], 'scope' => $b['scope'], 'created_at' => time(), 'expires_at' => $expires,
        ]);
        if (!$ok) {
            throw self::error('server_error', 503);
        }
        return $secret;
    }

    public static function issueCode(array $request, array $client, string $scope, int $expires, int $staffId): string
    {
        $name = $client['client_name'];
        $kind = match (true) {
            stripos($name, 'claude code') !== false => 'claude-code',
            stripos($name, 'claude') !== false => 'claude',
            stripos($name, 'codex') !== false => 'codex',
            stripos($name, 'chatgpt') !== false || stripos($name, 'openai') !== false => 'chatgpt',
            stripos($name, 'gemini') !== false => 'gemini',
            default => 'other',
        };
        $issued = Connections::create($staffId, $name, $kind, $scope, $expires, false);
        $binding = ['connection_id' => $issued['connection']['id'], 'client_id' => $request['client_id'], 'family' => bin2hex(random_bytes(16)), 'redirect_uri' => $request['redirect_uri'], 'challenge' => $request['code_challenge'], 'resource' => $request['resource'], 'scope' => $scope];
        $code = self::insert('code', $binding, time() + self::CODE_TTL);
        Store::db()->where('client_id', $request['client_id'])->update(Store::table('oauth_clients'), ['last_used' => time()]);
        return $code;
    }

    public static function verifyPkce(string $verifier, string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) === 1
            && hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    private static function lookup(string $secret, string $kind): ?array
    {
        $row = Store::db()->where('hash', hash('sha256', $secret))->where('kind', $kind)->get(Store::table('oauth_credentials'))->row_array();
        return $row ?: null;
    }

    private static function revokeConnection(array $c, string $event): void
    {
        Store::db()->where('id', $c['id'])->update(Store::table('connections'), ['revoked' => 1, 'revoked_at' => (int) $c['revoked_at'] ?: time()]);
        Connections::audit($c, $event, 'revoked');
    }

    /** Wymiana pod blokadą połączenia i w transakcji, żeby dwa równoległe żądania nie zużyły tego samego sekretu. */
    private static function locked(string $secret, string $kind, callable $exchange, bool $requireValid = true)
    {
        $initial = self::lookup($secret, $kind);
        if (!$initial) {
            throw self::error('invalid_grant');
        }
        $lock = 'conn:' . $initial['connection_id'];
        if (!Store::lock($lock)) {
            throw self::error('temporarily_unavailable', 503);
        }
        $db = Store::db();
        try {
            $db->trans_begin();
            $c = Connections::fresh($initial['connection_id']);
            if (!$c || ($requireValid && Connections::effective($c) === null)) {
                $db->trans_rollback();
                throw self::error('invalid_grant');
            }
            $credential = $db->query('SELECT * FROM `' . Store::table('oauth_credentials') . '` WHERE hash=? AND kind=? FOR UPDATE', [hash('sha256', $secret), $kind])->row_array();
            if (!$credential) {
                $db->trans_rollback();
                throw self::error('invalid_grant');
            }
            try {
                $reply = $exchange($credential, $c);
            } catch (Failure $e) {
                // Odwołanie rodziny po powtórce zostaje zapisane także wtedy, gdy odpowiedź to invalid_grant.
                $db->trans_commit();
                throw $e;
            }
            if ($db->trans_status() === false) {
                $db->trans_rollback();
                throw self::error('server_error', 503);
            }
            $db->trans_commit();
            return $reply;
        } finally {
            Store::unlock($lock);
        }
    }

    private static function tokens(array $credential): array
    {
        $access = self::insert('access', $credential, time() + self::ACCESS_TTL);
        $refresh = self::insert('refresh', $credential, 0);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => $credential['scope']];
    }

    private static function consume(array $credential): void
    {
        Store::db()->where('hash', $credential['hash'])->where('consumed_at', 0)->update(Store::table('oauth_credentials'), ['consumed_at' => time()]);
        if (Store::db()->affected_rows() !== 1) {
            throw self::error('invalid_grant');
        }
    }

    public static function exchangeCode(array $f): array
    {
        return self::locked($f['code'], 'code', function (array $cr, array $c) use ($f) {
            // Najpierw powiązanie z klientem i PKCE: sam wyciekły kod nie może zerwać cudzego połączenia.
            if (!hash_equals($cr['client_id'], $f['client_id']) || !hash_equals($cr['redirect_uri'], $f['redirect_uri'])
                || !hash_equals($cr['resource'], $f['resource']) || !self::verifyPkce($f['code_verifier'], $cr['challenge']) || $cr['scope'] !== $c['scope']) {
                throw self::error('invalid_grant');
            }
            if ((int) $cr['consumed_at']) {
                // RFC 6749 4.1.2: drugie użycie kodu unieważnia wydane na jego podstawie tokeny.
                self::revokeConnection($c, 'oauth.code_replay');
                throw self::error('invalid_grant');
            }
            if ((int) $cr['expires_at'] <= time()) {
                throw self::error('invalid_grant');
            }
            self::consume($cr);
            return self::tokens($cr);
        });
    }

    public static function refresh(array $f): array
    {
        return self::locked($f['refresh_token'], 'refresh', function (array $cr, array $c) use ($f) {
            if (!hash_equals($cr['client_id'], $f['client_id']) || !hash_equals($cr['resource'], $f['resource']) || (isset($f['scope']) && $f['scope'] !== $cr['scope'])) {
                throw self::error('invalid_grant');
            }
            if ((int) $cr['consumed_at']) {
                // RFC 9700 4.14.2: zużyty token odświeżania użyty ponownie = możliwa kradzież, zrywamy całe połączenie.
                self::revokeConnection($c, 'oauth.refresh_replay');
                throw self::error('invalid_grant');
            }
            self::consume($cr);
            return self::tokens($cr);
        });
    }

    /** Uwierzytelnia token dostępu OAuth. Zwraca połączenie albo null. */
    public static function authenticate(string $header): ?array
    {
        if (!preg_match('/^Bearer (oa\.[a-f0-9]{64})$/D', $header, $m)) {
            return null;
        }
        $cr = self::lookup($m[1], 'access');
        if (!$cr || (int) $cr['expires_at'] <= time() || (int) $cr['consumed_at'] || !hash_equals($cr['resource'], self::resource())) {
            return null;
        }
        $c = Connections::fresh($cr['connection_id']);
        return $c && $c['scope'] === $cr['scope'] ? $c : null;
    }

    public static function revoke(array $f): void
    {
        $kind = str_starts_with($f['token'], 'or.') ? 'refresh' : 'access';
        $initial = self::lookup($f['token'], $kind);
        if (!$initial || !hash_equals($initial['client_id'], $f['client_id'])) {
            return; // RFC 7009: nieznany token to też sukces.
        }
        self::locked($f['token'], $kind, function (array $cr, array $c) {
            self::revokeConnection($c, 'oauth.revoke');
            return true;
        }, false);
    }

    public static function cleanup(): void
    {
        $db = Store::db();
        $t = Store::table('oauth_credentials');
        $c = Store::table('connections');
        $old = time() - 90 * 86400;
        $db->query("DELETE t FROM `$t` t LEFT JOIN `$c` c ON c.id=t.connection_id WHERE (t.kind='code' AND t.expires_at<?) OR (t.kind='access' AND t.expires_at<?) OR (t.kind='refresh' AND (c.id IS NULL OR (c.revoked=1 AND c.revoked_at<?) OR (c.expires_at<>0 AND c.expires_at<?)))", [time() - 600, time() - 86400, $old, $old]);
        $db->query('DELETE FROM `' . Store::table('oauth_clients') . '` WHERE last_used=0 AND created_at<?', [time() - 86400]);
    }
}
