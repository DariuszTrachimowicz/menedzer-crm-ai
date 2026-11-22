<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Mcai\Failure;
use Mcai\Levels;
use Mcai\OAuth as O;
use Mcai\Operators;
use Mcai\Store;

/** OAuth 2.1 dla klientów MCP: dokumenty discovery, rejestracja, zgoda, tokeny. */
class Oauth extends App_Controller
{
    private const NOCACHE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer'];

    public function server() { $this->document(O::serverMetadata()); }
    public function openid() { $this->document(O::openidMetadata()); }
    public function resource() { $this->document(O::resourceMetadata()); }
    public function jwks() { $this->document(['keys' => []]); }

    private function document(array $doc): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            mcai_send_json(405, ['error' => 'method_not_allowed'], ['Allow' => 'GET, HEAD']);
        }
        mcai_send_json(200, $doc, self::NOCACHE + ['Access-Control-Allow-Origin' => '*']);
    }

    private function form(string $body): ?array
    {
        $out = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = urldecode($k);
            $v = urldecode($v);
            if ($k === '' || preg_match('/[^a-z_]/', $k) || isset($out[$k]) || strlen($v) > 2048) {
                return null;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function only(array $f, array $allowed, array $required): bool
    {
        return !array_diff(array_keys($f), $allowed) && !array_diff($required, array_keys($f));
    }

    private function endpoint(callable $handler, int $okStatus = 200): void
    {
        try {
            if (!Store::secure()) {
                throw O::error('access_denied', 403);
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw O::error('invalid_request', 405);
            }
            $body = (string) file_get_contents('php://input', false, null, 0, O::MAX_BODY + 1);
            if (strlen($body) > O::MAX_BODY) {
                throw O::error('invalid_request', 413);
            }
            mcai_send_json($okStatus, $handler($body), self::NOCACHE);
        } catch (Failure $e) {
            mcai_send_json($e->status, ['error' => $e->codeName], self::NOCACHE);
        } catch (\Throwable $e) {
            log_message('error', 'Menedzer CRM AI OAuth: ' . $e->getMessage());
            mcai_send_json(503, ['error' => 'server_error'], self::NOCACHE);
        }
    }

    private function type(): string
    {
        return strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    }

    public function register()
    {
        $this->endpoint(function (string $body) {
            $m = $this->type() === 'application/json' ? json_decode($body, true, 16) : null;
            if (!is_array($m) || array_is_list($m)) {
                throw O::error('invalid_client_metadata');
            }
            return O::register($m);
        }, 201);
    }

    public function token()
    {
        $this->endpoint(function (string $body) {
            $f = $this->type() === 'application/x-www-form-urlencoded' ? $this->form($body) : null;
            if ($f === null || !isset($f['client_id'])) {
                throw O::error();
            }
            if (!Store::rate('token|' . Store::ip(), 60) || !Store::rate('token|global', 1000)) {
                throw O::error('temporarily_unavailable', 429);
            }
            O::client($f['client_id']);
            $grant = $f['grant_type'] ?? '';
            if ($grant === 'authorization_code') {
                $keys = ['grant_type', 'client_id', 'code', 'redirect_uri', 'code_verifier', 'resource'];
                if (!$this->only($f, $keys, array_diff($keys, ['resource']))) {
                    throw O::error();
                }
                $f['resource'] = $f['resource'] ?? O::resource();
                if ($f['resource'] !== O::resource()) {
                    throw O::error('invalid_target');
                }
                return O::exchangeCode($f);
            }
            if ($grant === 'refresh_token') {
                if (!$this->only($f, ['grant_type', 'client_id', 'refresh_token', 'resource', 'scope'], ['grant_type', 'client_id', 'refresh_token'])) {
                    throw O::error();
                }
                $f['resource'] = $f['resource'] ?? O::resource();
                if ($f['resource'] !== O::resource()) {
                    throw O::error('invalid_target');
                }
                return O::refresh($f);
            }
            throw O::error('unsupported_grant_type');
        });
    }

    public function revoke()
    {
        $this->endpoint(function (string $body) {
            $f = $this->type() === 'application/x-www-form-urlencoded' ? $this->form($body) : null;
            if ($f === null || !$this->only($f, ['client_id', 'token', 'token_type_hint'], ['client_id', 'token'])) {
                throw O::error();
            }
            O::revoke($f);
            return (object) [];
        });
    }

    public function authorize()
    {
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' https: http://localhost:* http://127.0.0.1:*; frame-ancestors 'none'; base-uri 'none'");
        if (!Store::secure()) {
            $this->page(403, _l('mcai_err_https'));
        }
        if (!Store::rate('authorize|' . Store::ip(), 30)) {
            $this->page(429, _l('mcai_err_rate'));
        }
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $fields = $post ? $this->input->post(null, false) : $this->input->get(null, false);
        try {
            $v = O::validateAuthorization(is_array($fields) ? $fields : []);
        } catch (Failure $e) {
            $this->page(400, _l('mcai_err_authorize'));
        }
        if (!is_staff_logged_in()) {
            redirect_after_login_to_current_url();
            redirect(admin_url('authentication'));
        }
        $staffId = (int) get_staff_user_id();
        $level = Operators::level($staffId);
        if ($level === null) {
            $this->page(403, _l('mcai_err_not_operator'));
        }
        $request = $v['request'];
        $offered = array_values(array_filter(array_keys(Levels::ORDER), fn ($s) => in_array($s, $v['scopes'], true) && Levels::atLeast($level, $s)));
        if (!$offered) {
            $this->page(403, _l('mcai_err_level'));
        }
        if (!$post) {
            $this->consent($v, $offered, O::createConsent($request, $staffId));
            return;
        }
        if (!O::consumeConsent((string) ($request['consent_context'] ?? ''), $request, $staffId)) {
            $this->page(409, _l('mcai_err_consent'));
        }
        if (($request['decision'] ?? '') === 'deny') {
            redirect(O::callbackUrl($request, ['error' => 'access_denied']));
        }
        $scope = $request['selected_scope'] ?? '';
        if (($request['decision'] ?? '') !== 'allow' || !in_array($scope, $offered, true)) {
            $this->page(400, _l('mcai_err_scope'));
        }
        $expires = 0;
        if (($request['expiry_mode'] ?? '') === 'date') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($request['expiry_date'] ?? ''));
            if (!$date || $date->format('Y-m-d') !== ($request['expiry_date'] ?? '')) {
                $this->page(400, _l('mcai_err_date'));
            }
            $expires = $date->setTime(23, 59, 59)->getTimestamp();
            if ($expires <= time() || $expires > time() + 366 * 86400) {
                $this->page(400, _l('mcai_err_date_range'));
            }
        } elseif (($request['expiry_mode'] ?? '') !== 'unlimited') {
            $this->page(400, _l('mcai_err_date'));
        }
        try {
            $code = O::issueCode($request, $v['client'], $scope, $expires, $staffId);
        } catch (Failure $e) {
            $this->page(403, _l('mcai_err_level'));
        }
        redirect(O::callbackUrl($request, ['code' => $code]));
    }

    private function consent(array $v, array $offered, string $context): void
    {
        $this->load->view(MCAI_MODULE . '/consent', [
            'client' => $v['client'], 'request' => $v['request'], 'offered' => $offered, 'context' => $context,
            'host' => (string) parse_url($v['request']['redirect_uri'], PHP_URL_HOST), 'action' => O::authorizeUrl(),
        ]);
    }

    private function page(int $status, string $message): void
    {
        $this->output->set_status_header($status);
        $this->load->view(MCAI_MODULE . '/message', ['message' => $message]);
        $this->output->_display();
        exit;
    }
}
