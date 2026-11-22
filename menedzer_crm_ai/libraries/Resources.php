<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Odczyt danych CRM z uprawnieniami Perfexa. Każde zapytanie dostaje warunek widoczności
 * wynikający z roli pracownika (widok wszystkich, widok własnych albo brak dostępu).
 */
final class Resources
{
    // Kolumny, które nigdy nie wychodzą do aplikacji AI (hasła, klucze, publiczne linki, podpisy).
    private const SECRET = '/(password|pass_key|verification_key|two_factor|^token$|^hash$|secret|ticketkey|stripe_id|^signature$|acceptance_ip|last_ip|email_integration_uid)/i';
    private const LIST_TEXT = 300;
    private const GET_TEXT = 20000;

    /** Definicje zasobów. own: warunek widoku własnych, :sid zamieniane na id pracownika (int). */
    public static function map(): array
    {
        $p = db_prefix();
        return [
            'customers' => ['table' => 'clients', 'pk' => 'userid', 'feature' => 'customers', 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['userid', 'company', 'vat', 'phonenumber', 'city', 'active', 'datecreated'], 'search' => ['company', 'vat', 'phonenumber', 'city'],
                'own' => "userid IN (SELECT customer_id FROM {$p}customer_admins WHERE staff_id=:sid)"],
            'contacts' => ['table' => 'contacts', 'pk' => 'id', 'feature' => 'customers', 'date' => 'datecreated', 'status' => 'active', 'customer' => 'userid',
                'summary' => ['id', 'userid', 'firstname', 'lastname', 'email', 'phonenumber', 'title', 'is_primary', 'active'], 'search' => ['firstname', 'lastname', 'email', 'phonenumber'],
                'own' => "userid IN (SELECT customer_id FROM {$p}customer_admins WHERE staff_id=:sid)"],
            'leads' => ['table' => 'leads', 'pk' => 'id', 'feature' => 'leads', 'date' => 'dateadded', 'status' => 'status', 'assigned' => 'assigned', 'always_own' => true,
                'summary' => ['id', 'name', 'company', 'email', 'phonenumber', 'status', 'source', 'assigned', 'lead_value', 'dateadded', 'lastcontact'], 'search' => ['name', 'company', 'email', 'phonenumber'],
                'own' => '(assigned=:sid OR addedfrom=:sid OR is_public=1)'],
            'tickets' => ['table' => 'tickets', 'pk' => 'ticketid', 'feature' => null, 'date' => 'date', 'status' => 'status', 'assigned' => 'assigned', 'customer' => 'userid',
                'summary' => ['ticketid', 'subject', 'userid', 'contactid', 'email', 'name', 'department', 'priority', 'status', 'assigned', 'date', 'lastreply'], 'search' => ['subject', 'email', 'name', 'message'],
                'own' => "(department IN (SELECT departmentid FROM {$p}staff_departments WHERE staffid=:sid) OR assigned=:sid)"],
            'tasks' => ['table' => 'tasks', 'pk' => 'id', 'feature' => 'tasks', 'date' => 'dateadded', 'status' => 'status', 'always_own' => true,
                'summary' => ['id', 'name', 'status', 'priority', 'startdate', 'duedate', 'datefinished', 'rel_type', 'rel_id', 'addedfrom'], 'search' => ['name', 'description'],
                'own' => "(is_public=1 OR addedfrom=:sid OR id IN (SELECT taskid FROM {$p}task_assigned WHERE staffid=:sid) OR id IN (SELECT taskid FROM {$p}task_followers WHERE staffid=:sid))"],
            'projects' => ['table' => 'projects', 'pk' => 'id', 'feature' => 'projects', 'date' => 'project_created', 'status' => 'status', 'customer' => 'clientid', 'always_own' => true,
                'summary' => ['id', 'name', 'clientid', 'status', 'start_date', 'deadline', 'progress'], 'search' => ['name', 'description'],
                'own' => "id IN (SELECT project_id FROM {$p}project_members WHERE staff_id=:sid)"],
            'invoices' => ['table' => 'invoices', 'pk' => 'id', 'feature' => 'invoices', 'date' => 'date', 'status' => 'status', 'customer' => 'clientid', 'assigned' => 'sale_agent',
                'summary' => ['id', 'number', 'prefix', 'clientid', 'date', 'duedate', 'total', 'currency', 'status', 'sale_agent', 'sent'], 'search' => ['number', 'clientnote', 'adminnote'],
                'own' => '(addedfrom=:sid OR sale_agent=:sid)'],
            'estimates' => ['table' => 'estimates', 'pk' => 'id', 'feature' => 'estimates', 'date' => 'date', 'status' => 'status', 'customer' => 'clientid', 'assigned' => 'sale_agent',
                'summary' => ['id', 'number', 'prefix', 'clientid', 'date', 'expirydate', 'total', 'currency', 'status', 'sale_agent', 'sent'], 'search' => ['number', 'reference_no', 'clientnote', 'adminnote'],
                'own' => '(addedfrom=:sid OR sale_agent=:sid)'],
            'proposals' => ['table' => 'proposals', 'pk' => 'id', 'feature' => 'proposals', 'date' => 'date', 'status' => 'status', 'assigned' => 'assigned',
                'summary' => ['id', 'subject', 'proposal_to', 'rel_type', 'rel_id', 'date', 'open_till', 'total', 'currency', 'status', 'assigned'], 'search' => ['subject', 'proposal_to', 'email'],
                'own' => '(addedfrom=:sid OR assigned=:sid)'],
            'payments' => ['table' => 'invoicepaymentrecords', 'pk' => 'id', 'feature' => 'payments', 'date' => 'date',
                'summary' => ['id', 'invoiceid', 'amount', 'paymentmode', 'date', 'transactionid'], 'search' => ['transactionid', 'note'],
                'own' => "invoiceid IN (SELECT id FROM {$p}invoices WHERE addedfrom=:sid OR sale_agent=:sid)"],
            'knowledge_base' => ['table' => 'knowledge_base', 'pk' => 'articleid', 'feature' => 'knowledge_base', 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['articleid', 'subject', 'articlegroup', 'slug', 'active', 'staff_article', 'datecreated'], 'search' => ['subject', 'description'], 'own' => null],
            'announcements' => ['table' => 'announcements', 'pk' => 'announcementid', 'feature' => null, 'date' => 'dateadded',
                'summary' => ['announcementid', 'name', 'showtostaff', 'showtousers', 'dateadded'], 'search' => ['name', 'message'], 'own' => 'showtostaff=1'],
            'events' => ['table' => 'events', 'pk' => 'eventid', 'feature' => null, 'date' => 'start',
                'summary' => ['eventid', 'title', 'start', 'end', 'public', 'userid'], 'search' => ['title', 'description'], 'own' => '(userid=:sid OR public=1)'],
            'staff' => ['table' => 'staff', 'pk' => 'staffid', 'feature' => null, 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['staffid', 'firstname', 'lastname', 'email', 'phonenumber', 'active', 'admin'], 'search' => ['firstname', 'lastname', 'email'],
                'columns' => ['staffid', 'firstname', 'lastname', 'email', 'phonenumber', 'active', 'admin', 'role', 'datecreated', 'last_login'], 'own' => null],
        ];
    }

    /** 'all', 'own' albo null (brak dostępu) dla pracownika. */
    public static function access(string $resource, int $staffId): ?string
    {
        $def = self::map()[$resource] ?? null;
        if (!$def) {
            return null;
        }
        if (is_admin($staffId)) {
            return 'all';
        }
        if ($resource === 'tickets') {
            return get_option('staff_access_only_assigned_departments') == '1' ? 'own' : 'all';
        }
        if (in_array($resource, ['announcements', 'events'], true)) {
            return 'own';
        }
        if ($resource === 'staff') {
            return 'all';
        }
        if (staff_can('view', $def['feature'], $staffId)) {
            return 'all';
        }
        if (($def['own'] ?? null) && (staff_can('view_own', $def['feature'], $staffId) || !empty($def['always_own']))) {
            return 'own';
        }
        return null;
    }

    private static function visibility(array $def, string $access, int $staffId): ?string
    {
        return $access === 'own' ? str_replace(':sid', (string) $staffId, $def['own']) : null;
    }

    private static function fields(string $table): array
    {
        static $cache = [];
        return $cache[$table] ??= Store::db()->list_fields(db_prefix() . $table);
    }

    private static function clean(array $row, int $max): array
    {
        foreach ($row as $k => $v) {
            if (preg_match(self::SECRET, (string) $k)) {
                unset($row[$k]);
            } elseif (is_string($v) && mb_strlen($v) > $max) {
                $row[$k] = mb_substr($v, 0, $max) . '…';
            }
        }
        return $row;
    }

    private static function def(string $resource): array
    {
        $def = self::map()[$resource] ?? null;
        if (!$def) {
            throw new Failure('unknown_resource', _l('mcai_err_resource'), 404);
        }
        return $def;
    }

    private static function require(string $resource, int $staffId): array
    {
        $def = self::def($resource);
        $access = self::access($resource, $staffId);
        if ($access === null) {
            throw new Failure('forbidden', _l('mcai_err_forbidden'), 403);
        }
        return [$def, $access];
    }

    public static function list(string $resource, int $staffId, array $a): array
    {
        [$def, $access] = self::require($resource, $staffId);
        $db = Store::db();
        $table = db_prefix() . $def['table'];
        $have = self::fields($def['table']);
        $cols = array_values(array_intersect($def['summary'], $have));
        $db->select(implode(',', array_map(fn ($c) => "`$c`", $cols)))->from($table);
        if ($w = self::visibility($def, $access, $staffId)) {
            $db->where($w, null, false);
        }
        $search = trim((string) ($a['search'] ?? ''));
        if ($search !== '') {
            $db->group_start();
            foreach (array_intersect($def['search'], $have) as $c) {
                $db->or_like($c, $search);
            }
            $db->group_end();
        }
        if (isset($a['status']) && isset($def['status'])) {
            $db->where($def['status'], $a['status']);
        }
        if (isset($a['customer_id'])) {
            if ($resource === 'tasks') {
                $db->where('rel_type', 'customer')->where('rel_id', (int) $a['customer_id']);
            } elseif ($resource === 'proposals') {
                $db->where('rel_type', 'customer')->where('rel_id', (int) $a['customer_id']);
            } elseif (isset($def['customer'])) {
                $db->where($def['customer'], (int) $a['customer_id']);
            } else {
                throw new Failure('invalid_filter', _l('mcai_err_filter'));
            }
        }
        if (isset($a['assigned_to'])) {
            $sid = (int) $a['assigned_to'];
            if ($resource === 'tasks') {
                $db->where('id IN (SELECT taskid FROM ' . db_prefix() . "task_assigned WHERE staffid=$sid)", null, false);
            } elseif (isset($def['assigned'])) {
                $db->where($def['assigned'], $sid);
            } else {
                throw new Failure('invalid_filter', _l('mcai_err_filter'));
            }
        }
        if (isset($a['related_type'], $a['related_id']) && in_array('rel_type', $have, true)) {
            $db->where('rel_type', (string) $a['related_type'])->where('rel_id', (int) $a['related_id']);
        }
        foreach (['since' => '>=', 'until' => '<='] as $k => $op) {
            if (isset($a[$k])) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', (string) $a[$k])) {
                    throw new Failure('invalid_filter', _l('mcai_err_date'));
                }
                $db->where($def['date'] . " $op", $a[$k]);
            }
        }
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
        $offset = max(0, (int) ($a['offset'] ?? 0));
        $rows = $db->order_by($def['pk'], ($a['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC')->limit($limit + 1, $offset)->get()->result_array();
        $more = count($rows) > $limit;
        return ['resource' => $resource, 'access' => $access, 'items' => array_map(fn ($r) => self::clean($r, self::LIST_TEXT), array_slice($rows, 0, $limit)), 'offset' => $offset, 'has_more' => $more];
    }

    /** Pełny rekord bez sekretów, razem z powiązanymi danymi. */
    public static function get(string $resource, int $id, int $staffId): array
    {
        [$def, $access] = self::require($resource, $staffId);
        $row = self::row($resource, $id, $staffId, $def, $access);
        if ($row === null) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        return ['resource' => $resource, 'id' => $id, 'item' => self::clean($row, self::GET_TEXT), 'related' => self::related($resource, $id, $row, $staffId)];
    }

    /** Surowy rekord (do kopii przed zmianą). null, gdy brak lub niewidoczny. */
    public static function row(string $resource, int $id, int $staffId, ?array $def = null, ?string $access = null): ?array
    {
        if ($def === null) {
            [$def, $access] = self::require($resource, $staffId);
        }
        $db = Store::db();
        $select = isset($def['columns']) ? implode(',', array_map(fn ($c) => "`$c`", array_intersect($def['columns'], self::fields($def['table'])))) : '*';
        $db->select($select)->from(db_prefix() . $def['table'])->where($def['pk'], $id);
        if ($w = self::visibility($def, $access, $staffId)) {
            $db->where($w, null, false);
        }
        return $db->get()->row_array() ?: null;
    }

    private static function rows(string $sql, array $bind = []): array
    {
        return array_map(fn ($r) => self::clean($r, self::GET_TEXT), Store::db()->query($sql, $bind)->result_array());
    }

    private static function related(string $resource, int $id, array $row, int $staffId): array
    {
        $p = db_prefix();
        $staffName = "CONCAT(s.firstname,' ',s.lastname)";
        switch ($resource) {
            case 'customers':
                return [
                    'contacts' => self::rows("SELECT id,firstname,lastname,email,phonenumber,title,is_primary,active FROM {$p}contacts WHERE userid=? ORDER BY is_primary DESC,id", [$id]),
                    'notes' => self::rows("SELECT n.id,n.description,n.dateadded,$staffName AS added_by FROM {$p}notes n LEFT JOIN {$p}staff s ON s.staffid=n.addedfrom WHERE n.rel_type='customer' AND n.rel_id=? ORDER BY n.id DESC LIMIT 50", [$id]),
                    'admins' => self::rows("SELECT s.staffid,$staffName AS name FROM {$p}customer_admins a JOIN {$p}staff s ON s.staffid=a.staff_id WHERE a.customer_id=?", [$id]),
                ];
            case 'leads':
                return ['notes' => self::rows("SELECT n.id,n.description,n.dateadded,$staffName AS added_by FROM {$p}notes n LEFT JOIN {$p}staff s ON s.staffid=n.addedfrom WHERE n.rel_type='lead' AND n.rel_id=? ORDER BY n.id DESC LIMIT 50", [$id])];
            case 'tickets':
                return [
                    'replies' => self::rows("SELECT r.id,r.date,r.message,r.admin AS staff_id,r.contactid,r.name,r.email,$staffName AS staff_name FROM {$p}ticket_replies r LEFT JOIN {$p}staff s ON s.staffid=r.admin WHERE r.ticketid=? ORDER BY r.id", [$id]),
                    'notes' => self::rows("SELECT n.id,n.description,n.dateadded,$staffName AS added_by FROM {$p}notes n LEFT JOIN {$p}staff s ON s.staffid=n.addedfrom WHERE n.rel_type='ticket' AND n.rel_id=? ORDER BY n.id DESC LIMIT 50", [$id]),
                ];
            case 'tasks':
                return [
                    'assignees' => self::rows("SELECT s.staffid,$staffName AS name FROM {$p}task_assigned a JOIN {$p}staff s ON s.staffid=a.staffid WHERE a.taskid=?", [$id]),
                    'followers' => self::rows("SELECT s.staffid,$staffName AS name FROM {$p}task_followers f JOIN {$p}staff s ON s.staffid=f.staffid WHERE f.taskid=?", [$id]),
                    'comments' => self::rows("SELECT c.id,c.content,c.dateadded,c.staffid,c.contact_id,$staffName AS staff_name FROM {$p}task_comments c LEFT JOIN {$p}staff s ON s.staffid=c.staffid WHERE c.taskid=? ORDER BY c.id", [$id]),
                    'checklist' => self::rows("SELECT id,description,finished FROM {$p}task_checklist_items WHERE taskid=? ORDER BY list_order", [$id]),
                ];
            case 'projects':
                return [
                    'members' => self::rows("SELECT s.staffid,$staffName AS name FROM {$p}project_members m JOIN {$p}staff s ON s.staffid=m.staff_id WHERE m.project_id=?", [$id]),
                    'tasks' => self::rows("SELECT id,name,status,duedate FROM {$p}tasks WHERE rel_type='project' AND rel_id=? ORDER BY id DESC LIMIT 100", [$id]),
                ];
            case 'invoices':
                return [
                    'items' => self::rows("SELECT id,description,long_description,qty,rate,unit,item_order FROM {$p}itemable WHERE rel_type='invoice' AND rel_id=? ORDER BY item_order", [$id]),
                    'payments' => self::access('payments', $staffId) ? self::rows("SELECT id,amount,paymentmode,date,transactionid FROM {$p}invoicepaymentrecords WHERE invoiceid=? ORDER BY id", [$id]) : [],
                ];
            case 'estimates':
            case 'proposals':
                $type = $resource === 'estimates' ? 'estimate' : 'proposal';
                return ['items' => self::rows("SELECT id,description,long_description,qty,rate,unit,item_order FROM {$p}itemable WHERE rel_type=? AND rel_id=? ORDER BY item_order", [$type, $id])];
            case 'knowledge_base':
                return ['group' => self::rows("SELECT groupid,name FROM {$p}knowledge_base_groups WHERE groupid=?", [(int) $row['articlegroup']])];
        }
        return [];
    }

    /** Wyszukiwanie w wielu zasobach naraz, po 5 wyników na zasób. */
    public static function search(string $query, int $staffId, ?array $only = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new Failure('invalid_query', _l('mcai_err_query'));
        }
        $out = [];
        foreach (array_keys(self::map()) as $r) {
            if (($only && !in_array($r, $only, true)) || $r === 'staff' || self::access($r, $staffId) === null) {
                continue;
            }
            $found = self::list($r, $staffId, ['search' => $query, 'limit' => 5]);
            if ($found['items']) {
                $out[$r] = $found['items'];
            }
        }
        return ['query' => $query, 'results' => $out];
    }

    /** Spis zasobów, poziom dostępu, liczby rekordów i słowniki statusów. */
    public static function inventory(int $staffId): array
    {
        $p = db_prefix();
        $resources = [];
        foreach (self::map() as $name => $def) {
            $access = self::access($name, $staffId);
            $count = null;
            if ($access !== null) {
                $db = Store::db()->from($p . $def['table']);
                if ($w = self::visibility($def, $access, $staffId)) {
                    $db->where($w, null, false);
                }
                $count = $db->count_all_results();
            }
            $resources[$name] = ['access' => $access ?? 'none', 'count' => $count];
        }
        $db = Store::db();
        $dict = fn (string $sql) => $db->query($sql)->result_array();
        return [
            'resources' => $resources,
            'lookups' => [
                'lead_statuses' => $dict("SELECT id,name FROM {$p}leads_status ORDER BY statusorder"),
                'lead_sources' => $dict("SELECT id,name FROM {$p}leads_sources ORDER BY name"),
                'ticket_statuses' => $dict("SELECT ticketstatusid AS id,name FROM {$p}tickets_status ORDER BY statusorder"),
                'ticket_priorities' => $dict("SELECT priorityid AS id,name FROM {$p}tickets_priorities"),
                'departments' => $dict("SELECT departmentid AS id,name FROM {$p}departments"),
                'kb_groups' => $dict("SELECT groupid AS id,name FROM {$p}knowledge_base_groups ORDER BY group_order"),
                'task_statuses' => [1 => _l('task_status_1'), 2 => _l('task_status_2'), 3 => _l('task_status_3'), 4 => _l('task_status_4'), 5 => _l('task_status_5')],
                'task_priorities' => [1 => _l('task_priority_low'), 2 => _l('task_priority_medium'), 3 => _l('task_priority_high'), 4 => _l('task_priority_urgent')],
                'project_statuses' => [1 => _l('project_status_1'), 2 => _l('project_status_2'), 3 => _l('project_status_3'), 4 => _l('project_status_4'), 5 => _l('project_status_5')],
                'invoice_statuses' => [1 => _l('invoice_status_unpaid'), 2 => _l('invoice_status_paid'), 3 => _l('invoice_status_not_paid_completely'), 4 => _l('invoice_status_overdue'), 5 => _l('invoice_status_cancelled'), 6 => _l('invoice_status_draft')],
                'estimate_statuses' => [1 => _l('estimate_status_draft'), 2 => _l('estimate_status_sent'), 3 => _l('estimate_status_declined'), 4 => _l('estimate_status_accepted'), 5 => _l('estimate_status_expired')],
                'proposal_statuses' => [1 => _l('proposal_status_open'), 2 => _l('proposal_status_declined'), 3 => _l('proposal_status_accepted'), 4 => _l('proposal_status_sent'), 5 => _l('proposal_status_revised'), 6 => _l('proposal_status_draft')],
            ],
        ];
    }
}
