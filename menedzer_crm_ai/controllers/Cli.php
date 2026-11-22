<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Polecenia z wiersza poleceń (tylko CLI, przez HTTP 404):
 *   php index.php menedzer_crm_ai/cli/install   tworzy tabele (jak włączenie modułu w panelu)
 *   php index.php menedzer_crm_ai/cli/status    wersja, liczba uprawnionych i aktywnych połączeń
 *   php index.php menedzer_crm_ai/cli/token <id pracownika> [read|work|full] [dni]
 *                                               ręczne poświadczenie (np. dla Codexa lub testu); pokazane raz
 *   php index.php menedzer_crm_ai/cli/revoke <id połączenia>   odcina połączenie
 *   php index.php menedzer_crm_ai/cli/update    aktualizacja z najnowszego wydania na GitHubie
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

    /** Te same zasady co w panelu: osoba z listy uprawnionych, poziom nie wyższy niż jej poziom, najwyżej rok. */
    public function token($staffId = 0, $level = 'read', $days = 1)
    {
        $days = max(1, min(366, (int) $days));
        try {
            $r = Mcai\Connections::create((int) $staffId, 'CLI', 'other', (string) $level, time() + $days * 86400, true);
        } catch (Mcai\Failure $e) {
            echo 'BŁĄD ' . $e->getMessage() . PHP_EOL;
            exit(1);
        }
        echo Mcai\Store::json(['connection_id' => $r['connection']['id'], 'level' => $level, 'expires' => date('c', $r['connection']['expires_at']), 'token' => $r['token']]) . PHP_EOL;
    }

    public function revoke($id = '')
    {
        echo (Mcai\Connections::revoke((string) $id) ? 'OK' : 'BRAK') . PHP_EOL;
    }

    public function update()
    {
        $version = Mcai\Updater::latest(true);
        if ($version === null || !version_compare($version, MCAI_VERSION, '>')) {
            echo 'AKTUALNA ' . MCAI_VERSION . PHP_EOL;
            return;
        }
        try {
            Mcai\Updater::update($version);
        } catch (Mcai\Failure $e) {
            echo 'BŁĄD ' . $e->getMessage() . PHP_EOL;
            exit(1);
        }
        echo 'OK ' . MCAI_VERSION . ' -> ' . $version . PHP_EOL;
    }
}
