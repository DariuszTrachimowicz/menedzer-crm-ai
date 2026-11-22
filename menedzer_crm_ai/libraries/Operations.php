<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Zapisy przez AI: podgląd, wykonanie, sprawdzenie i cofnięcie.
 * - Piszemy bezpośrednio do tabel, bez modeli Perfexa: żadna zmiana nie wysyła maila ani powiadomienia.
 * - Nic, co widzi klient: artykuły bazy wiedzy tylko jako wewnętrzne szkice, bez komentarzy
 *   i zmian treści w zadaniach widocznych dla klienta, szkice odpowiedzi jako notatki wewnętrzne.
 * - Usuwania danych nie ma. Cofnąć można zmianę pól oraz własny komentarz, notatkę i szkic
 *   odpowiedzi (nic się do nich nie dopina). Nowego klienta, kontakt, zadanie, lead czy artykuł
 *   usuwa w razie potrzeby człowiek w panelu, bo pewnego sprawdzenia zależności w Perfexie nie ma.
 * - Wykonanie i cofnięcie: jedna transakcja; w niej od nowa połączenie, poziom, rola, widoczność
 *   i blokada rekordu; stan operacji zapisywany razem ze zmianą.
 */
final class Operations
{
    private const TTL = 900;
    private const PLAN_VERSION = 2;
    public const ID_PATTERN = '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$';

    private static function actions(): array
    {
        $taskF = ['name' => 'text:200', 'description' => 'html', 'startdate' => 'date', 'duedate' => 'date', 'priority' => 'int:1-4', 'status' => 'int:1-5'];
        $leadF = ['name' => 'text:191', 'company' => 'text:191', 'title' => 'text:100', 'email' => 'email', 'phonenumber' => 'text:50', 'website' => 'text:150',
            'description' => 'text:5000', 'status' => 'ref:leads_status.id', 'source' => 'ref:leads_sources.id', 'assigned' => 'staff', 'lead_value' => 'decimal',
            'address' => 'text:100', 'city' => 'text:100', 'zip' => 'text:15', 'state' => 'text:50', 'country' => 'ref:countries.country_id'];
        $kbF = ['subject' => 'text:191', 'description' => 'html', 'articlegroup' => 'ref:knowledge_base_groups.groupid'];
        $custF = ['company' => 'text:191', 'vat' => 'text:50', 'phonenumber' => 'text:30', 'website' => 'text:150', 'address' => 'text:191', 'city' => 'text:100',
            'state' => 'text:50', 'zip' => 'text:15', 'country' => 'ref:countries.country_id'];
        $contactF = ['firstname' => 'text:191', 'lastname' => 'text:191', 'email' => 'email', 'phonenumber' => 'text:100', 'title' => 'text:100'];
        return [
            'task.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'tasks', 'pk' => 'id', 'req' => ['name'],
                'f' => $taskF + ['rel_type' => 'enum:customer,project,lead,ticket', 'rel_id' => 'int:1-', 'assignees' => 'staff[]'], 'desc' => 'Nowe zadanie wewnętrzne (niewidoczne dla klienta), opcjonalnie przy kliencie, projekcie, leadzie lub zgłoszeniu i z przypisanymi osobami. Bez cofania.'],
            'task.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tasks', 'pk' => 'id', 'resource' => 'tasks', 'f' => $taskF,
                'desc' => 'Zmiana pól zadania. Osoba przypisana bez prawa edycji zmienia tylko status. W zadaniu widocznym dla klienta bez zmiany nazwy i opisu.'],
            'task.comment' => ['level' => 'work', 'mode' => 'create', 'leaf' => true, 'table' => 'task_comments', 'pk' => 'id', 'parent' => 'tasks', 'req' => ['content'], 'f' => ['content' => 'html'],
                'desc' => 'Komentarz do zadania niewidocznego dla klienta (target_id = id zadania).'],
            'note.create' => ['level' => 'work', 'mode' => 'create', 'leaf' => true, 'table' => 'notes', 'pk' => 'id', 'req' => ['rel_type', 'rel_id', 'description'],
                'f' => ['rel_type' => 'enum:customer,lead', 'rel_id' => 'int:1-', 'description' => 'text:10000'], 'desc' => 'Notatka wewnętrzna przy kliencie lub leadzie.'],
            'ticket.draft_reply' => ['level' => 'work', 'mode' => 'create', 'leaf' => true, 'table' => 'notes', 'pk' => 'id', 'parent' => 'tickets', 'req' => ['message'], 'f' => ['message' => 'text:20000'],
                'desc' => 'Szkic odpowiedzi jako notatka wewnętrzna zgłoszenia (target_id = id zgłoszenia). Nic nie trafia do klienta; wysyła pracownik.'],
            'ticket.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tickets', 'pk' => 'ticketid', 'resource' => 'tickets',
                'f' => ['status' => 'ref:tickets_status.ticketstatusid', 'priority' => 'ref:tickets_priorities.priorityid', 'assigned' => 'staff', 'department' => 'ref:departments.departmentid'],
                'desc' => 'Status, priorytet, dział lub osoba przypisana, bez maila do klienta.'],
            'lead.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'leads', 'pk' => 'id', 'req' => ['name'], 'f' => $leadF, 'desc' => 'Nowy lead. Bez cofania.'],
            'lead.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'leads', 'pk' => 'id', 'resource' => 'leads', 'f' => $leadF, 'desc' => 'Zmiana pól leada.'],
            'kb.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'knowledge_base', 'pk' => 'articleid', 'req' => ['subject', 'description', 'articlegroup'], 'f' => $kbF,
                'desc' => 'Artykuł bazy wiedzy jako nieaktywny szkic wewnętrzny. Publikuje pracownik w panelu. Bez cofania.'],
            'kb.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'knowledge_base', 'pk' => 'articleid', 'resource' => 'knowledge_base', 'f' => $kbF,
                'desc' => 'Zmiana artykułu, który jest nieaktywnym szkicem. Opublikowanych nie zmienia.'],
            'customer.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'clients', 'pk' => 'userid', 'req' => ['company'], 'f' => $custF, 'desc' => 'Nowy klient (firma). Bez cofania.'],
            'customer.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'clients', 'pk' => 'userid', 'resource' => 'customers', 'f' => $custF, 'desc' => 'Zmiana danych klienta.'],
            'contact.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'contacts', 'pk' => 'id', 'req' => ['userid', 'firstname', 'lastname', 'email'], 'f' => ['userid' => 'int:1-'] + $contactF,
                'desc' => 'Nowy kontakt klienta, bez hasła i bez maila powitalnego. Bez cofania.'],
            'contact.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'contacts', 'pk' => 'id', 'resource' => 'contacts', 'f' => $contactF, 'desc' => 'Zmiana danych kontaktu.'],
            'project.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'projects', 'pk' => 'id', 'resource' => 'projects',
                'f' => ['status' => 'int:1-5', 'start_date' => 'date', 'deadline' => 'date', 'progress' => 'int:0-100'], 'desc' => 'Status, daty i postęp projektu.'],
        ];
    }

    private static function rollbackable(array $def): bool
    {
        return $def['mode'] === 'update' || !empty($def['leaf']);
    }

    public static function catalog(string $level): array
    {
        $out = [];
        foreach (self::actions() as $name => $a) {
            if (Levels::atLeast($level, $a['level'])) {
                $out[$name] = ['description' => $a['desc'], 'target_id' => $a['mode'] === 'update' || isset($a['parent']), 'fields' => $a['f'], 'required' => $a['req'] ?? [], 'rollback' => self::rollbackable($a)];
            }
        }
        return $out;
    }

    public static function schemas(string $level): array
    {
        $op = ['type' => 'string', 'pattern' => self::ID_PATTERN];
        return [
            ['name' => 'crm_operation_preview', 'title' => 'Podgląd zmiany', 'description' => 'Przygotowuje zmianę i pokazuje stan przed i po. Nic nie zapisuje. Akcje i pola: crm_status.actions. Pokaż podgląd użytkownikowi przed wykonaniem.',
                'inputSchema' => ['type' => 'object', 'required' => ['action', 'change'], 'additionalProperties' => false, 'properties' => [
                    'action' => ['type' => 'string', 'enum' => array_keys(self::catalog($level))], 'target_id' => ['type' => 'integer', 'minimum' => 1], 'change' => ['type' => 'object'],
                ]], 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false]],
            ['name' => 'crm_operation_execute', 'title' => 'Wykonaj zmianę', 'description' => 'Wykonuje zmianę z podglądu po akceptacji użytkownika. confirm=true i stały idempotency_key; po niejasnym wyniku sprawdź crm_operation_status zamiast ponawiać.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id', 'confirm', 'idempotency_key'], 'additionalProperties' => false, 'properties' => [
                    'operation_id' => $op, 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string', 'maxLength' => 100],
                ]], 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]],
            ['name' => 'crm_operation_status', 'title' => 'Stan operacji', 'description' => 'Stan operacji: previewed, verified, conflict, expired, rolled_back.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id'], 'additionalProperties' => false, 'properties' => ['operation_id' => $op]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'crm_operation_rollback', 'title' => 'Cofnij zmianę', 'description' => 'Przywraca stan sprzed zmiany pól albo usuwa własny komentarz, notatkę lub szkic, jeśli nikt ich od tego czasu nie zmienił.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id', 'confirm'], 'additionalProperties' => false, 'properties' => ['operation_id' => $op, 'confirm' => ['type' => 'boolean']]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false]],
        ];
    }

    public static function call(array $c, string $level, string $tool, array $a): array
    {
        if (in_array($tool, ['crm_operation_execute', 'crm_operation_rollback'], true) && $a['confirm'] !== true) {
            throw new Failure('confirm_required', 'Wymagane confirm=true.');
        }
        switch ($tool) {
            case 'crm_operation_preview':
                return self::preview($c, $level, (string) $a['action'], isset($a['target_id']) ? (int) $a['target_id'] : null, (array) $a['change']);
            case 'crm_operation_execute':
                return self::execute($c, (string) $a['operation_id'], (string) $a['idempotency_key']);
            case 'crm_operation_status':
                return self::public(self::load($c, (string) $a['operation_id'], false));
            case 'crm_operation_rollback':
                return self::rollback($c, (string) $a['operation_id']);
        }
        throw new Failure('unknown_tool', _l('mcai_err_tool'), 404);
    }

    // ---- walidacja ----

    /** Oczyszczanie HTML od AI własną konfiguracją, niezależnie od filtrów Perfexa. Bez oczyszczacza: sam tekst. */
    private static function purify(string $html): string
    {
        static $purifier = null;
        if ($purifier === null && class_exists('HTMLPurifier')) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,a[href],h2,h3,h4,blockquote,code,pre,table,thead,tbody,tr,th,td');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $purifier = new \HTMLPurifier($config);
        }
        if (!$purifier) {
            throw new Failure('invalid_change', 'Ten CRM nie ma oczyszczacza HTML; pole przyjmuje tylko zapis przez panel.');
        }
        return $purifier->purify($html);
    }

    private static function value(string $field, string $type, $v)
    {
        $bad = fn () => new Failure('invalid_change', "Nieprawidłowa wartość pola $field.");
        [$kind, $arg] = array_pad(explode(':', $type, 2), 2, '');
        switch ($kind) {
            case 'text':
                if (!is_string($v) || mb_strlen($v) > (int) $arg) {
                    throw $bad();
                }
                return trim(strip_tags($v));
            case 'html':
                if (!is_string($v) || strlen($v) > 200000) {
                    throw $bad();
                }
                return self::purify($v);
            case 'email':
                if (!is_string($v) || !filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 100) {
                    throw $bad();
                }
                return strtolower($v);
            case 'date':
                $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
                if (!$d || $d->format('Y-m-d') !== $v) {
                    throw $bad();
                }
                return $v;
            case 'decimal':
                if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                    throw $bad();
                }
                return (string) round((float) $v, 2);
            case 'int':
                [$min, $max] = array_pad(explode('-', $arg, 2), 2, '');
                if (!is_int($v) || ($min !== '' && $v < (int) $min) || ($max !== '' && $v > (int) $max)) {
                    throw $bad();
                }
                return $v;
            case 'enum':
                if (!in_array($v, explode(',', $arg), true)) {
                    throw $bad();
                }
                return $v;
            case 'ref':
                [$table, $col] = explode('.', $arg);
                if (!is_int($v) || !Store::db()->where($col, $v)->count_all_results(db_prefix() . $table)) {
                    throw $bad();
                }
                return $v;
            case 'staff':
                if (!is_int($v) || !Store::db()->where('staffid', $v)->where('active', 1)->count_all_results(db_prefix() . 'staff')) {
                    throw $bad();
                }
                return $v;
            case 'staff[]':
                if (!is_array($v) || !array_is_list($v) || count($v) > 20) {
                    throw $bad();
                }
                return array_values(array_unique(array_map(fn ($s) => self::value($field, 'staff', $s), $v)));
        }
        throw $bad();
    }

    private static function normalize(array $def, array $change, bool $create): array
    {
        $out = [];
        foreach ($change as $k => $v) {
            if (!isset($def['f'][$k])) {
                throw new Failure('invalid_change', "Pole $k nie jest dozwolone w tej akcji.");
            }
            // null czyści pole tylko przy zmianie istniejącego rekordu.
            $out[$k] = $v === null && !$create ? null : self::value($k, $def['f'][$k], $v);
        }
        if ($create) {
            foreach ($def['req'] ?? [] as $r) {
                if (!isset($out[$r]) || $out[$r] === '') {
                    throw new Failure('invalid_change', "Brak wymaganego pola $r.");
                }
            }
        } elseif (!$out) {
            throw new Failure('invalid_change', 'Brak pól do zmiany.');
        }
        return $out;
    }

    /**
     * Uprawnienia z roli Perfexa i ograniczenia „nic dla klienta”. $current to rekord docelowy
     * (dla update) albo nadrzędny (komentarz, szkic), pobrany i zablokowany w bieżącej transakcji.
     */
    private static function authorize(string $action, int $sid, array $change, ?array $current): void
    {
        $deny = fn (string $why = '') => new Failure('forbidden', $why !== '' ? $why : _l('mcai_err_forbidden'), 403);
        $visible = fn (string $resource, int $id) => self::visible($resource, $id, $sid);
        switch ($action) {
            case 'task.create':
                if (!staff_can('create', 'tasks', $sid)) {
                    throw $deny();
                }
                if (isset($change['rel_type']) !== isset($change['rel_id'])) {
                    throw new Failure('invalid_change', 'Podaj razem rel_type i rel_id.');
                }
                $map = ['customer' => 'customers', 'project' => 'projects', 'lead' => 'leads', 'ticket' => 'tickets'];
                if (isset($change['rel_type']) && !$visible($map[$change['rel_type']], $change['rel_id'])) {
                    throw $deny();
                }
                return;
            case 'task.update':
                $assigned = Store::db()->query('SELECT id FROM ' . db_prefix() . 'task_assigned WHERE taskid=? AND staffid=? LOCK IN SHARE MODE', [(int) $current['id'], $sid])->num_rows() > 0;
                if (!staff_can('edit', 'tasks', $sid) && !($assigned && array_keys($change) === ['status'])) {
                    throw $deny();
                }
                if ((int) $current['visible_to_client'] === 1 && array_intersect(array_keys($change), ['name', 'description'])) {
                    throw $deny('Zadanie jest widoczne dla klienta: nazwę i opis zmienia pracownik.');
                }
                return;
            case 'task.comment':
                if ((int) $current['visible_to_client'] === 1) {
                    throw $deny('Zadanie jest widoczne dla klienta: komentarz dodaje pracownik.');
                }
                return;
            case 'note.create':
                if (!$visible($change['rel_type'] === 'customer' ? 'customers' : 'leads', $change['rel_id'])) {
                    throw $deny();
                }
                return;
            case 'kb.create':
                if (!staff_can('create', 'knowledge_base', $sid)) {
                    throw $deny();
                }
                return;
            case 'kb.update':
                if (!staff_can('edit', 'knowledge_base', $sid)) {
                    throw $deny();
                }
                if ((int) $current['active'] === 1) {
                    throw $deny('Artykuł jest opublikowany: zmienia go pracownik w panelu.');
                }
                return;
            case 'customer.create':
                if (!staff_can('create', 'customers', $sid)) {
                    throw $deny();
                }
                return;
            case 'customer.update':
            case 'contact.update':
                if (!staff_can('edit', 'customers', $sid)) {
                    throw $deny();
                }
                return;
            case 'contact.create':
                if (!staff_can('edit', 'customers', $sid) || !$visible('customers', $change['userid'])) {
                    throw $deny();
                }
                return;
            case 'project.update':
                if (!staff_can('edit', 'projects', $sid)) {
                    throw $deny();
                }
                return;
        }
        // ticket.*, lead.*: wystarcza widoczność rekordu, jak w panelu Perfexa.
    }

    // ---- stan ----

    private static function hash(array $fields): string
    {
        ksort($fields);
        $norm = [];
        foreach ($fields as $k => $v) {
            $norm[$k] = $v === null ? null : (string) $v;
        }
        return hash('sha256', Store::json($norm));
    }

    /** $where: warunek widoczności osoby w tym samym zapytaniu (podgląd nie ujawni pól rekordu, który właśnie zniknął z zasięgu). */
    private static function snapshot(array $def, int $id, ?array $fields, bool $lock, ?string $where = null): ?array
    {
        $cols = $fields === null ? '*' : implode(',', array_map(fn ($c) => "`$c`", $fields));
        $row = Store::db()->query('SELECT ' . $cols . ' FROM `' . db_prefix() . $def['table'] . '` WHERE `' . $def['pk'] . '`=?' . ($where ? " AND $where" : '') . ($lock ? ' FOR UPDATE' : ''), [$id])->row_array();
        return $row ?: null;
    }

    /** Widoczność rekordu powiązanego, z blokadą współdzieloną do końca transakcji. */
    private static function visible(string $resource, int $id, int $sid): bool
    {
        $scope = Resources::scope($resource, $sid);
        if ($scope === null || $scope[0] === 'limited') {
            return false;
        }
        $def = Resources::map()[$resource];
        $where = isset($scope[1]) ? ' AND ' . $scope[1] : '';
        return Store::db()->query("SELECT `{$def['pk']}` FROM `" . db_prefix() . $def['table'] . "` WHERE `{$def['pk']}`=?$where LOCK IN SHARE MODE", [$id])->num_rows() === 1;
    }

    private static function columns(string $action, array $change): array
    {
        $cols = array_keys($change);
        if ($action === 'task.update' && in_array('status', $cols, true)) {
            $cols[] = 'datefinished'; // zmienia się razem ze statusem i wraca przy cofnięciu
        }
        return $cols;
    }

    /**
     * Rekord docelowy lub nadrzędny: tylko pola potrzebne do autoryzacji, z warunkiem widoczności
     * osoby w tym samym zapytaniu co blokada FOR UPDATE.
     */
    private static function target(array $def, ?int $id, int $sid, bool $lock): ?array
    {
        $resource = $def['resource'] ?? $def['parent'] ?? null;
        if ($resource === null) {
            return null;
        }
        $scope = Resources::scope($resource, $sid);
        $rdef = Resources::map()[$resource];
        if ($scope === null || $scope[0] === 'limited' || !$id) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        $fields = array_intersect(['visible_to_client', 'active'], Store::db()->list_fields(db_prefix() . $rdef['table']));
        $cols = implode(',', array_map(fn ($c) => "`$c`", array_merge([$rdef['pk']], $fields)));
        $where = isset($scope[1]) ? ' AND ' . $scope[1] : '';
        $row = Store::db()->query("SELECT $cols FROM `" . db_prefix() . $rdef['table'] . "` WHERE `{$rdef['pk']}`=?$where" . ($lock ? ' FOR UPDATE' : ''), [$id])->row_array();
        if (!$row) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        $row['id'] = $row[$rdef['pk']];
        return $row;
    }

    private static function uuid(): string
    {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    // ---- podgląd ----

    private static function preview(array $c, string $level, string $action, ?int $target, array $change): array
    {
        $def = self::actions()[$action] ?? null;
        if (!$def || !Levels::atLeast($level, $def['level'])) {
            throw new Failure('forbidden', _l('mcai_err_level'), 403);
        }
        $sid = (int) $c['staff_id'];
        $create = $def['mode'] === 'create';
        $needsTarget = !$create || isset($def['parent']);
        if ($needsTarget !== ($target !== null)) {
            throw new Failure('invalid_change', $needsTarget ? 'Brak target_id.' : 'Ta akcja nie przyjmuje target_id.');
        }
        $change = self::normalize($def, $change, $create);
        $current = self::target($def, $target, $sid, false);
        self::authorize($action, $sid, $change, $current);
        self::checkContact($action, $change, $target);
        $scope = $create ? null : Resources::scope($def['resource'], $sid);
        $before = $create ? [] : self::snapshot($def, $target, self::columns($action, $change), false, $scope[1] ?? null);
        if ($before === null) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        $plan = ['v' => self::PLAN_VERSION, 'action' => $action, 'target_id' => $target, 'change' => $change];
        $id = self::uuid();
        Store::db()->insert(Store::table('operations'), [
            'id' => $id, 'connection_id' => $c['id'], 'staff_id' => $sid, 'action' => $action, 'resource' => $def['table'],
            'target_id' => (int) $target, 'plan' => Store::json($plan), 'plan_hash' => hash('sha256', Store::json($plan)),
            'before_state' => Store::json($before), 'before_hash' => $create ? '' : self::hash($before),
            'status' => 'previewed', 'created_at' => time(), 'expires_at' => time() + self::TTL,
        ]);
        return ['operation_id' => $id, 'status' => 'previewed', 'action' => $action, 'target_id' => $target, 'before' => $before ?: null, 'after' => $change,
            'rollback' => self::rollbackable($def), 'expires_at' => date('c', time() + self::TTL),
            'next' => 'Pokaż podgląd użytkownikowi; po akceptacji crm_operation_execute z confirm=true.'];
    }

    /** Unikalny e-mail kontaktu. Przy wykonaniu i cofnięciu wołane pod blokadą „contacts” trzymaną do commitu. */
    private static function checkContact(string $action, array $change, ?int $target): void
    {
        if (!in_array($action, ['contact.create', 'contact.update'], true) || !isset($change['email'])) {
            return;
        }
        $db = Store::db()->where('email', $change['email']);
        if ($action === 'contact.update') {
            $db->where('id !=', (int) $target);
        }
        if ($db->count_all_results(db_prefix() . 'contacts') > 0) {
            throw new Failure('invalid_change', 'Kontakt z tym adresem e-mail już istnieje.');
        }
    }

    // ---- wykonanie i cofnięcie ----

    private static function load(array $c, string $id, bool $lock): array
    {
        if (!preg_match('/' . self::ID_PATTERN . '/D', $id)) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        $op = Store::db()->query('SELECT * FROM `' . Store::table('operations') . '` WHERE id=? AND connection_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id, $c['id']])->row_array();
        if (!$op) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        return $op;
    }

    private static function public(array $op): array
    {
        $def = self::actions()[$op['action']] ?? null;
        $current = (json_decode((string) $op['plan'], true)['v'] ?? 0) === self::PLAN_VERSION;
        return ['operation_id' => $op['id'], 'status' => $op['status'], 'action' => $op['action'], 'target_id' => (int) $op['target_id'] ?: null,
            'new_id' => (int) $op['new_id'] ?: null, 'rollback_available' => $op['status'] === 'verified' && $current && $def && self::rollbackable($def),
            'updated_at' => (int) $op['updated_at'] ? date('c', (int) $op['updated_at']) : null];
    }

    private static function fail(string $code, string $message, ?string $opStatus = null): Failure
    {
        $f = new Failure($code, $message, 409);
        $f->opStatus = $opStatus;
        return $f;
    }

    /**
     * Wspólna rama wykonania i cofnięcia. W transakcji: blokada wiersza operacji, świeże połączenie
     * i poziom, praca, zapis stanu. Konflikt lub wygaśnięcie zapisujemy po wycofaniu, tylko ze stanu previewed.
     */
    private static function transact(array $c, string $id, callable $work): array
    {
        $db = Store::db();
        $peek = self::load($c, $id, false);
        $contacts = str_starts_with($peek['action'], 'contact.');
        if ($contacts && !Store::lock('contacts', 5)) {
            throw self::fail('busy', 'Spróbuj ponownie za chwilę.');
        }
        try {
            if (!$db->trans_begin()) {
                throw new \RuntimeException('trans_begin');
            }
            try {
                $op = self::load($c, $id, true);
                // Wiersze decydujące o dostępie blokujemy do commitu: odcięcie połączenia, zmiana poziomu
                // czy roli w trakcie czeka na koniec tej operacji, a nie przechodzi obok niej.
                $p = db_prefix();
                $fresh = $db->query('SELECT * FROM `' . Store::table('connections') . '` WHERE id=? FOR UPDATE', [$c['id']])->row_array();
                $sid = (int) ($fresh['staff_id'] ?? 0);
                $db->query("SELECT staffid FROM {$p}staff WHERE staffid=? LOCK IN SHARE MODE", [$sid]);
                $db->query('SELECT staff_id FROM `' . Store::table('operators') . '` WHERE staff_id=? LOCK IN SHARE MODE', [$sid]);
                $db->query("SELECT staff_id FROM {$p}staff_permissions WHERE staff_id=? LOCK IN SHARE MODE", [$sid]);
                $level = $fresh ? Connections::effective($fresh) : null;
                if ($level === null) {
                    throw new Failure('unauthorized', _l('mcai_err_auth'), 401);
                }
                $plan = json_decode($op['plan'], true);
                $def = self::actions()[$op['action']] ?? null;
                if (!is_array($plan) || ($plan['v'] ?? 0) !== self::PLAN_VERSION || !$def) {
                    throw self::fail('expired', 'Ta operacja pochodzi ze starszej wersji modułu. Przygotuj nowy podgląd.', 'expired');
                }
                if (!Levels::atLeast($level, $def['level'])) {
                    throw new Failure('forbidden', _l('mcai_err_level'), 403);
                }
                [$status, $extra] = $work($op, $plan, $def, (int) $fresh['staff_id']);
                $extra += ['status' => $status, 'updated_at' => time()];
                $db->where('id', $id)->update(Store::table('operations'), $extra);
                if ($db->trans_status() === false || !$db->trans_commit()) {
                    throw new \RuntimeException('commit');
                }
                return self::public(array_merge($op, $extra));
            } catch (\Throwable $e) {
                $db->trans_rollback();
                if ($e instanceof Failure && $e->opStatus !== null) {
                    $db->where('id', $id)->where('status', 'previewed')->update(Store::table('operations'), ['status' => $e->opStatus, 'updated_at' => time()]);
                }
                if ($e instanceof Failure) {
                    throw $e;
                }
                log_message('error', 'Menedzer CRM AI operacja: ' . $e->getMessage());
                throw new Failure('failed', 'Zapis nie powiódł się; nic nie zostało zmienione.', 500);
            }
        } finally {
            if ($contacts) {
                Store::unlock('contacts');
            }
        }
    }

    private static function execute(array $c, string $id, string $key): array
    {
        $keyHash = hash('sha256', $key);
        return self::transact($c, $id, function (array $op, array $plan, array $def, int $sid) use ($c, $keyHash) {
            if ($op['status'] !== 'previewed') {
                if ($op['execution_key'] === $keyHash) {
                    return [$op['status'], []]; // to samo wykonanie: zwracamy zapisany wynik
                }
                throw self::fail('already_executed', 'Ta operacja została już wykonana albo zamknięta.');
            }
            if ((int) $op['expires_at'] < time()) {
                throw self::fail('expired', 'Podgląd wygasł. Przygotuj nowy.', 'expired');
            }
            $create = $def['mode'] === 'create';
            // Plan musi być dokładnie tym z podglądu i przejść bieżącą walidację bez żadnej zmiany treści.
            if (!hash_equals($op['plan_hash'], hash('sha256', Store::json($plan))) || $plan['action'] !== $op['action'] || (int) ($plan['target_id'] ?? 0) !== (int) $op['target_id']
                || Store::json(self::normalize($def, $plan['change'], $create)) !== Store::json($plan['change'])) {
                throw self::fail('expired', 'Plan operacji nie przechodzi bieżącej walidacji. Przygotuj nowy podgląd.', 'expired');
            }
            $current = self::target($def, $plan['target_id'] ?? null, $sid, true);
            self::authorize($plan['action'], $sid, $plan['change'], $current);
            self::checkContact($plan['action'], $plan['change'], $plan['target_id'] ?? null);
            $extra = ['execution_key' => $keyHash] + ($create ? self::applyCreate($def, $plan, $sid) : self::applyUpdate($op, $def, $plan));
            Connections::audit($c, 'operation.' . $plan['action'], 'verified', $op['id']);
            return ['verified', $extra];
        });
    }

    private static function applyUpdate(array $op, array $def, array $plan): array
    {
        $target = (int) $plan['target_id'];
        $cols = self::columns($plan['action'], $plan['change']);
        $now = self::snapshot($def, $target, $cols, true);
        if ($now === null || self::hash($now) !== $op['before_hash']) {
            throw self::fail('conflict', 'Rekord zmienił się po podglądzie. Przygotuj nowy podgląd.', 'conflict');
        }
        $data = $plan['change'];
        if ($plan['action'] === 'task.update' && isset($data['status'])) {
            $data['datefinished'] = (int) $data['status'] === 5 ? date('Y-m-d H:i:s') : null;
        }
        Store::db()->where($def['pk'], $target)->update(db_prefix() . $def['table'], $data);
        $after = self::snapshot($def, $target, $cols, true);
        if (self::hash(array_intersect_key($after, $plan['change'])) !== self::hash($plan['change'])) {
            throw new \RuntimeException('verify');
        }
        return ['after_hash' => self::hash($after)];
    }

    private static function applyCreate(array $def, array $plan, int $sid): array
    {
        $ch = $plan['change'];
        $now = date('Y-m-d H:i:s');
        $p = db_prefix();
        $db = Store::db();
        switch ($plan['action']) {
            case 'task.create':
                $row = array_diff_key($ch, ['assignees' => 1]) + ['description' => '', 'priority' => 2, 'status' => 1, 'startdate' => date('Y-m-d'), 'dateadded' => $now, 'addedfrom' => $sid, 'is_public' => 0, 'billable' => 0];
                $row['visible_to_client'] = 0;
                break;
            case 'task.comment':
                $row = ['content' => $ch['content'], 'taskid' => (int) $plan['target_id'], 'staffid' => $sid, 'contact_id' => 0, 'file_id' => 0, 'dateadded' => $now];
                break;
            case 'note.create':
                $row = $ch + ['addedfrom' => $sid, 'dateadded' => $now];
                break;
            case 'ticket.draft_reply':
                $row = ['rel_type' => 'ticket', 'rel_id' => (int) $plan['target_id'], 'description' => '[' . _l('mcai_draft_reply') . "]\n" . $ch['message'], 'addedfrom' => $sid, 'dateadded' => $now];
                break;
            case 'lead.create':
                $first = fn ($t) => (int) ($db->query("SELECT id FROM {$p}$t ORDER BY id LIMIT 1")->row_array()['id'] ?? 0);
                $row = $ch + ['status' => $first('leads_status'), 'source' => $first('leads_sources'), 'assigned' => $sid, 'addedfrom' => $sid, 'dateadded' => $now,
                    'hash' => app_generate_hash(), 'is_public' => 0, 'country' => 0, 'description' => '', 'lastcontact' => null];
                break;
            case 'kb.create':
                $slug = slug_it($ch['subject']);
                $base = $slug;
                for ($i = 2; $db->where('slug', $slug)->count_all_results($p . 'knowledge_base'); $i++) {
                    $slug = $base . '-' . $i;
                }
                // Szkic wewnętrzny: nieaktywny i tylko dla personelu. Publikuje człowiek.
                $row = $ch + ['slug' => $slug, 'datecreated' => $now, 'article_order' => 0];
                $row['active'] = 0;
                $row['staff_article'] = 1;
                break;
            case 'customer.create':
                $row = $ch + ['datecreated' => $now, 'addedfrom' => $sid, 'active' => 1, 'country' => 0, 'show_primary_contact' => 0, 'registration_confirmed' => 1, 'default_currency' => 0, 'default_language' => ''];
                break;
            case 'contact.create':
                // Pod blokadą „contacts” (transact): e-mail sprawdzony w tej transakcji, jeden kontakt główny.
                $primary = $db->query("SELECT id FROM {$p}contacts WHERE userid=? AND is_primary=1 FOR UPDATE", [$ch['userid']])->num_rows() === 0 ? 1 : 0;
                $row = $ch + ['phonenumber' => '', 'is_primary' => $primary, 'datecreated' => $now, 'active' => 1, 'email_verified_at' => $now];
                // Nowy kontakt nie staje się odbiorcą faktur, ofert ani powiadomień: włącza to pracownik.
                foreach (['invoice_emails', 'estimate_emails', 'credit_note_emails', 'contract_emails', 'task_emails', 'project_emails', 'ticket_emails'] as $flag) {
                    if (in_array($flag, $db->list_fields($p . 'contacts'), true)) {
                        $row[$flag] = 0;
                    }
                }
                break;
            default:
                throw new \RuntimeException('action');
        }
        $db->insert($p . $def['table'], $row);
        $newId = (int) $db->insert_id();
        if ($newId < 1) {
            throw new \RuntimeException('insert');
        }
        foreach ($ch['assignees'] ?? [] as $staff) {
            $db->insert($p . 'task_assigned', ['staffid' => $staff, 'taskid' => $newId, 'assigned_from' => $sid, 'is_assigned_from_contact' => 0]);
        }
        // Skrót całego wiersza tylko dla wpisów, które da się cofnąć (komentarz, notatka); inaczej samo potwierdzenie ID.
        $after = self::snapshot($def, $newId, empty($def['leaf']) ? [$def['pk']] : null, true);
        if ($after === null) {
            throw new \RuntimeException('verify');
        }
        return ['new_id' => $newId, 'after_hash' => empty($def['leaf']) ? '' : self::hash($after)];
    }

    private static function rollback(array $c, string $id): array
    {
        return self::transact($c, $id, function (array $op, array $plan, array $def, int $sid) use ($c) {
            if ($op['status'] !== 'verified' || !self::rollbackable($def)) {
                throw self::fail('rollback_unavailable', self::rollbackable($def) ? 'Cofnąć można tylko wykonaną i sprawdzoną operację.' : 'Tej akcji nie cofa AI. Rekord usuwa pracownik w panelu.');
            }
            $db = Store::db();
            $p = db_prefix();
            // Cofnięcie wymaga dziś tych samych praw i widoczności co wykonanie.
            $current = self::target($def, $plan['target_id'] ?? null, $sid, true);
            if ($def['mode'] === 'update') {
                $before = json_decode($op['before_state'], true);
                $expected = self::columns($plan['action'], $plan['change']);
                sort($expected);
                $keys = is_array($before) ? array_keys($before) : [];
                sort($keys);
                if ($keys !== $expected || self::hash($before) !== $op['before_hash']) {
                    throw self::fail('rollback_unavailable', 'Kopia stanu nie pasuje do tej operacji.');
                }
                $restore = array_diff_key($before, ['datefinished' => 1]);
                // Odtwarzane wartości muszą dziś przejść walidację (np. status zgłoszenia mógł zostać usunięty).
                foreach ($restore as $k => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $type = $def['f'][$k];
                    $typed = preg_match('/^(int|ref|staff)/', $type) && is_numeric($v) ? (int) $v : $v;
                    try {
                        self::value($k, $type, $typed);
                    } catch (Failure $e) {
                        throw self::fail('conflict', "Poprzednia wartość pola $k jest dziś nieprawidłowa. Nie cofam.");
                    }
                }
                self::authorize($plan['action'], $sid, $restore, $current);
                self::checkContact($plan['action'], $restore, (int) $plan['target_id']);
                $now = self::snapshot($def, (int) $plan['target_id'], array_keys($before), true);
                if ($now === null || self::hash($now) !== $op['after_hash']) {
                    throw self::fail('conflict', 'Rekord zmienił się po tej operacji. Cofnięcie przywróciłoby cudze zmiany.');
                }
                $db->where($def['pk'], (int) $plan['target_id'])->update($p . $def['table'], $before);
                if (self::hash(self::snapshot($def, (int) $plan['target_id'], array_keys($before), true)) !== self::hash($before)) {
                    throw new \RuntimeException('verify');
                }
            } else {
                // Własny komentarz, notatka lub szkic: usuwamy tylko niezmieniony wiersz tej operacji.
                self::authorize($plan['action'], $sid, $plan['change'], $current);
                $newId = (int) $op['new_id'];
                $now = self::snapshot($def, $newId, null, true);
                if ($now === null || $op['after_hash'] === '' || self::hash($now) !== $op['after_hash']) {
                    throw self::fail('conflict', 'Wpis zmienił się po tej operacji. Nie cofam.');
                }
                if ($plan['action'] === 'task.comment') {
                    // Reguła Perfexa (Tasks_model::remove_comment): po godzinie usuwa tylko administrator.
                    if (get_option('client_staff_add_edit_delete_task_comments_first_hour') == 1 && strtotime($now['dateadded']) < strtotime('-1 hours') && !is_admin($sid)) {
                        throw self::fail('rollback_unavailable', 'Komentarz ma ponad godzinę; usuwa go administrator.');
                    }
                    if ((int) $now['file_id'] !== 0 || $db->query("SELECT id FROM {$p}files WHERE task_comment_id=? LOCK IN SHARE MODE", [$newId])->num_rows() > 0) {
                        throw self::fail('conflict', 'Do komentarza dołączono plik. Nie cofam.');
                    }
                }
                $db->where($def['pk'], $newId)->delete($p . $def['table']);
            }
            Connections::audit($c, 'rollback.' . $plan['action'], 'rolled_back', $op['id']);
            return ['rolled_back', []];
        });
    }
}
