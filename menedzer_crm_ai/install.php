<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/libraries/Mcai.php';

$CI = &get_instance();
$charset = $CI->db->char_set;
$collate = $CI->db->dbcollat;
$suffix  = " ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate}";
$t       = fn ($n) => Mcai\Store::table($n);

$sql = [
    // Lista uprawnionych pracowników. Administratorzy Perfexa są na niej zawsze, z poziomem pełnym.
    "CREATE TABLE IF NOT EXISTS `{$t('operators')}` (
        staff_id int NOT NULL, level varchar(8) NOT NULL, added_by int NOT NULL, created_at int NOT NULL,
        PRIMARY KEY (staff_id))",
    "CREATE TABLE IF NOT EXISTS `{$t('connections')}` (
        id varchar(32) NOT NULL, staff_id int NOT NULL, name varchar(100) NOT NULL, client varchar(32) NOT NULL,
        scope varchar(8) NOT NULL, token_hash varchar(64) NOT NULL DEFAULT '', created_at int NOT NULL,
        expires_at int NOT NULL DEFAULT 0, revoked tinyint NOT NULL DEFAULT 0, revoked_at int NOT NULL DEFAULT 0,
        deleted_at int NOT NULL DEFAULT 0, last_used int NOT NULL DEFAULT 0, rate_window int NOT NULL DEFAULT 0,
        rate_count int NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY staff_id (staff_id))",
    "CREATE TABLE IF NOT EXISTS `{$t('oauth_clients')}` (
        client_id varchar(80) NOT NULL, metadata text NOT NULL, created_at int NOT NULL,
        last_used int NOT NULL DEFAULT 0, PRIMARY KEY (client_id))",
    "CREATE TABLE IF NOT EXISTS `{$t('oauth_credentials')}` (
        hash varchar(64) NOT NULL, kind varchar(8) NOT NULL, connection_id varchar(32) NOT NULL,
        client_id varchar(80) NOT NULL, family varchar(32) NOT NULL, redirect_uri text NOT NULL,
        challenge varchar(43) NOT NULL DEFAULT '', resource varchar(255) NOT NULL, scope varchar(8) NOT NULL,
        created_at int NOT NULL, expires_at int NOT NULL, consumed_at int NOT NULL DEFAULT 0,
        PRIMARY KEY (hash), KEY connection_id (connection_id), KEY client_id (client_id))",
    "CREATE TABLE IF NOT EXISTS `{$t('oauth_consents')}` (
        hash varchar(64) NOT NULL, staff_id int NOT NULL, session_hash varchar(64) NOT NULL,
        request_hash varchar(64) NOT NULL, expires_at int NOT NULL, consumed_at int NOT NULL DEFAULT 0,
        PRIMARY KEY (hash), KEY expires_at (expires_at))",
    "CREATE TABLE IF NOT EXISTS `{$t('limits')}` (
        bucket varchar(64) NOT NULL, `window` int NOT NULL, count int NOT NULL DEFAULT 0,
        PRIMARY KEY (bucket, `window`))",
    "CREATE TABLE IF NOT EXISTS `{$t('operations')}` (
        id varchar(36) NOT NULL, connection_id varchar(32) NOT NULL, staff_id int NOT NULL, action varchar(40) NOT NULL,
        resource varchar(32) NOT NULL, target_id int NOT NULL DEFAULT 0, plan mediumtext NOT NULL,
        plan_hash varchar(64) NOT NULL, before_state mediumtext NOT NULL, before_hash varchar(64) NOT NULL DEFAULT '',
        after_hash varchar(64) NOT NULL DEFAULT '', new_id int NOT NULL DEFAULT 0, status varchar(24) NOT NULL,
        execution_key varchar(64) NOT NULL DEFAULT '', created_at int NOT NULL, expires_at int NOT NULL,
        updated_at int NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY connection_id (connection_id), KEY created_at (created_at))",
    "CREATE TABLE IF NOT EXISTS `{$t('audit')}` (
        id bigint unsigned NOT NULL AUTO_INCREMENT, connection_id varchar(32) NOT NULL, staff_id int NOT NULL,
        event varchar(64) NOT NULL, result varchar(24) NOT NULL, operation_id varchar(36) NOT NULL DEFAULT '',
        created_at int NOT NULL, PRIMARY KEY (id), KEY connection_time (connection_id, created_at), KEY created_at (created_at))",
];

foreach ($sql as $query) {
    $CI->db->query($query . $suffix);
}

if (get_option('mcai_install_id') === '') {
    add_option('mcai_install_id', bin2hex(random_bytes(16)), 0);
}
