<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Menedżer CRM AI
Description: Połączenia AI przez MCP i OAuth oraz praca na danych CRM z podglądem zmian i cofaniem z kopii.
Version: 0.1.1
Author: Dariusz Trachimowicz
Author URI: https://d-x.pl/
Requires at least: 3.0.*
*/

define('MCAI_MODULE', 'menedzer_crm_ai');
define('MCAI_VERSION', '0.1.1');

require_once __DIR__ . '/libraries/Mcai.php';
require_once __DIR__ . '/helpers.php';

register_activation_hook(MCAI_MODULE, 'mcai_activate');
register_uninstall_hook(MCAI_MODULE, 'mcai_uninstall');
register_language_files(MCAI_MODULE, [MCAI_MODULE]);

function mcai_activate()
{
    require_once __DIR__ . '/install.php';
}

// Odinstalowanie usuwa tabele modułu, więc wszystkie połączenia AI tracą dostęp od razu.
function mcai_uninstall()
{
    $CI = &get_instance();
    foreach (Mcai\Store::TABLES as $name) {
        $CI->db->query('DROP TABLE IF EXISTS `' . Mcai\Store::table($name) . '`');
    }
}

// Sprzątanie historii (30 dni), wygasłych zgód, tokenów i nieużytych rejestracji przy cronie Perfexa.
hooks()->add_action('after_cron_run', function () {
    Mcai\Store::cleanup();
});

hooks()->add_action('admin_init', function () {
    if (!is_admin()) {
        // Pracownik z listy uprawnionych widzi moduł w menu bocznym, żeby zarządzać swoimi połączeniami.
        if (is_staff_logged_in() && Mcai\Operators::level((int) get_staff_user_id()) !== null) {
            get_instance()->app_menu->add_sidebar_menu_item('menedzer-crm-ai', [
                'href' => admin_url(MCAI_MODULE), 'name' => _l('mcai_name'), 'icon' => 'fa-solid fa-robot', 'position' => 60,
            ]);
        }
        return;
    }
    get_instance()->app_menu->add_setup_menu_item('menedzer-crm-ai', [
        'href'     => admin_url(MCAI_MODULE),
        'name'     => _l('mcai_name'),
        'position' => 66,
    ]);
});

hooks()->add_filter('module_' . MCAI_MODULE . '_action_links', function ($actions) {
    $actions[] = '<a href="' . admin_url(MCAI_MODULE) . '">' . _l('settings') . '</a>';
    return $actions;
});
