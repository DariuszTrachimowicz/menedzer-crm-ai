<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Mcai\Connections;
use Mcai\Failure;
use Mcai\Levels;
use Mcai\OAuth;
use Mcai\Operators;
use Mcai\Store;

/** Panel modułu: /admin/menedzer_crm_ai */
class Menedzer_crm_ai extends AdminController
{
    private int $me;
    private ?string $level;

    public function __construct()
    {
        parent::__construct();
        $this->me = (int) get_staff_user_id();
        $this->level = Operators::level($this->me);
        if ($this->level === null) {
            access_denied(_l('mcai_name'));
        }
    }

    public function index()
    {
        $tabs = ['start', 'connections', 'activity', 'help'];
        if (is_admin()) {
            array_splice($tabs, 2, 0, ['operators']);
        }
        $tab = in_array($this->input->get('tab'), $tabs, true) ? $this->input->get('tab') : 'start';
        $data = [
            'title' => _l('mcai_name'), 'tabs' => $tabs, 'tab' => $tab, 'level' => $this->level,
            'mcpUrl' => OAuth::resource(), 'isAdmin' => is_admin(), 'token' => $this->session->flashdata('mcai_token'),
        ];
        if ($tab === 'start') {
            $data['checks'] = $this->input->get('check') ? $this->diagnostics() : null;
        } elseif ($tab === 'connections') {
            $data['connections'] = Connections::forStaff(is_admin() ? null : $this->me);
            $data['names'] = $this->staffNames();
        } elseif ($tab === 'operators') {
            $data['operators'] = Operators::all();
            $data['candidates'] = Operators::candidates();
        } elseif ($tab === 'activity') {
            $data['logs'] = Connections::logs(null, is_admin() ? null : $this->me, 200);
            $data['names'] = $this->staffNames();
        }
        $this->load->view('panel', $data);
    }

    private function staffNames(): array
    {
        $out = [];
        foreach ($this->db->select('staffid,firstname,lastname')->get(db_prefix() . 'staff')->result_array() as $s) {
            $out[(int) $s['staffid']] = trim($s['firstname'] . ' ' . $s['lastname']);
        }
        return $out;
    }

    private function back(string $tab, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            set_alert($type, $message);
        }
        redirect(admin_url(MCAI_MODULE . '?tab=' . $tab));
    }

    private function onlyAdmin(): void
    {
        if (!is_admin()) {
            access_denied(_l('mcai_name'));
        }
    }

    public function operator_save()
    {
        $this->onlyAdmin();
        try {
            Operators::set((int) $this->input->post('staff_id'), (string) $this->input->post('level'), $this->me);
            $this->back('operators', _l('mcai_saved'));
        } catch (Failure $e) {
            $this->back('operators', $e->getMessage(), 'danger');
        }
    }

    public function operator_remove()
    {
        $this->onlyAdmin();
        Operators::remove((int) $this->input->post('staff_id'));
        $this->back('operators', _l('mcai_operator_removed'));
    }

    public function connection_revoke()
    {
        $id = (string) $this->input->post('id');
        $ok = Connections::revoke($id, is_admin() ? null : $this->me);
        $this->back('connections', $ok ? _l('mcai_connection_revoked') : _l('mcai_err_not_found'), $ok ? 'success' : 'danger');
    }

    public function connections_revoke_all()
    {
        $this->onlyAdmin();
        Connections::revokeAll();
        $this->back('connections', _l('mcai_connection_revoked_all'));
    }

    /** Ręczne poświadczenie dla Codexa, Gemini CLI i programów bez OAuth. Token widać tylko raz. */
    public function manual_create()
    {
        $scope = (string) $this->input->post('scope');
        $expires = 0;
        if ($this->input->post('expiry_mode') !== 'unlimited') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input->post('expiry_date'));
            if (!$date || $date->getTimestamp() <= time()) {
                $this->back('connections', _l('mcai_err_date'), 'danger');
            }
            $expires = $date->setTime(23, 59, 59)->getTimestamp();
        }
        try {
            $r = Connections::create($this->me, (string) $this->input->post('name'), (string) $this->input->post('client'), $scope, $expires, true);
            $this->session->set_flashdata('mcai_token', $r['token']);
            $this->back('connections');
        } catch (Failure $e) {
            $this->back('connections', $e->getMessage(), 'danger');
        }
    }

    /** Sprawdza z serwera, czy dokumenty logowania są osiągalne pod adresami, które czytają klienci. */
    private function diagnostics(): array
    {
        $host = parse_url(Store::base(), PHP_URL_HOST);
        $urls = [
            OAuth::issuer() . '/.well-known/openid-configuration' => 'issuer',
            OAuth::issuer() . '/.well-known/oauth-authorization-server' => 'issuer',
            OAuth::resourceMetadataUrl() => 'resource',
            Store::base() . '/.well-known/oauth-protected-resource/' . MCAI_MODULE . '/mcp' => 'resource',
        ];
        $out = [];
        foreach ($urls as $url => $kind) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $doc = is_string($body) ? json_decode($body, true) : null;
            $valid = $status === 200 && is_array($doc) && ($kind === 'issuer' ? ($doc['issuer'] ?? '') === OAuth::issuer() : ($doc['resource'] ?? '') === OAuth::resource());
            $out[] = ['url' => $url, 'status' => $status, 'valid' => $valid, 'required' => $url !== array_key_last($urls) && !str_contains($url, 'oauth-authorization-server')];
        }
        return $out;
    }
}
