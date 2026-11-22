<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Zapisy przez AI: podgląd, wykonanie pod blokadą z kontrolą stanu, sprawdzenie wyniku i cofnięcie.
 * Piszemy bezpośrednio do tabel, bez modeli Perfexa, żeby żadna zmiana nie wysłała maila ani
 * powiadomienia do klienta. Usuwania istniejących danych nie ma; cofnięcie utworzenia usuwa
 * wyłącznie rekord, który powstał w tej operacji i od tego czasu się nie zmienił.
 */
final class Operations
{
    private const TTL = 900;

    /** Definicje akcji. f: pola z typem; req: wymagane przy tworzeniu. */
    private static function actions(): array
    {
        $taskF = ['name' => 'text:200', 'description' => 'html', 'startdate' => 'date', 'duedate' => 'date', 'priority' => 'int:1-4', 'status' => 'int:1-5'];
        $leadF = ['name' => 'text:191', 'company' => 'text:191', 'title' => 'text:100', 'email' => 'email', 'phonenumber' => 'text:50', 'website' => 'text:150',
            'description' => 'text:5000', 'status' => 'ref:leads_status.id', 'source' => 'ref:leads_sources.id', 'assigned' => 'staff', 'lead_value' => 'decimal',
            'address' => 'text:100', 'city' => 'text:100', 'zip' => 'text:15', 'state' => 'text:50', 'country' => 'ref:countries.country_id'];
        $kbF = ['subject' => 'text:191', 'description' => 'html', 'articlegroup' => 'ref:knowledge_base_groups.groupid', 'active' => 'int:0-1'];
        $custF = ['company' => 'text:191', 'vat' => 'text:50', 'phonenumber' => 'text:30', 'website' => 'text:150', 'address' => 'text:191', 'city' => 'text:100',
            'state' => 'text:50', 'zip' => 'text:15', 'country' => 'ref:countries.country_id'];
        $contactF = ['firstname' => 'text:191', 'lastname' => 'text:191', 'email' => 'email', 'phonenumber' => 'text:100', 'title' => 'text:100'];
        return [
            'task.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'tasks', 'pk' => 'id', 'req' => ['name'],
                'f' => $taskF + ['rel_type' => 'enum:customer,project,lead,ticket', 'rel_id' => 'int:1-', 'assignees' => 'staff[]'], 'desc' => 'Nowe zadanie (opcjonalnie przypisane do klienta, projektu, leada lub zgłoszenia i do osób).'],
            'task.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tasks', 'pk' => 'id', 'resource' => 'tasks', 'f' => $taskF, 'desc' => 'Zmiana pól zadania; osoba przypisana może zmienić sam status.'],
            'task.comment' => ['level' => 'work', 'mode' => 'create', 'table' => 'task_comments', 'pk' => 'id', 'parent' => 'tasks', 'req' => ['content'], 'f' => ['content' => 'html'], 'desc' => 'Komentarz do zadania (target_id = id zadania).'],
            'note.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'notes', 'pk' => 'id', 'req' => ['rel_type', 'rel_id', 'description'],
                'f' => ['rel_type' => 'enum:customer,lead', 'rel_id' => 'int:1-', 'description' => 'text:10000'], 'desc' => 'Notatka wewnętrzna przy kliencie lub leadzie.'],
            'ticket.draft_reply' => ['level' => 'work', 'mode' => 'create', 'table' => 'notes', 'pk' => 'id', 'parent' => 'tickets', 'req' => ['message'], 'f' => ['message' => 'text:20000'],
                'desc' => 'Szkic odpowiedzi jako notatka wewnętrzna zgłoszenia (target_id = id zgłoszenia). Nic nie trafia do klienta; wysyła pracownik.'],
            'ticket.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'tickets', 'pk' => 'ticketid', 'resource' => 'tickets',
                'f' => ['status' => 'ref:tickets_status.ticketstatusid', 'priority' => 'ref:tickets_priorities.priorityid', 'assigned' => 'staff', 'department' => 'ref:departments.departmentid'], 'desc' => 'Status, priorytet, dział lub osoba przypisana (bez maila do klienta).'],
            'lead.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'leads', 'pk' => 'id', 'req' => ['name'], 'f' => $leadF, 'desc' => 'Nowy lead.'],
            'lead.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'leads', 'pk' => 'id', 'resource' => 'leads', 'f' => $leadF, 'desc' => 'Zmiana pól leada.'],
            'kb.create' => ['level' => 'work', 'mode' => 'create', 'table' => 'knowledge_base', 'pk' => 'articleid', 'req' => ['subject', 'description', 'articlegroup'], 'f' => $kbF, 'desc' => 'Artykuł bazy wiedzy; domyślnie nieaktywny (szkic).'],
            'kb.update' => ['level' => 'work', 'mode' => 'update', 'table' => 'knowledge_base', 'pk' => 'articleid', 'resource' => 'knowledge_base', 'f' => $kbF, 'desc' => 'Zmiana artykułu bazy wiedzy.'],
            'customer.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'clients', 'pk' => 'userid', 'req' => ['company'], 'f' => $custF, 'desc' => 'Nowy klient (firma).'],
            'customer.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'clients', 'pk' => 'userid', 'resource' => 'customers', 'f' => $custF, 'desc' => 'Zmiana danych klienta.'],
            'contact.create' => ['level' => 'full', 'mode' => 'create', 'table' => 'contacts', 'pk' => 'id', 'req' => ['userid', 'firstname', 'lastname', 'email'], 'f' => ['userid' => 'int:1-'] + $contactF,
                'desc' => 'Nowy kontakt klienta, bez hasła i bez maila powitalnego.'],
            'contact.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'contacts', 'pk' => 'id', 'resource' => 'contacts', 'f' => $contactF, 'desc' => 'Zmiana danych kontaktu.'],
            'project.update' => ['level' => 'full', 'mode' => 'update', 'table' => 'projects', 'pk' => 'id', 'resource' => 'projects',
                'f' => ['name' => 'text:191', 'description' => 'html', 'status' => 'int:1-5', 'start_date' => 'date', 'deadline' => 'date', 'progress' => 'int:0-100'], 'desc' => 'Zmiana pól projektu.'],
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
        $names = array_keys(self::catalog($level));
        $op = ['type' => 'string', 'pattern' => '^[a-f0-9-]{36}$'];
        return [
            ['name' => 'crm_operation_preview', 'title' => 'Podgląd zmiany', 'description' => 'Przygotowuje zmianę i pokazuje stan przed i po. Nic nie zapisuje. Lista akcji i pól: crm_status.actions.',
                'inputSchema' => ['type' => 'object', 'required' => ['action', 'change'], 'additionalProperties' => false, 'properties' => [
                    'action' => ['type' => 'string', 'enum' => $names], 'target_id' => ['type' => 'integer', 'minimum' => 1], 'change' => ['type' => 'object'],
                ]], 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false]],
            ['name' => 'crm_operation_execute', 'title' => 'Wykonaj zmianę', 'description' => 'Wykonuje zmianę z podglądu. Wymaga confirm=true i stałego idempotency_key; po niejasnym wyniku sprawdź crm_operation_status zamiast ponawiać.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id', 'confirm', 'idempotency_key'], 'additionalProperties' => false, 'properties' => [
                    'operation_id' => $op, 'confirm' => ['type' => 'boolean'], 'idempotency_key' => ['type' => 'string', 'maxLength' => 100],
                ]], 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]],
            ['name' => 'crm_operation_status', 'title' => 'Stan operacji', 'description' => 'Stan operacji: previewed, verified, failed, conflict, rolled_back.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id'], 'additionalProperties' => false, 'properties' => ['operation_id' => $op]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'crm_operation_rollback', 'title' => 'Cofnij zmianę', 'description' => 'Przywraca stan sprzed wykonanej operacji, jeśli od tego czasu nikt nie zmienił tego rekordu.',
                'inputSchema' => ['type' => 'object', 'required' => ['operation_id', 'confirm'], 'additionalProperties' => false, 'properties' => ['operation_id' => $op, 'confirm' => ['type' => 'boolean']]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false]],
        ];
    }

    public static function call(array $c, string $level, string $tool, array $a): array
    {
        switch ($tool) {
            case 'crm_operation_preview':
                return self::preview($c, $level, (string) $a['action'], isset($a['target_id']) ? (int) $a['target_id'] : null, (array) $a['change']);
            case 'crm_operation_execute':
                if ($a['confirm'] !== true) {
                    throw new Failure('confirm_required', 'Wymagane confirm=true.');
                }
                return self::execute($c, $level, (string) $a['operation_id'], (string) $a['idempotency_key']);
            case 'crm_operation_status':
                return self::public(self::load($c, (string) $a['operation_id']));
            case 'crm_operation_rollback':
                if ($a['confirm'] !== true) {
                    throw new Failure('confirm_required', 'Wymagane confirm=true.');
                }
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
                return function_exists('html_purify') ? html_purify($v) : strip_tags($v, '<p><br><b><strong><i><em><ul><ol><li><a><h2><h3><h4><blockquote><code><pre><table><tr><td><th><thead><tbody>');
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

    /** Sprawdza rolę w Perfexie i widoczność rekordów powiązanych. */
    private static function authorize(string $action, array $def, int $sid, ?int $target, array $change, ?array $current): void
    {
        $deny = fn () => new Failure('forbidden', _l('mcai_err_forbidden'), 403);
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
                $assigned = Store::db()->where('taskid', $target)->where('staffid', $sid)->count_all_results(db_prefix() . 'task_assigned') > 0;
                if (!staff_can('edit', 'tasks', $sid) && !($assigned && array_keys($change) === ['status'])) {
                    throw $deny();
                }
                return;
            case 'note.create':
                if (!$visible($change['rel_type'] === 'customer' ? 'customers' : 'leads', $change['rel_id'])) {
                    throw $deny();
                }
                return;
            case 'kb.create':
            case 'kb.update':
                if (!staff_can($def['mode'] === 'create' ? 'create' : 'edit', 'knowledge_base', $sid)) {
                    throw $deny();
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
        // task.comment, ticket.*, lead.*: wystarcza widoczność rekordu, jak w panelu Perfexa.
    }

    // ---- stan ----

    private static function hash(array $fields): string
    {
        $norm = [];
        ksort($fields);
        foreach ($fields as $k => $v) {
            $norm[$k] = $v === null ? null : (is_array($v) ? $v : (string) $v);
        }
        return hash('sha256', Store::json($norm));
    }

    private static function snapshot(array $def, int $id, array $fields, bool $lock = false): ?array
    {
        $cols = implode(',', array_map(fn ($c) => "`$c`", $fields));
        $row = Store::db()->query('SELECT ' . $cols . ' FROM `' . db_prefix() . $def['table'] . '` WHERE `' . $def['pk'] . '`=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->row_array();
        return $row ?: null;
    }

    /** Kolumny, które zapisuje akcja (bez pól pomocniczych jak assignees). */
    private static function columns(string $action, array $change): array
    {
        $cols = array_keys($change);
        if ($action === 'ticket.draft_reply') {
            return ['description'];
        }
        if ($action === 'task.update' && in_array('status', $cols, true)) {
            $cols[] = 'datefinished'; // zmienia się razem ze statusem i wraca przy cofnięciu
        }
        return array_values(array_diff($cols, ['assignees']));
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
        $change = self::normalize($def, $change, $create);
        $before = [];
        if (!$create || isset($def['parent'])) {
            if (!$target) {
                throw new Failure('invalid_change', 'Brak target_id.');
            }
            if (Resources::row($def['resource'] ?? $def['parent'], $target, $sid) === null) {
                throw new Failure('not_found', _l('mcai_err_not_found'), 404);
            }
        }
        self::authorize($action, $def, $sid, $target, $change, null);
        if ($action === 'contact.create' || $action === 'contact.update') {
            $dupe = Store::db()->where('email', $change['email'] ?? '')->where('id !=', (int) $target)->count_all_results(db_prefix() . 'contacts');
            if (isset($change['email']) && $dupe) {
                throw new Failure('invalid_change', 'Kontakt z tym adresem e-mail już istnieje.');
            }
        }
        if (!$create) {
            $before = self::snapshot($def, $target, self::columns($action, $change));
        }
        $h = bin2hex(random_bytes(16));
        $id = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
        $plan = ['action' => $action, 'target_id' => $target, 'change' => $change];
        Store::db()->insert(Store::table('operations'), [
            'id' => $id, 'connection_id' => $c['id'], 'staff_id' => $sid, 'action' => $action, 'resource' => $def['table'],
            'target_id' => (int) $target, 'plan' => Store::json($plan), 'plan_hash' => hash('sha256', Store::json($plan)),
            'before_state' => Store::json($before), 'before_hash' => $create ? '' : self::hash($before),
            'status' => 'previewed', 'created_at' => time(), 'expires_at' => time() + self::TTL,
        ]);
        return ['operation_id' => $id, 'status' => 'previewed', 'action' => $action, 'target_id' => $target, 'before' => $before ?: null, 'after' => $change,
            'expires_at' => date('c', time() + self::TTL), 'next' => 'crm_operation_execute z confirm=true po akceptacji użytkownika'];
    }

    // ---- wykonanie ----

    private static function load(array $c, string $id): array
    {
        $op = Store::db()->where('id', $id)->where('connection_id', $c['id'])->get(Store::table('operations'))->row_array();
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

    private static function mark(array &$op, string $status, array $extra = []): void
    {
        $op = array_merge($op, $extra, ['status' => $status, 'updated_at' => time()]);
        Store::db()->where('id', $op['id'])->update(Store::table('operations'), array_merge($extra, ['status' => $status, 'updated_at' => time()]));
    }

    private static function execute(array $c, string $level, string $id, string $key): array
    {
        if (!Store::lock('op:' . $id)) {
            throw new Failure('busy', 'Operacja jest właśnie wykonywana.', 409);
        }
        try {
            $op = self::load($c, $id);
            $keyHash = hash('sha256', $key);
            if ($op['status'] !== 'previewed') {
                if ($op['execution_key'] === $keyHash) {
                    return self::public($op); // to samo wykonanie: zwracamy zapisany wynik
                }
                throw new Failure('already_executed', 'Ta operacja została już wykonana albo zamknięta.', 409);
            }
            if ((int) $op['expires_at'] < time()) {
                self::mark($op, 'expired');
                throw new Failure('expired', 'Podgląd wygasł. Przygotuj nowy.', 409);
            }
            $plan = json_decode($op['plan'], true);
            $def = self::actions()[$plan['action']];
            $sid = (int) $c['staff_id'];
            if (!Levels::atLeast($level, $def['level'])) {
                throw new Failure('forbidden', _l('mcai_err_level'), 403);
            }
            if (isset($def['resource']) || isset($def['parent'])) {
                if (Resources::row($def['resource'] ?? $def['parent'], (int) $plan['target_id'], $sid) === null) {
                    throw new Failure('not_found', _l('mcai_err_not_found'), 404);
                }
            }
            self::authorize($plan['action'], $def, $sid, $plan['target_id'], $plan['change'], null);
            self::mark($op, 'executing', ['execution_key' => $keyHash]);
            $db = Store::db();
            $db->trans_begin();
            try {
                $result = $def['mode'] === 'update' ? self::applyUpdate($op, $def, $plan) : self::applyCreate($op, $def, $plan, $sid);
                if ($db->trans_status() === false) {
                    throw new \RuntimeException('db');
                }
                $db->trans_commit();
            } catch (\Throwable $e) {
                $db->trans_rollback();
                self::mark($op, $e instanceof Failure && $e->codeName === 'conflict' ? 'conflict' : 'failed');
                throw $e instanceof Failure ? $e : new Failure('failed', 'Zapis nie powiódł się; nic nie zostało zmienione.', 500);
            }
            self::mark($op, 'verified', $result);
            Connections::audit($c, 'operation.' . $plan['action'], 'verified', $op['id']);
            return self::public($op);
        } finally {
            Store::unlock('op:' . $id);
        }
    }

    private static function applyUpdate(array $op, array $def, array $plan): array
    {
        $target = (int) $plan['target_id'];
        $cols = self::columns($plan['action'], $plan['change']);
        $now = self::snapshot($def, $target, $cols, true);
        if ($now === null || self::hash($now) !== $op['before_hash']) {
            throw new Failure('conflict', 'Rekord zmienił się po podglądzie. Przygotuj nowy podgląd.', 409);
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

    private static function applyCreate(array $op, array $def, array $plan, int $sid): array
    {
        $ch = $plan['change'];
        $now = date('Y-m-d H:i:s');
        $p = db_prefix();
        $db = Store::db();
        switch ($plan['action']) {
            case 'task.create':
                $row = array_diff_key($ch, ['assignees' => 1]) + ['description' => '', 'priority' => 2, 'status' => 1, 'startdate' => date('Y-m-d'), 'dateadded' => $now, 'addedfrom' => $sid, 'is_public' => 0, 'billable' => 0, 'visible_to_client' => 0];
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
                $row = $ch + ['active' => 0, 'slug' => $slug, 'datecreated' => $now, 'staff_article' => 0, 'article_order' => 0];
                break;
            case 'customer.create':
                $row = $ch + ['datecreated' => $now, 'addedfrom' => $sid, 'active' => 1, 'country' => 0, 'show_primary_contact' => 0, 'registration_confirmed' => 1, 'default_currency' => 0, 'default_language' => ''];
                break;
            case 'contact.create':
                $primary = $db->where('userid', $ch['userid'])->count_all_results($p . 'contacts') === 0 ? 1 : 0;
                $row = $ch + ['phonenumber' => '', 'is_primary' => $primary, 'datecreated' => $now, 'active' => 1, 'email_verified_at' => $now];
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
        $cols = array_keys(array_diff_key($row, ['dateadded' => 1, 'datecreated' => 1]));
        $after = self::snapshot($def, $newId, $cols, true);
        if ($after === null) {
            throw new \RuntimeException('verify');
        }
        return ['new_id' => $newId, 'after_hash' => self::hash($after), 'before_state' => Store::json(['created_columns' => $cols])];
    }

    // ---- cofanie ----

    private static function rollback(array $c, string $level, string $id): array
    {
        if (!Store::lock('op:' . $id)) {
            throw new Failure('busy', 'Operacja jest właśnie przetwarzana.', 409);
        }
        try {
            $op = self::load($c, $id);
            if ($op['status'] !== 'verified') {
                throw new Failure('rollback_unavailable', 'Cofnąć można tylko wykonaną i sprawdzoną operację.', 409);
            }
            $plan = json_decode($op['plan'], true);
            $def = self::actions()[$plan['action']];
            if (!Levels::atLeast($level, $def['level'])) {
                throw new Failure('forbidden', _l('mcai_err_level'), 403);
            }
            $db = Store::db();
            $p = db_prefix();
            $db->trans_begin();
            try {
                if ($def['mode'] === 'update') {
                    $before = json_decode($op['before_state'], true);
                    $now = self::snapshot($def, (int) $plan['target_id'], array_keys($before), true);
                    if ($now === null || self::hash($now) !== $op['after_hash']) {
                        throw new Failure('conflict', 'Rekord zmienił się po tej operacji. Cofnięcie przywróciłoby cudze zmiany.', 409);
                    }
                    $db->where($def['pk'], (int) $plan['target_id'])->update($p . $def['table'], $before);
                    if (self::hash(self::snapshot($def, (int) $plan['target_id'], array_keys($before), true)) !== self::hash($before)) {
                        throw new \RuntimeException('verify');
                    }
                } else {
                    $newId = (int) $op['new_id'];
                    $cols = json_decode($op['before_state'], true)['created_columns'] ?? [];
                    $now = self::snapshot($def, $newId, $cols, true);
                    if ($now === null || self::hash($now) !== $op['after_hash'] || self::hasChildren($plan['action'], $newId)) {
                        throw new Failure('conflict', 'Utworzony rekord zmienił się albo ma już powiązane dane. Nie cofam.', 409);
                    }
                    if ($plan['action'] === 'task.create') {
                        $db->where('taskid', $newId)->delete($p . 'task_assigned');
                    }
                    $db->where($def['pk'], $newId)->delete($p . $def['table']);
                }
                if ($db->trans_status() === false) {
                    throw new \RuntimeException('db');
                }
                $db->trans_commit();
            } catch (\Throwable $e) {
                $db->trans_rollback();
                throw $e instanceof Failure ? $e : new Failure('rollback_failed', 'Cofnięcie nie powiodło się; stan bez zmian.', 500);
            }
            self::mark($op, 'rolled_back');
            Connections::audit($c, 'rollback.' . $plan['action'], 'rolled_back', $op['id']);
            return self::public($op);
        } finally {
            Store::unlock('op:' . $id);
        }
    }

    /** Rekord utworzony przez AI, do którego ktoś już coś dopiął, zostaje. */
    private static function hasChildren(string $action, int $id): bool
    {
        $p = db_prefix();
        $db = Store::db();
        $checks = match ($action) {
            'task.create' => [["{$p}task_comments", 'taskid'], ["{$p}task_checklist_items", 'taskid'], ["{$p}taskstimers", 'task_id']],
            'customer.create' => [["{$p}contacts", 'userid'], ["{$p}invoices", 'clientid'], ["{$p}estimates", 'clientid'], ["{$p}projects", 'clientid'], ["{$p}tickets", 'userid']],
            'lead.create' => [["{$p}notes", 'rel_id', "rel_type='lead'"]],
            'contact.create' => [["{$p}tickets", 'contactid']],
            default => [],
        };
        foreach ($checks as $ch) {
            $db->from($ch[0])->where($ch[1], $id);
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
