<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

final class Tools
{
    private static function resourceEnum(): array
    {
        return array_keys(Resources::map());
    }

    public static function schemas(string $level): array
    {
        $resource = ['type' => 'string', 'enum' => self::resourceEnum()];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $tools = [
            ['name' => 'crm_status', 'title' => 'Stan połączenia', 'description' => 'Kto jest zalogowany, jaki ma poziom dostępu, wersja CRM i modułu. Zacznij od tego narzędzia.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false], 'annotations' => $ro],
            ['name' => 'crm_inventory', 'title' => 'Spis danych', 'description' => 'Dostępne zasoby z liczbą rekordów oraz słowniki: statusy, źródła leadów, działy, priorytety, grupy bazy wiedzy.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false], 'annotations' => $ro],
            ['name' => 'crm_resource_list', 'title' => 'Lista rekordów', 'description' => 'Lista rekordów zasobu z filtrami. Zwraca skrócone pola; pełny rekord daje crm_resource_get.',
                'inputSchema' => ['type' => 'object', 'required' => ['resource'], 'additionalProperties' => false, 'properties' => [
                    'resource' => $resource, 'search' => ['type' => 'string', 'maxLength' => 200], 'status' => ['type' => ['integer', 'string']],
                    'customer_id' => ['type' => 'integer'], 'assigned_to' => ['type' => 'integer', 'description' => 'id pracownika'],
                    'related_type' => ['type' => 'string'], 'related_id' => ['type' => 'integer'],
                    'since' => ['type' => 'string', 'description' => 'RRRR-MM-DD'], 'until' => ['type' => 'string', 'description' => 'RRRR-MM-DD'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ]], 'annotations' => $ro],
            ['name' => 'crm_resource_get', 'title' => 'Rekord', 'description' => 'Pełny rekord z powiązaniami: kontakty i notatki klienta, odpowiedzi zgłoszenia, komentarze zadania, pozycje faktury.',
                'inputSchema' => ['type' => 'object', 'required' => ['resource', 'id'], 'additionalProperties' => false, 'properties' => ['resource' => $resource, 'id' => ['type' => 'integer', 'minimum' => 1]]], 'annotations' => $ro],
            ['name' => 'crm_search', 'title' => 'Szukaj', 'description' => 'Szuka frazy naraz w klientach, kontaktach, leadach, zgłoszeniach, zadaniach, projektach, dokumentach sprzedaży i bazie wiedzy.',
                'inputSchema' => ['type' => 'object', 'required' => ['query'], 'additionalProperties' => false, 'properties' => ['query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200], 'resources' => ['type' => 'array', 'items' => $resource]]], 'annotations' => $ro],
            ['name' => 'crm_logs', 'title' => 'Historia połączenia', 'description' => 'Ostatnie wpisy historii tego połączenia (narzędzia i operacje).',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], 'additionalProperties' => false], 'annotations' => $ro],
        ];
        if (Levels::atLeast($level, 'work')) {
            $tools = array_merge($tools, Operations::schemas($level));
        }
        return $tools;
    }

    public static function call(array $c, string $level, string $tool, array $a): array
    {
        $sid = (int) $c['staff_id'];
        switch ($tool) {
            case 'crm_status':
                $staff = Store::db()->select('staffid,firstname,lastname,email,admin')->where('staffid', $sid)->get(db_prefix() . 'staff')->row_array();
                return [
                    'crm' => get_option('companyname'), 'url' => Store::base(), 'perfex_version' => get_app_version(), 'module_version' => MCAI_VERSION,
                    'user' => ['id' => $sid, 'name' => trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? '')), 'admin' => (bool) ($staff['admin'] ?? false)],
                    'level' => $level, 'connection' => ['name' => $c['name'], 'expires_at' => (int) $c['expires_at'] ? date('c', (int) $c['expires_at']) : null],
                    'rules' => _l('mcai_rules_for_ai'),
                    'actions' => Levels::atLeast($level, 'work') ? Operations::catalog($level) : [],
                ];
            case 'crm_inventory':
                return Resources::inventory($sid);
            case 'crm_resource_list':
                return Resources::list((string) $a['resource'], $sid, $a);
            case 'crm_resource_get':
                return Resources::get((string) $a['resource'], (int) $a['id'], $sid);
            case 'crm_search':
                return Resources::search((string) $a['query'], $sid, isset($a['resources']) && is_array($a['resources']) ? $a['resources'] : null);
            case 'crm_logs':
                return ['entries' => Connections::logs($c['id'], null, (int) ($a['limit'] ?? 50))];
        }
        return Operations::call($c, $level, $tool, $a);
    }
}
