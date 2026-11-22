<?php

defined('BASEPATH') or exit('No direct script access allowed');

$lang['mcai_name'] = 'CRM AI Manager';
$lang['mcai_html_lang'] = 'en';
$lang['mcai_help_view'] = 'help_english';
$lang['mcai_your_level'] = 'Your level';
$lang['mcai_tab_start'] = 'Start';
$lang['mcai_tab_connections'] = 'Connections';
$lang['mcai_tab_operators'] = 'Permissions';
$lang['mcai_tab_activity'] = 'History';
$lang['mcai_tab_help'] = 'Help';

$lang['mcai_level_read'] = 'Read';
$lang['mcai_level_work'] = 'Work';
$lang['mcai_level_full'] = 'Full';
$lang['mcai_level_read_desc'] = 'Browse and search the data this person can access in the CRM. No changes.';
$lang['mcai_level_work_desc'] = 'Read plus tasks, comments, notes, leads, ticket reply drafts and knowledge base articles. Every change with preview and rollback.';
$lang['mcai_level_full_desc'] = 'Work plus customers, contacts, projects and sales documents (as drafts). No deleting and no sending to customers.';

$lang['mcai_start_title'] = 'Connect an AI assistant to the CRM';
$lang['mcai_start_intro'] = 'The AI assistant (Claude, Codex, ChatGPT, Gemini) reads CRM data and, with approval, prepares changes. It never has more permissions than you have in Perfex.';
$lang['mcai_mcp_url'] = 'MCP server address';
$lang['mcai_copy'] = 'Copy';
$lang['mcai_copied'] = 'Copied';
$lang['mcai_start_step1'] = 'In Claude open Settings, Connectors, Add custom connector and paste the address.';
$lang['mcai_start_step2'] = 'Sign in to the CRM and choose the access level and end date on the consent screen.';
$lang['mcai_start_step3'] = 'Ask the assistant for the connection status. The connection appears on the Connections tab.';
$lang['mcai_start_other_apps'] = 'Codex, ChatGPT, Gemini CLI and Claude Code: see the Help tab';

$lang['mcai_diag_title'] = 'Sign-in check';
$lang['mcai_diag_intro'] = 'Checks whether AI apps can find the OAuth sign-in documents at the addresses they look for.';
$lang['mcai_diag_run'] = 'Check';
$lang['mcai_diag_ok'] = 'works';
$lang['mcai_diag_bad'] = 'not working';
$lang['mcai_diag_optional'] = 'optional';
$lang['mcai_diag_all_ok'] = 'Sign-in from AI apps should work.';
$lang['mcai_diag_fix'] = 'The web server intercepts /.well-known addresses before Perfex. Add the rule below to this site\'s nginx configuration (before the location ~ /.well-known block) and reload nginx. Until then a manual credential works.';
$lang['mcai_diag_cloudpanel'] = 'in the server block listening on 443';
$lang['mcai_diag_fix_note'] = 'Apache with the Perfex .htaccess file does not need the rule.';

$lang['mcai_col_name'] = 'Name';
$lang['mcai_col_person'] = 'Person';
$lang['mcai_col_scope'] = 'Level';
$lang['mcai_col_created'] = 'Created';
$lang['mcai_col_expires'] = 'Valid until';
$lang['mcai_col_used'] = 'Last used';
$lang['mcai_col_state'] = 'State';
$lang['mcai_col_time'] = 'Time';
$lang['mcai_col_event'] = 'Event';
$lang['mcai_col_result'] = 'Result';
$lang['mcai_col_operation'] = 'Operation';
$lang['mcai_manual'] = 'manual';
$lang['mcai_unlimited'] = 'no end date';
$lang['mcai_state_active'] = 'active';
$lang['mcai_state_inactive'] = 'inactive';
$lang['mcai_revoke'] = 'Revoke';
$lang['mcai_revoke_all'] = 'Revoke all connections';
$lang['mcai_revoke_all_confirm'] = 'Revoke all AI connections in this CRM?';
$lang['mcai_connection_revoked'] = 'Connection revoked.';
$lang['mcai_connection_revoked_all'] = 'All connections revoked.';
$lang['mcai_token_once'] = 'Copy the credential now. It will not be shown again.';
$lang['mcai_manual_title'] = 'Manual credential';
$lang['mcai_manual_intro'] = 'For Codex, Gemini CLI and clients that do not sign in with OAuth. The credential works like a password: keep it in an environment variable.';
$lang['mcai_manual_create'] = 'Create credential';
$lang['mcai_app'] = 'App';
$lang['mcai_other'] = 'Other';

$lang['mcai_operators_intro'] = 'Only people on this list can connect an AI assistant to the CRM. Administrators are always on it, with the full level. Removing a person revokes their connections immediately.';
$lang['mcai_admin_auto'] = 'administrator';
$lang['mcai_operator_add'] = 'Add person';
$lang['mcai_add'] = 'Add';
$lang['mcai_save'] = 'Save';
$lang['mcai_saved'] = 'Saved.';
$lang['mcai_remove'] = 'Remove from list';
$lang['mcai_remove_confirm'] = 'Remove this person from the list? Their AI connections will be revoked.';
$lang['mcai_operator_removed'] = 'Person removed, their connections revoked.';
$lang['mcai_activity_intro'] = 'Every tool use by the AI assistant. Entries are kept for 30 days.';

$lang['mcai_consent_title'] = 'Approve access to the CRM';
$lang['mcai_consent_app'] = 'App:';
$lang['mcai_consent_declared'] = 'The app name is declared by its developer. Check the return address before approving.';
$lang['mcai_consent_return'] = 'Return address:';
$lang['mcai_consent_crm'] = 'CRM:';
$lang['mcai_consent_scope'] = 'Access level';
$lang['mcai_consent_expiry'] = 'Access period';
$lang['mcai_consent_until'] = 'Until';
$lang['mcai_consent_end_date'] = 'End date';
$lang['mcai_consent_unlimited'] = 'No end date';
$lang['mcai_consent_unlimited_desc'] = 'Access without an end date lasts until the connection is revoked or you are removed from the list.';
$lang['mcai_consent_rules'] = 'The assistant does not delete data and never sends anything to customers. It has at most your CRM permissions.';
$lang['mcai_consent_allow'] = 'Approve access';
$lang['mcai_consent_deny'] = 'Deny';

$lang['mcai_instructions'] = 'Start with crm_status. Access follows the person\'s CRM role and the connection level. Changes: crm_operation_preview first, then crm_operation_execute. After an unclear result read crm_operation_status, do not retry the write.';
$lang['mcai_rules_for_ai'] = 'You do not delete data. You never send anything to customers: replies and documents are saved as drafts and a staff member sends them. Every change has a preview, execution and rollback.';

$lang['mcai_err_https'] = 'HTTPS is required.';
$lang['mcai_err_auth'] = 'No valid credential or access was revoked.';
$lang['mcai_err_authorize'] = 'Invalid sign-in request. Go back to the app and add the connection again.';
$lang['mcai_err_not_operator'] = 'You are not on the list of people allowed to connect AI to this CRM. Ask an administrator to add you.';
$lang['mcai_err_level'] = 'You are not allowed to grant this access level.';
$lang['mcai_err_consent'] = 'The form was already used or has expired. Go back to the app and connect again.';
$lang['mcai_err_scope'] = 'Choose an access level.';
$lang['mcai_err_date'] = 'Enter a valid end date.';
$lang['mcai_err_date_range'] = 'Choose a future date, at most one year ahead.';
$lang['mcai_err_rate'] = 'Too many attempts. Try again in a minute.';
$lang['mcai_err_operator'] = 'This person cannot be saved.';
$lang['mcai_err_resource'] = 'Unknown resource.';
$lang['mcai_err_forbidden'] = 'This person\'s CRM role does not allow access to this data.';
$lang['mcai_err_not_found'] = 'Not found or no access.';
$lang['mcai_err_filter'] = 'This filter does not apply to this resource.';
$lang['mcai_err_query'] = 'Enter at least 2 characters.';
$lang['mcai_err_tool'] = 'This tool is not available for this connection.';
$lang['mcai_err_server'] = 'Server error. Details are in the CRM log.';
