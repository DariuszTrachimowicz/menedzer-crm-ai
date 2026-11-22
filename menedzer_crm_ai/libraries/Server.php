<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/** Transport MCP Streamable HTTP (pojedyncze żądania JSON-RPC, odpowiedź JSON). */
final class Server
{
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    public const MAX_BODY = 262144;

    /** @return array{0:int,1:array,2:?array} status, nagłówki, treść */
    public static function handle(string $method, array $h, string $body): array
    {
        if (!Store::secure()) {
            return self::fail(403, 'forbidden', _l('mcai_err_https'));
        }
        $origin = $h['origin'] ?? '';
        $base = parse_url(Store::base());
        $canonical = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if ($origin !== '' && $origin !== $canonical) {
            return self::fail(403, 'forbidden', 'Origin');
        }
        $auth = $h['authorization'] ?? '';
        $c = str_starts_with($auth, 'Bearer oa.') ? OAuth::authenticate($auth) : Connections::authenticateManual($auth);
        $level = $c ? Connections::effective($c) : null;
        if (!$c || $level === null) {
            return self::fail(401, 'unauthorized', _l('mcai_err_auth'));
        }
        if (!Connections::rate($c)) {
            return [429, ['Retry-After' => '60'], ['error' => 'rate_limited']];
        }
        if ($method !== 'POST') {
            return [405, ['Allow' => 'POST'], ['error' => 'method_not_allowed']];
        }
        if (strlen($body) > self::MAX_BODY) {
            return [413, [], ['error' => 'too_large']];
        }
        if (!preg_match('~^application/json(\s*;.*)?$~i', $h['content-type'] ?? '')) {
            return [415, [], ['error' => 'unsupported_media_type']];
        }
        $version = $h['mcp-protocol-version'] ?? '';
        if ($version !== '' && !in_array($version, self::VERSIONS, true)) {
            return [400, [], ['error' => 'unsupported_protocol_version']];
        }
        try {
            $d = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [400, [], self::rpcError(null, -32700, 'Parse error')];
        }
        if (!is_array($d) || array_is_list($d) || ($d['jsonrpc'] ?? '') !== '2.0' || !is_string($d['method'] ?? null)) {
            return [400, [], self::rpcError(null, -32600, 'Invalid request')];
        }
        $id = $d['id'] ?? null;
        $params = $d['params'] ?? [];
        if (!is_array($params) || ($id !== null && !is_int($id) && !is_string($id))) {
            return [400, [], self::rpcError(null, -32600, 'Invalid request')];
        }
        unset($params['_meta']);
        $rpc = $d['method'];
        if (!array_key_exists('id', $d)) {
            // Powiadomienia klienta nie wymagają odpowiedzi.
            return [202, [], null];
        }
        Connections::used($c);
        switch ($rpc) {
            case 'initialize':
                $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
                return [200, [], ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'menedzer-crm-ai', 'title' => trim(get_option('companyname') . ' (' . ($base['host'] ?? '') . ')'), 'version' => MCAI_VERSION, 'websiteUrl' => Store::base() . '/'],
                    'instructions' => _l('mcai_instructions'),
                ]]];
            case 'ping':
                return [200, [], ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []]];
            case 'tools/list':
                return [200, [], ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => Tools::schemas($level)]]];
            case 'tools/call':
                return [200, [], ['jsonrpc' => '2.0', 'id' => $id, 'result' => self::call($c, $level, $params)]];
        }
        return [200, [], self::rpcError($id, -32601, 'Method not found')];
    }

    private static function call(array $c, string $level, array $params): array
    {
        $tool = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        $schemas = array_column(Tools::schemas($level), null, 'name');
        if (!is_string($tool) || !isset($schemas[$tool]) || !is_array($args)) {
            return self::text(['error' => 'unknown_tool', 'message' => _l('mcai_err_tool')], true);
        }
        $problem = self::validate($args, $schemas[$tool]['inputSchema']);
        if ($problem !== null) {
            return self::text(['error' => 'invalid_arguments', 'message' => $problem], true);
        }
        try {
            $out = Tools::call($c, $level, $tool, $args);
            $error = in_array($out['status'] ?? null, ['failed', 'unknown', 'rollback_failed', 'denied'], true);
            Connections::audit($c, $tool, $error ? 'failed' : 'completed', (string) ($out['operation_id'] ?? ''));
            return self::text($out, $error);
        } catch (Failure $e) {
            Connections::audit($c, $tool, 'denied');
            return self::text(['error' => $e->codeName, 'message' => $e->getMessage()], true);
        } catch (\Throwable $e) {
            log_message('error', 'Menedzer CRM AI: ' . get_class($e) . ': ' . $e->getMessage());
            Connections::audit($c, $tool, 'error');
            return self::text(['error' => 'server_error', 'message' => _l('mcai_err_server')], true);
        }
    }

    /** Minimalna walidacja schematu narzędzia: wymagane pola, brak nieznanych, typy proste. */
    public static function validate(array $args, array $schema): ?string
    {
        $props = (array) ($schema['properties'] ?? []);
        foreach ($schema['required'] ?? [] as $r) {
            if (!array_key_exists($r, $args)) {
                return "Brak pola: $r";
            }
        }
        foreach ($args as $k => $v) {
            if (!isset($props[$k])) {
                return "Nieznane pole: $k";
            }
            $p = $props[$k];
            $types = (array) ($p['type'] ?? []);
            $ok = !$types;
            foreach ($types as $t) {
                $ok = $ok || match ($t) {
                    'integer' => is_int($v), 'string' => is_string($v), 'boolean' => is_bool($v),
                    'array' => is_array($v) && array_is_list($v), 'object' => is_array($v) && ($v === [] || !array_is_list($v)), default => true,
                };
            }
            if (!$ok) {
                return "Zły typ pola: $k";
            }
            if (isset($p['enum']) && !in_array($v, $p['enum'], true)) {
                return "Niedozwolona wartość pola: $k";
            }
            if (is_string($v) && isset($p['pattern']) && !preg_match('/' . $p['pattern'] . '/D', $v)) {
                return "Nieprawidłowy format pola: $k";
            }
            if (is_string($v) && isset($p['maxLength']) && mb_strlen($v) > $p['maxLength']) {
                return "Za długie pole: $k";
            }
            if (is_int($v) && ((isset($p['minimum']) && $v < $p['minimum']) || (isset($p['maximum']) && $v > $p['maximum']))) {
                return "Wartość poza zakresem: $k";
            }
            if (is_array($v) && isset($p['items']['enum'])) {
                foreach ($v as $item) {
                    if (!in_array($item, $p['items']['enum'], true)) {
                        return "Niedozwolona wartość w polu: $k";
                    }
                }
            }
        }
        return null;
    }

    private static function text($data, bool $error): array
    {
        return ['content' => [['type' => 'text', 'text' => Store::json($data)]], 'isError' => $error];
    }

    private static function rpcError($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function fail(int $status, string $code, string $message): array
    {
        $headers = $status === 401 ? ['WWW-Authenticate' => 'Bearer resource_metadata="' . OAuth::resourceMetadataUrl() . '"'] : [];
        return [$status, $headers, ['error' => $code, 'message' => $message]];
    }
}
