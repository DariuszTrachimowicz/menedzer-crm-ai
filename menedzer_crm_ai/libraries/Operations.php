<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Zapisy przez AI: podgląd, wykonanie, sprawdzenie i cofnięcie.
 * - Piszemy bezpośrednio do tabel, bez modeli Perfexa: żadna zmiana nie wysyła maila ani powiadomienia.
 * - Nic, co widzi klient: artykuły bazy wiedzy tylko jako wewnętrzne szkice, bez komentarzy
 *   i zmian treści w zadaniach widocznych dla klienta, szkice odpowiedzi jako notatki wewnętrzne.
 * - Usuwania istniejących danych nie ma. Cofnięcie utworzenia usuwa wyłącznie rekord tej operacji,
 *   jeśli nikt go od tego czasu nie zmienił i nic do niego nie dopiął.
 * - Wykonanie i cofnięcie: jedna transakcja z blokadą wiersza operacji i rekordu docelowego,
 *   uprawnienia sprawdzane w tej samej transakcji, stan operacji zapisywany razem ze zmianą.
 */
final class Operations
{
    private const TTL = 900;
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
                'f' => $taskF + ['rel_type' => 'enum:customer,project,lead,ticket', 'rel_id' => 'int:1-', 'assignees' => 'staff[]'], 'desc' => 'Nowe zadanie wewnętrzne (niewidoczne dla klienta), opcjonalnie przy kliencie, projekcie, leadzie lub zgłoszeniu i z przypisanymi osobami.'],
            'task.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tasks', 'pk' => 'id', 'resource' => 'tasks', 'f' => $taskF,
                'desc' => 'Zmiana pól zadania. Osoba przypisana bez prawa edycji zmienia tylko status. W zadaniu widocznym dla klienta bez zmiany nazwy i opisu.'],
            'task.comment' => ['level' => 'work', 'mode' => 'create', 'table' => 'task_comments', 'pk' => 'id', 'parent' => 'tasks', 'req' => ['content'], 'f' => ['content' => 'html'],
                'desc' => 'Komentarz do zadania niewidocznego dla klienta (target_id = id zadania).'],
            'note.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'notes', 'pk' => 'id', 'req' => ['rel_type', 'rel_id', 'description'],
                'f' => ['rel_type' => 'enum:customer,lead', 'rel_id' => 'int:1-', 'description' => 'text:10000'], 'desc' => 'Notatka wewnętrzna przy kliencie lub leadzie.'],
            'ticket.draft_reply' => ['level' => 'work', 'mode' => 'create', 'table' => 'notes', 'pk' => 'id', 'parent' => 'tickets', 'req' => ['message'], 'f' => ['message' => 'text:20000'],
                'desc' => 'Szkic odpowiedzi jako notatka wewnętrzna zgłoszenia (target_id = id zgłoszenia). Nic nie trafia do klienta; wysyła pracownik.'],
            'ticket.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tickets', 'pk' => 'ticketid', 'resource' => 'tickets',
                'f' => ['status' => 'ref:tickets_status.ticketstatusid', 'priority' => 'ref:tickets_priorities.priorityid', 'assigned' => 'staff', 'department' => 'ref:departments.departmentid'],
                'desc' => 'Status, priorytet, dział lub osoba przypisana, bez maila do klienta.'],
            'lead.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'leads', 'pk' => 'id', 'req' => ['name'], 'f' => $leadF, 'desc' => 'Nowy lead.'],
            'lead.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'leads', 'pk' => 'id', 'resource' => 'leads', 'f' => $leadF, 'desc' => 'Zmiana pól leada.'],
            'kb.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'knowledge_base', 'pk' => 'articleid', 'req' => ['subject', 'description', 'articlegroup'], 'f' => $kbF,
                'desc' => 'Artykuł bazy wiedzy jako nieaktywny szkic wewnętrzny. Publikuje pracownik w panelu.'],
            'kb.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'knowledge_base', 'pk' => 'articleid', 'resource' => 'knowledge_base', 'f' => $kbF,
                'desc' => 'Zmiana artykułu, który jest nieaktywnym szkicem. Opublikowanych nie zmienia.'],
            'customer.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'clients', 'pk' => 'userid', 'req' => ['company'], 'f' => $custF, 'desc' => 'Nowy klient (firma).'],
            'customer.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'clients', 'pk' => 'userid', 'resource' => 'customers', 'f' => $custF, 'desc' => 'Zmiana danych klienta.'],
            'contact.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'contacts', 'pk' => 'id', 'req' => ['userid', 'firstname', 'lastname', 'email'], 'f' => ['userid' => 'int:1-'] + $contactF,
                'desc' => 'Nowy kontakt klienta, bez hasła i bez maila powitalnego.'],
            'contact.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'contacts', 'pk' => 'id', 'resource' => 'contacts', 'f' => $contactF, 'desc' => 'Zmiana danych kontaktu.'],
            'project.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'projects', 'pk' => 'id', 'resource' => 'projects',
                'f' => ['status' => 'int:1-5', 'start_date' => 'date', 'deadline' => 'date', 'progress' => 'int:0-100'], 'desc' => 'Status, daty i postęp projektu.'],
        ];
    }

    public static function catalog(string $level): array
    {
        $out = [];
        foreach (self::actions() as $name => $a) {
            if (Levels::atLeast($level, $a['level'])) {
                $out[$name] = ['description' => $a['desc'], 'target_id' => $a['mode'] === 'update' || isset($a['parent']), 'fields' => $a['f'], 'required' => $a['req'] ?? []];
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
            ['name' => 'crm_operation_status', 'title' => 'Stan operacji', 'description' => 'Stan operacji: previewed, verified, failed, conflict, expired, rolled_back.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id'], 'additionalProperties' => false, 'properties' => ['operation_id' => $op]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'crm_operation_rollback', 'title' => 'Cofnij zmianę', 'description' => 'Przywraca stan sprzed operacji, jeśli od tego czasu nikt nie zmienił rekordu ani nic do niego nie dopiął.',
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
                return self::execute($c, $level, (string) $a['operation_id'], (string) $a['idempotency_key']);
            case 'crm_operation_status':
                return self::public(self::load($c, (string) $a['operation_id'], false));
            case 'crm_operation_rollback':
                return self::rollback($c, $level, (string) $a['operation_id']);
        }
        throw new Failure('unknown_tool', _l('mcai_err_tool'), 404);
    }

    // ---- walidacja ----

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
                // Bez oczyszczacza Perfexa zapisujemy sam tekst: atrybuty HTML mogłyby nieść skrypt.
                return function_exists('html_purify') ? html_purify($v) : htmlspecialchars(strip_tags($v), ENT_QUOTES, 'UTF-8');
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
     * (dla update) albo nadrzędny (komentarz, szkic odpowiedzi), pobrany w bieżącej transakcji.
     */
    private static function authorize(string $action, int $sid, array $change, ?array $current): void
    {
        $deny = fn (string $why = '') => new Failure('forbidden', $why !== '' ? $why : _l('mcai_err_forbidden'), 403);
        $visible = fn (string $resource, int $id) => Resources::row($resource, $id, $sid) !== null;
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
                $assigned = Store::db()->where('taskid', (int) $current['id'])->where('staffid', $sid)->count_all_results(db_prefix() . 'task_assigned') > 0;
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

    private static function snapshot(array $def, int $id, ?array $fields, bool $lock): ?array
    {
        $cols = $fields === null ? '*' : implode(',', array_map(fn ($c) => "`$c`", $fields));
        $row = Store::db()->query('SELECT ' . $cols . ' FROM `' . db_prefix() . $def['table'] . '` WHERE `' . $def['pk'] . '`=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->row_array();
        return $row ?: null;
    }

    /** Kolumny, które zmienia aktualizacja. */
    private static function columns(string $action, array $change): array
    {
        $cols = array_keys($change);
        if ($action === 'task.update' && in_array('status', $cols, true)) {
            $cols[] = 'datefinished'; // zmienia się razem ze statusem i wraca przy cofnięciu
        }
        return $cols;
    }

    /** Rekord docelowy lub nadrzędny, widoczny dla osoby, zablokowany w bieżącej transakcji. */
    private static function target(array $def, array $plan, int $sid, bool $lock): ?array
    {
        $resource = $def['resource'] ?? $def['parent'] ?? null;
        if ($resource === null) {
            return null;
        }
        $id = (int) $plan['target_id'];
        if (Resources::row($resource, $id, $sid) === null) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        $rdef = Resources::map()[$resource];
        $row = Store::db()->query('SELECT * FROM `' . db_prefix() . $rdef['table'] . '` WHERE `' . $rdef['pk'] . '`=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->row_array();
        if (!$row) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
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
        $plan = ['action' => $action, 'target_id' => $target, 'change' => $change];
        $current = $needsTarget ? self::target($def, $plan, $sid, false) : null;
        self::authorize($action, $sid, $change, $current);
        self::checkContact($action, $change, $target);
        $before = $create ? [] : self::snapshot($def, $target, self::columns($action, $change), false);
        $id = self::uuid();
        Store::db()->insert(Store::table('operations'), [
            'id' => $id, 'connection_id' => $c['id'], 'staff_id' => $sid, 'action' => $action, 'resource' => $def['table'],
            'target_id' => (int) $target, 'plan' => Store::json($plan), 'plan_hash' => hash('sha256', Store::json($plan)),
            'before_state' => Store::json($before), 'before_hash' => $create ? '' : self::hash($before),
            'status' => 'previewed', 'created_at' => time(), 'expires_at' => time() + self::TTL,
        ]);
        return ['operation_id' => $id, 'status' => 'previewed', 'action' => $action, 'target_id' => $target, 'before' => $before ?: null, 'after' => $change,
            'expires_at' => date('c', time() + self::TTL), 'next' => 'Pokaż podgląd użytkownikowi; po akceptacji crm_operation_execute z confirm=true.'];
    }

    /** Unikalny e-mail kontaktu; przy wykonaniu wołane pod blokadą „contacts”. */
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
        return ['operation_id' => $op['id'], 'status' => $op['status'], 'action' => $op['action'], 'target_id' => (int) $op['target_id'] ?: null,
            'new_id' => (int) $op['new_id'] ?: null, 'rollback_available' => $op['status'] === 'verified', 'updated_at' => (int) $op['updated_at'] ? date('c', (int) $op['updated_at']) : null];
    }

    private static function setStatus(string $id, string $status, array $extra = []): void
    {
        Store::db()->where('id', $id)->update(Store::table('operations'), $extra + ['status' => $status, 'updated_at' => time()]);
    }

    /**
     * Wspólna rama: transakcja, blokada wiersza operacji, praca, zapis stanu w tej samej transakcji.
     * Gdy praca zgłosi konflikt albo błąd, zmiany CRM są wycofane, a stan operacji zapisany osobno.
     */
    private static function transact(array $c, string $id, callable $work): array
    {
        $db = Store::db();
        $db->trans_begin();
        try {
            $op = self::load($c, $id, true);
            [$status, $extra, $result] = $work($op);
            self::setStatus($id, $status, $extra);
            if ($db->trans_status() === false) {
                throw new \RuntimeException('db');
            }
            $db->trans_commit();
            return $result + self::public(array_merge($op, $extra, ['status' => $status, 'updated_at' => time()]));
        } catch (\Throwable $e) {
            $db->trans_rollback();
            if ($e instanceof Failure && $e->opStatus !== null) {
                self::setStatus($id, $e->opStatus);
            }
            if ($e instanceof Failure) {
                throw $e;
            }
            log_message('error', 'Menedzer CRM AI operacja: ' . $e->getMessage());
            throw new Failure('failed', 'Zapis nie powiódł się; nic nie zostało zmienione.', 500);
        }
    }

    private static function fail(string $code, string $message, ?string $opStatus = null): Failure
    {
        $f = new Failure($code, $message, 409);
        if ($opStatus !== null) {
            $f->opStatus = $opStatus;
        }
        return $f;
    }

    private static function execute(array $c, string $level, string $id, string $key): array
    {
        $keyHash = hash('sha256', $key);
        return self::transact($c, $id, function (array $op) use ($c, $level, $keyHash) {
            if ($op['status'] !== 'previewed') {
                if ($op['execution_key'] === $keyHash) {
                    return [$op['status'], [], []]; // to samo wykonanie: zwracamy zapisany wynik
                }
                throw self::fail('already_executed', 'Ta operacja została już wykonana albo zamknięta.');
            }
            if ((int) $op['expires_at'] < time()) {
                throw self::fail('expired', 'Podgląd wygasł. Przygotuj nowy.', 'expired');
            }
            $plan = json_decode($op['plan'], true);
            $def = self::actions()[$plan['action']];
            $sid = (int) $c['staff_id'];
            if (!Levels::atLeast($level, $def['level'])) {
                throw new Failure('forbidden', _l('mcai_err_level'), 403);
            }
            $current = self::target($def, $plan, $sid, true);
            self::authorize($plan['action'], $sid, $plan['change'], $current);
            $extra = ['execution_key' => $keyHash];
            if ($def['mode'] === 'update') {
                $extra += self::applyUpdate($op, $def, $plan);
            } else {
                $extra += self::applyCreate($def, $plan, $sid);
            }
            Connections::audit($c, 'operation.' . $plan['action'], 'verified', $op['id']);
            return ['verified', $extra, []];
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
                // Unikalność e-maila i jeden kontakt główny: sprawdzane ponownie pod blokadą.
                if (!Store::lock('contacts', 5)) {
                    throw self::fail('busy', 'Spróbuj ponownie za chwilę.');
                }
                try {
                    self::checkContact('contact.create', $ch, null);
                    $primary = $db->where('userid', $ch['userid'])->where('is_primary', 1)->count_all_results($p . 'contacts') === 0 ? 1 : 0;
                    $row = $ch + ['phonenumber' => '', 'is_primary' => $primary, 'datecreated' => $now, 'active' => 1, 'email_verified_at' => $now];
                    $db->insert($p . $def['table'], $row);
                    $newId = (int) $db->insert_id();
                } finally {
                    Store::unlock('contacts');
                }
                break;
            default:
                throw new \RuntimeException('action');
        }
        if (!isset($newId)) {
            $db->insert($p . $def['table'], $row);
            $newId = (int) $db->insert_id();
        }
        if ($newId < 1) {
            throw new \RuntimeException('insert');
        }
        $links = [];
        foreach ($ch['assignees'] ?? [] as $staff) {
            $db->insert($p . 'task_assigned', ['staffid' => $staff, 'taskid' => $newId, 'assigned_from' => $sid, 'is_assigned_from_contact' => 0]);
            $links[] = (int) $db->insert_id();
        }
        $after = self::snapshot($def, $newId, null, true);
        if ($after === null) {
            throw new \RuntimeException('verify');
        }
        // Kopia całego rekordu i dokładnych powiązań utworzonych przez tę operację.
        return ['new_id' => $newId, 'after_hash' => self::hash($after), 'before_state' => Store::json(['links' => $links])];
    }

    private static function rollback(array $c, string $level, string $id): array
    {
        return self::transact($c, $id, function (array $op) use ($c, $level) {
            if ($op['status'] !== 'verified') {
                throw self::fail('rollback_unavailable', 'Cofnąć można tylko wykonaną i sprawdzoną operację.');
            }
            $plan = json_decode($op['plan'], true);
            $def = self::actions()[$plan['action']];
            $sid = (int) $c['staff_id'];
            if (!Levels::atLeast($level, $def['level'])) {
                throw new Failure('forbidden', _l('mcai_err_level'), 403);
            }
            $db = Store::db();
            $p = db_prefix();
            // Cofnięcie wymaga dziś tych samych praw co wykonanie.
            $current = self::target($def, $plan, $sid, true);
            self::authorize($plan['action'], $sid, $plan['change'], $current);
            if ($def['mode'] === 'update') {
                $before = json_decode($op['before_state'], true);
                $now = self::snapshot($def, (int) $plan['target_id'], array_keys($before), true);
                if ($now === null || self::hash($now) !== $op['after_hash']) {
                    throw self::fail('conflict', 'Rekord zmienił się po tej operacji. Cofnięcie przywróciłoby cudze zmiany.');
                }
                $db->where($def['pk'], (int) $plan['target_id'])->update($p . $def['table'], $before);
                if (self::hash(self::snapshot($def, (int) $plan['target_id'], array_keys($before), true)) !== self::hash($before)) {
                    throw new \RuntimeException('verify');
                }
            } else {
                $newId = (int) $op['new_id'];
                $links = json_decode($op['before_state'], true)['links'] ?? [];
                $now = self::snapshot($def, $newId, null, true);
                if ($now === null || self::hash($now) !== $op['after_hash'] || self::hasChildren($plan['action'], $newId, $links)) {
                    throw self::fail('conflict', 'Utworzony rekord zmienił się albo ma już powiązane dane. Nie cofam.');
                }
                if ($links) {
                    $db->where_in('id', $links)->delete($p . 'task_assigned');
                }
                $db->where($def['pk'], $newId)->delete($p . $def['table']);
            }
            Connections::audit($c, 'rollback.' . $plan['action'], 'rolled_back', $op['id']);
            return ['rolled_back', [], []];
        });
    }

    /** Czy do rekordu utworzonego przez AI ktoś coś dopiął. Przypisania liczą się tylko te z tej operacji. */
    private static function hasChildren(string $action, int $id, array $links): bool
    {
        $p = db_prefix();
        $db = Store::db();
        if ($action === 'task.create') {
            $assigned = array_map('intval', array_column($db->query("SELECT id FROM {$p}task_assigned WHERE taskid=? FOR UPDATE", [$id])->result_array(), 'id'));
            sort($assigned);
            sort($links);
            if ($assigned !== array_map('intval', $links)) {
                return true;
            }
        }
        $checks = match ($action) {
            'task.create' => [['task_followers', 'taskid'], ['task_comments', 'taskid'], ['task_checklist_items', 'taskid'], ['taskstimers', 'task_id'], ['files', 'rel_id', "rel_type='task'"], ['notes', 'rel_id', "rel_type='task'"]],
            'customer.create' => [['contacts', 'userid'], ['invoices', 'clientid'], ['estimates', 'clientid'], ['projects', 'clientid'], ['tickets', 'userid'], ['contracts', 'client'],
                ['customer_admins', 'customer_id'], ['customer_groups', 'customer_id'], ['notes', 'rel_id', "rel_type='customer'"], ['tasks', 'rel_id', "rel_type='customer'"],
                ['proposals', 'rel_id', "rel_type='customer'"], ['files', 'rel_id', "rel_type='customer'"], ['expenses', 'clientid'], ['creditnotes', 'clientid']],
            'lead.create' => [['notes', 'rel_id', "rel_type='lead'"], ['tasks', 'rel_id', "rel_type='lead'"], ['proposals', 'rel_id', "rel_type='lead'"], ['files', 'rel_id', "rel_type='lead'"]],
            'contact.create' => [['tickets', 'contactid'], ['task_comments', 'contact_id']],
            default => [],
        };
        foreach ($checks as $ch) {
            if (!$db->table_exists($p . $ch[0])) {
                continue;
            }
            $db->from($p . $ch[0])->where($ch[1], $id);
            if (isset($ch[2])) {
                $db->where($ch[2], null, false);
            }
            if ($db->count_all_results() > 0) {
                return true;
            }
        }
        return false;
    }
}
