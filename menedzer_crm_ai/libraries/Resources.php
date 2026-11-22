<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Odczyt danych CRM z uprawnieniami Perfexa. Widoczność odwzorowuje reguły Perfexa 3.x
 * (widok wszystkich, widok własnych, przypisanie), a do AI trafiają tylko kolumny z jawnej listy.
 */
final class Resources
{
    // Druga linia obrony: nazwy, które nie wyjdą nawet wtedy, gdy trafią na listę kolumn.
    private const SECRET = '/(password|pass_key|verification_key|two_factor|token|^hash$|secret|ticketkey|stripe_id|signature|acceptance_ip|last_ip|api_key|short_link|email_integration_uid)/i';
    private const LIST_TEXT = 300;
    private const GET_TEXT = 20000;

    public static function map(): array
    {
        $addr = ['billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country', 'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country'];
        $money = ['currency', 'subtotal', 'total_tax', 'total', 'adjustment', 'discount_percent', 'discount_total', 'discount_type'];
        return [
            'customers' => ['table' => 'clients', 'pk' => 'userid', 'feature' => 'customers', 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['userid', 'company', 'vat', 'phonenumber', 'city', 'active', 'datecreated'], 'search' => ['company', 'vat', 'phonenumber', 'city'],
                'columns' => array_merge(['userid', 'company', 'vat', 'phonenumber', 'country', 'city', 'zip', 'state', 'address', 'website', 'datecreated', 'active', 'leadid', 'default_language', 'default_currency', 'addedfrom'], $addr)],
            'contacts' => ['table' => 'contacts', 'pk' => 'id', 'feature' => 'customers', 'date' => 'datecreated', 'status' => 'active', 'customer' => 'userid',
                'summary' => ['id', 'userid', 'firstname', 'lastname', 'email', 'phonenumber', 'title', 'is_primary', 'active'], 'search' => ['firstname', 'lastname', 'email', 'phonenumber'],
                'columns' => ['id', 'userid', 'is_primary', 'firstname', 'lastname', 'email', 'phonenumber', 'title', 'datecreated', 'last_login', 'active', 'invoice_emails', 'estimate_emails', 'credit_note_emails', 'contract_emails', 'task_emails', 'project_emails', 'ticket_emails']],
            'leads' => ['table' => 'leads', 'pk' => 'id', 'feature' => 'leads', 'date' => 'dateadded', 'status' => 'status', 'assigned' => 'assigned',
                'summary' => ['id', 'name', 'company', 'email', 'phonenumber', 'status', 'source', 'assigned', 'lead_value', 'dateadded', 'lastcontact'], 'search' => ['name', 'company', 'email', 'phonenumber'],
                'columns' => ['id', 'name', 'title', 'company', 'description', 'country', 'zip', 'city', 'state', 'address', 'assigned', 'dateadded', 'status', 'source', 'lastcontact', 'dateassigned', 'last_status_change', 'addedfrom', 'email', 'website', 'phonenumber', 'date_converted', 'lost', 'junk', 'is_public', 'default_language', 'client_id', 'lead_value']],
            'tickets' => ['table' => 'tickets', 'pk' => 'ticketid', 'feature' => null, 'date' => 'date', 'status' => 'status', 'assigned' => 'assigned', 'customer' => 'userid',
                'summary' => ['ticketid', 'subject', 'userid', 'contactid', 'email', 'name', 'department', 'priority', 'status', 'assigned', 'date', 'lastreply'], 'search' => ['subject', 'email', 'name', 'message'],
                'columns' => ['ticketid', 'userid', 'contactid', 'merged_ticket_id', 'email', 'name', 'department', 'priority', 'status', 'service', 'subject', 'message', 'admin', 'date', 'project_id', 'lastreply', 'clientread', 'adminread', 'assigned', 'cc']],
            'tasks' => ['table' => 'tasks', 'pk' => 'id', 'feature' => 'tasks', 'date' => 'dateadded', 'status' => 'status',
                'summary' => ['id', 'name', 'status', 'priority', 'startdate', 'duedate', 'datefinished', 'rel_type', 'rel_id', 'addedfrom', 'visible_to_client'], 'search' => ['name', 'description'],
                'columns' => ['id', 'name', 'description', 'priority', 'dateadded', 'startdate', 'duedate', 'datefinished', 'addedfrom', 'status', 'recurring', 'rel_id', 'rel_type', 'is_public', 'billable', 'billed', 'invoice_id', 'hourly_rate', 'milestone', 'visible_to_client']],
            'projects' => ['table' => 'projects', 'pk' => 'id', 'feature' => 'projects', 'date' => 'project_created', 'status' => 'status', 'customer' => 'clientid',
                'summary' => ['id', 'name', 'clientid', 'status', 'start_date', 'deadline', 'progress'], 'search' => ['name', 'description'],
                'columns' => ['id', 'name', 'description', 'status', 'clientid', 'billing_type', 'start_date', 'deadline', 'project_created', 'date_finished', 'progress', 'progress_from_tasks', 'project_cost', 'project_rate_per_hour', 'estimated_hours', 'addedfrom']],
            'invoices' => ['table' => 'invoices', 'pk' => 'id', 'feature' => 'invoices', 'date' => 'date', 'status' => 'status', 'customer' => 'clientid', 'assigned' => 'sale_agent',
                'summary' => ['id', 'number', 'prefix', 'clientid', 'date', 'duedate', 'total', 'currency', 'status', 'sale_agent', 'sent'], 'search' => ['number', 'clientnote', 'adminnote'],
                'columns' => array_merge(['id', 'sent', 'datesend', 'clientid', 'number', 'prefix', 'formatted_number', 'datecreated', 'date', 'duedate', 'addedfrom', 'status', 'clientnote', 'adminnote', 'recurring', 'terms', 'sale_agent', 'project_id'], $money, $addr)],
            'estimates' => ['table' => 'estimates', 'pk' => 'id', 'feature' => 'estimates', 'date' => 'date', 'status' => 'status', 'customer' => 'clientid', 'assigned' => 'sale_agent',
                'summary' => ['id', 'number', 'prefix', 'clientid', 'date', 'expirydate', 'total', 'currency', 'status', 'sale_agent', 'sent'], 'search' => ['number', 'reference_no', 'clientnote', 'adminnote'],
                'columns' => array_merge(['id', 'sent', 'datesend', 'clientid', 'project_id', 'number', 'prefix', 'formatted_number', 'datecreated', 'date', 'expirydate', 'addedfrom', 'status', 'clientnote', 'adminnote', 'invoiceid', 'invoiced_date', 'terms', 'reference_no', 'sale_agent', 'acceptance_firstname', 'acceptance_lastname', 'acceptance_email', 'acceptance_date'], $money, $addr)],
            'proposals' => ['table' => 'proposals', 'pk' => 'id', 'feature' => 'proposals', 'date' => 'date', 'status' => 'status', 'assigned' => 'assigned',
                'summary' => ['id', 'subject', 'proposal_to', 'rel_type', 'rel_id', 'date', 'open_till', 'total', 'currency', 'status', 'assigned'], 'search' => ['subject', 'proposal_to', 'email'],
                'columns' => array_merge(['id', 'subject', 'content', 'addedfrom', 'datecreated', 'open_till', 'date', 'rel_id', 'rel_type', 'assigned', 'proposal_to', 'project_id', 'country', 'zip', 'state', 'city', 'address', 'email', 'phone', 'status', 'estimate_id', 'invoice_id', 'date_converted', 'acceptance_firstname', 'acceptance_lastname', 'acceptance_email', 'acceptance_date'], $money)],
            'payments' => ['table' => 'invoicepaymentrecords', 'pk' => 'id', 'feature' => 'payments', 'date' => 'date',
                'summary' => ['id', 'invoiceid', 'amount', 'paymentmode', 'date', 'transactionid'], 'search' => ['transactionid', 'note'],
                'columns' => ['id', 'invoiceid', 'amount', 'paymentmode', 'paymentmethod', 'date', 'daterecorded', 'note', 'transactionid']],
            'knowledge_base' => ['table' => 'knowledge_base', 'pk' => 'articleid', 'feature' => 'knowledge_base', 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['articleid', 'subject', 'articlegroup', 'slug', 'active', 'staff_article', 'datecreated'], 'search' => ['subject', 'description'],
                'columns' => ['articleid', 'articlegroup', 'subject', 'description', 'slug', 'active', 'datecreated', 'article_order', 'staff_article']],
            'announcements' => ['table' => 'announcements', 'pk' => 'announcementid', 'feature' => null, 'date' => 'dateadded',
                'summary' => ['announcementid', 'name', 'showtostaff', 'showtousers', 'dateadded'], 'search' => ['name', 'message'],
                'columns' => ['announcementid', 'name', 'message', 'showtousers', 'showtostaff', 'showname', 'dateadded', 'userid']],
            'events' => ['table' => 'events', 'pk' => 'eventid', 'feature' => null, 'date' => 'start',
                'summary' => ['eventid', 'title', 'start', 'end', 'public', 'userid'], 'search' => ['title', 'description'],
                'columns' => ['eventid', 'title', 'description', 'userid', 'start', 'end', 'public', 'color']],
            'staff' => ['table' => 'staff', 'pk' => 'staffid', 'feature' => 'staff', 'date' => 'datecreated', 'status' => 'active',
                'summary' => ['staffid', 'firstname', 'lastname', 'email', 'phonenumber', 'active', 'admin'], 'search' => ['firstname', 'lastname', 'email'],
                'columns' => ['staffid', 'firstname', 'lastname', 'email', 'phonenumber', 'active', 'admin', 'role', 'datecreated', 'last_login'],
                'limited' => ['staffid', 'firstname', 'lastname', 'active']],
        ];
    }

    /**
     * Zakres widoczności: null (brak dostępu), ['all'], ['own', warunek SQL] albo ['limited'] (tylko imiona pracowników).
     * Warunki odwzorowują get_*_where_sql_for_staff() i listy w kontrolerach Perfexa.
     */
    public static function scope(string $resource, int $sid): ?array
    {
        $def = self::map()[$resource] ?? null;
        if (!$def) {
            return null;
        }
        if (is_admin($sid)) {
            return ['all'];
        }
        $p = db_prefix();
        $can = fn ($cap, $feature = null) => staff_can($cap, $feature ?? $def['feature'], $sid);
        switch ($resource) {
            case 'customers':
            case 'contacts':
                if ($can('view', 'customers')) {
                    return ['all'];
                }
                // Perfex: bez prawa widoku klient jest widoczny dla swoich opiekunów (customer_admins).
                return ['own', "userid IN (SELECT customer_id FROM {$p}customer_admins WHERE staff_id=$sid)"];
            case 'leads':
                return $can('view') ? ['all'] : ['own', "(assigned=$sid OR addedfrom=$sid OR is_public=1)"];
            case 'tickets':
                // Jak Tickets::ticket(): przy dostępie według działów przypisanie nie otwiera zgłoszenia z innego działu.
                return get_option('staff_access_only_assigned_departments') == '1'
                    ? ['own', "department IN (SELECT departmentid FROM {$p}staff_departments WHERE staffid=$sid)"] : ['all'];
            case 'tasks':
                return $can('view') ? ['all'] : ['own', self::taskWhere($sid)];
            case 'projects':
                return $can('view') ? ['all'] : ['own', "id IN (SELECT project_id FROM {$p}project_members WHERE staff_id=$sid)"];
            case 'invoices':
            case 'estimates':
            case 'proposals':
                if ($can('view')) {
                    return ['all'];
                }
                $where = self::salesWhere($resource, $sid);
                return $where === null ? null : ['own', $where];
            case 'payments':
                if ($can('view')) {
                    return ['all'];
                }
                // Jak views/admin/tables/payments.php: pełny widok faktur nie daje pełnego widoku płatności.
                $where = "invoiceid IN (SELECT id FROM {$p}invoices WHERE addedfrom=$sid AND addedfrom IN (SELECT staff_id FROM {$p}staff_permissions WHERE feature='invoices' AND capability='view_own'))";
                if (get_option('allow_staff_view_invoices_assigned') == 1) {
                    $where .= " OR invoiceid IN (SELECT id FROM {$p}invoices WHERE sale_agent=$sid)";
                }
                return ['own', "($where)"];
            case 'knowledge_base':
                return $can('view') ? ['all'] : null;
            case 'announcements':
                return ['own', 'showtostaff=1'];
            case 'events':
                return ['own', "(userid=$sid OR public=1)"];
            case 'staff':
                // Bez prawa do personelu: tylko aktywne osoby, imię i nazwisko (do przypisań).
                return $can('view') ? ['all'] : ['limited', 'active=1'];
        }
        return null;
    }

    /** Jak get_tasks_where_string() w Perfexie (tasks_helper.php). */
    public static function taskWhere(int $sid): string
    {
        $p = db_prefix();
        $w = "(id IN (SELECT taskid FROM {$p}task_assigned WHERE staffid=$sid) OR id IN (SELECT taskid FROM {$p}task_followers WHERE staffid=$sid) OR (addedfrom=$sid AND is_added_from_contact=0)";
        if (get_option('show_all_tasks_for_project_member') == 1) {
            $w .= " OR (rel_type='project' AND rel_id IN (SELECT project_id FROM {$p}project_members WHERE staff_id=$sid))";
        }
        return $w . ' OR is_public=1)';
    }

    /** Faktury, wyceny, propozycje: jak get_invoices_where_sql_for_staff() itd. w Perfexie, z warunkiem praw w samym SQL. */
    private static function salesWhere(string $resource, int $sid): ?string
    {
        $p = db_prefix();
        $agent = $resource === 'proposals' ? 'assigned' : 'sale_agent';
        $assignedAllowed = get_option('allow_staff_view_' . $resource . '_assigned') == 1;
        if (staff_can('view_own', $resource, $sid)) {
            return "((addedfrom=$sid AND addedfrom IN (SELECT staff_id FROM {$p}staff_permissions WHERE feature='$resource' AND capability='view_own'))" . ($assignedAllowed ? " OR $agent=$sid" : '') . ')';
        }
        return $assignedAllowed ? "$agent=$sid" : null;
    }

    public static function access(string $resource, int $sid): ?string
    {
        $s = self::scope($resource, $sid);
        return $s[0] ?? null;
    }

    private static function fields(string $table): array
    {
        static $cache = [];
        return $cache[$table] ??= Store::db()->list_fields(db_prefix() . $table);
    }

    private static function cols(array $def, array $wanted): array
    {
        return array_values(array_filter(array_intersect($wanted, self::fields($def['table'])), fn ($c) => !preg_match(self::SECRET, $c)));
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

    private static function require(string $resource, int $sid): array
    {
        $def = self::map()[$resource] ?? null;
        if (!$def) {
            throw new Failure('unknown_resource', _l('mcai_err_resource'), 404);
        }
        $scope = self::scope($resource, $sid);
        if ($scope === null) {
            throw new Failure('forbidden', _l('mcai_err_forbidden'), 403);
        }
        return [$def, $scope];
    }

    private static function applyScope(\CI_DB_query_builder $db, array $scope): void
    {
        if (isset($scope[1])) {
            $db->where($scope[1], null, false);
        }
    }

    public static function list(string $resource, int $sid, array $a): array
    {
        [$def, $scope] = self::require($resource, $sid);
        $limited = $scope[0] === 'limited';
        $db = Store::db();
        $have = self::fields($def['table']);
        $cols = self::cols($def, $limited ? $def['limited'] : $def['summary']);
        $db->select(implode(',', array_map(fn ($c) => "`$c`", $cols)))->from(db_prefix() . $def['table']);
        self::applyScope($db, $scope);
        $search = trim((string) ($a['search'] ?? ''));
        if ($search !== '') {
            $db->group_start();
            foreach (array_intersect($limited ? ['firstname', 'lastname'] : $def['search'], $have) as $c) {
                $db->or_like($c, $search);
            }
            $db->group_end();
        }
        if (isset($a['status']) && isset($def['status'])) {
            $db->where($def['status'], $a['status']);
        }
        if (isset($a['customer_id'])) {
            if (in_array($resource, ['tasks', 'proposals'], true)) {
                $db->where('rel_type', 'customer')->where('rel_id', (int) $a['customer_id']);
            } elseif (isset($def['customer'])) {
                $db->where($def['customer'], (int) $a['customer_id']);
            } else {
                throw new Failure('invalid_filter', _l('mcai_err_filter'));
            }
        }
        if (isset($a['assigned_to'])) {
            $assignee = (int) $a['assigned_to'];
            if ($resource === 'tasks') {
                $db->where('id IN (SELECT taskid FROM ' . db_prefix() . "task_assigned WHERE staffid=$assignee)", null, false);
            } elseif (isset($def['assigned'])) {
                $db->where($def['assigned'], $assignee);
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
        return ['resource' => $resource, 'access' => $scope[0], 'items' => array_map(fn ($r) => self::clean($r, self::LIST_TEXT), array_slice($rows, 0, $limit)), 'offset' => $offset, 'has_more' => count($rows) > $limit];
    }

    public static function get(string $resource, int $id, int $sid): array
    {
        [$def, $scope] = self::require($resource, $sid);
        $row = self::fetch($def, $scope, $id);
        if ($row === null) {
            throw new Failure('not_found', _l('mcai_err_not_found'), 404);
        }
        return ['resource' => $resource, 'id' => $id, 'item' => self::clean($row, self::GET_TEXT), 'related' => $scope[0] === 'limited' ? [] : self::related($resource, $id, $row, $sid)];
    }

    /** Rekord widoczny dla pracownika (kolumny z listy) albo null. */
    public static function row(string $resource, int $id, int $sid): ?array
    {
        [$def, $scope] = self::require($resource, $sid);
        return self::fetch($def, $scope, $id);
    }

    private static function fetch(array $def, array $scope, int $id): ?array
    {
        $db = Store::db();
        $cols = self::cols($def, $scope[0] === 'limited' ? $def['limited'] : $def['columns']);
        $db->select(implode(',', array_map(fn ($c) => "`$c`", $cols)))->from(db_prefix() . $def['table'])->where($def['pk'], $id);
        self::applyScope($db, $scope);
        return $db->get()->row_array() ?: null;
    }

    private static function rows(string $sql, array $bind = []): array
    {
        return array_map(fn ($r) => self::clean($r, self::GET_TEXT), Store::db()->query($sql, $bind)->result_array());
    }

    private static function related(string $resource, int $id, array $row, int $sid): array
    {
        $p = db_prefix();
        $name = "CONCAT(s.firstname,' ',s.lastname)";
        $notes = fn (string $type) => self::rows("SELECT n.id,n.description,n.dateadded,$name AS added_by FROM {$p}notes n LEFT JOIN {$p}staff s ON s.staffid=n.addedfrom WHERE n.rel_type=? AND n.rel_id=? ORDER BY n.id DESC LIMIT 50", [$type, $id]);
        $items = fn (string $type) => self::rows("SELECT id,description,long_description,qty,rate,unit,item_order FROM {$p}itemable WHERE rel_type=? AND rel_id=? ORDER BY item_order", [$type, $id]);
        switch ($resource) {
            case 'customers':
                return [
                    'contacts' => self::rows("SELECT id,firstname,lastname,email,phonenumber,title,is_primary,active FROM {$p}contacts WHERE userid=? ORDER BY is_primary DESC,id", [$id]),
                    'notes' => $notes('customer'),
                    'admins' => self::rows("SELECT s.staffid,$name AS name FROM {$p}customer_admins a JOIN {$p}staff s ON s.staffid=a.staff_id WHERE a.customer_id=?", [$id]),
                ];
            case 'leads':
                return ['notes' => $notes('lead')];
            case 'tickets':
                return [
                    'replies' => self::rows("SELECT r.id,r.date,r.message,r.admin AS staff_id,r.contactid,r.name,r.email,$name AS staff_name FROM {$p}ticket_replies r LEFT JOIN {$p}staff s ON s.staffid=r.admin WHERE r.ticketid=? ORDER BY r.id", [$id]),
                    'notes' => $notes('ticket'),
                ];
            case 'tasks':
                return [
                    'assignees' => self::rows("SELECT s.staffid,$name AS name FROM {$p}task_assigned a JOIN {$p}staff s ON s.staffid=a.staffid WHERE a.taskid=?", [$id]),
                    'followers' => self::rows("SELECT s.staffid,$name AS name FROM {$p}task_followers f JOIN {$p}staff s ON s.staffid=f.staffid WHERE f.taskid=?", [$id]),
                    'comments' => self::rows("SELECT c.id,c.content,c.dateadded,c.staffid,c.contact_id,$name AS staff_name FROM {$p}task_comments c LEFT JOIN {$p}staff s ON s.staffid=c.staffid WHERE c.taskid=? ORDER BY c.id", [$id]),
                    'checklist' => self::rows("SELECT id,description,finished FROM {$p}task_checklist_items WHERE taskid=? ORDER BY list_order", [$id]),
                ];
            case 'projects':
                $tasks = self::scope('tasks', $sid);
                $where = $tasks && $tasks[0] === 'own' ? ' AND ' . $tasks[1] : '';
                return [
                    'members' => self::rows("SELECT s.staffid,$name AS name FROM {$p}project_members m JOIN {$p}staff s ON s.staffid=m.staff_id WHERE m.project_id=?", [$id]),
                    'tasks' => $tasks ? self::rows("SELECT id,name,status,duedate FROM {$p}tasks WHERE rel_type='project' AND rel_id=?$where ORDER BY id DESC LIMIT 100", [$id]) : [],
                ];
            case 'invoices':
                $pay = self::scope('payments', $sid);
                $where = $pay && $pay[0] === 'own' ? ' AND ' . $pay[1] : '';
                return ['items' => $items('invoice'), 'payments' => $pay ? self::rows("SELECT id,amount,paymentmode,date,transactionid FROM {$p}invoicepaymentrecords WHERE invoiceid=?$where ORDER BY id", [$id]) : []];
            case 'estimates':
                return ['items' => $items('estimate')];
            case 'proposals':
                return ['items' => $items('proposal')];
            case 'knowledge_base':
                return ['group' => self::rows("SELECT groupid,name FROM {$p}knowledge_base_groups WHERE groupid=?", [(int) $row['articlegroup']])];
        }
        return [];
    }

    public static function search(string $query, int $sid, ?array $only = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new Failure('invalid_query', _l('mcai_err_query'));
        }
        $out = [];
        foreach (array_keys(self::map()) as $r) {
            if (($only && !in_array($r, $only, true)) || $r === 'staff' || self::scope($r, $sid) === null) {
                continue;
            }
            $found = self::list($r, $sid, ['search' => $query, 'limit' => 5]);
            if ($found['items']) {
                $out[$r] = $found['items'];
            }
        }
        return ['query' => $query, 'results' => $out];
    }

    public static function inventory(int $sid): array
    {
        $p = db_prefix();
        $resources = [];
        foreach (self::map() as $name => $def) {
            $scope = self::scope($name, $sid);
            $count = null;
            if ($scope !== null) {
                $db = Store::db()->from($p . $def['table']);
                self::applyScope($db, $scope);
                $count = $db->count_all_results();
            }
            $resources[$name] = ['access' => $scope[0] ?? 'none', 'count' => $count];
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
