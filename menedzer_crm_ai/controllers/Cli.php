<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Polecenia z wiersza poleceń (tylko CLI, przez HTTP 404):
 *   php index.php menedzer_crm_ai/cli/install   tworzy tabele (jak włączenie modułu w panelu)
 *   php index.php menedzer_crm_ai/cli/status    wersja, liczba uprawnionych i aktywnych połączeń
 */
class Cli extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) {
            show_404();
        }
    }

    public function install()
    {
        mcai_activate();
        $this->db->where('module_name', MCAI_MODULE)->update(db_prefix() . 'modules', ['installed_version' => MCAI_VERSION, 'active' => 1]);
        echo 'OK ' . MCAI_VERSION . PHP_EOL;
    }

    public function status()
    {
        $active = 0;
        foreach (Mcai\Connections::forStaff(null) as $c) {
            $active += Mcai\Connections::effective($c) !== null ? 1 : 0;
        }
        echo Mcai\Store::json(['version' => MCAI_VERSION, 'mcp' => Mcai\OAuth::resource(), 'operators' => count(Mcai\Operators::all()), 'active_connections' => $active]) . PHP_EOL;
    }
}
