<?php defined('BASEPATH') or exit('No direct script access allowed');
init_head();
$url = fn ($tab, $extra = '') => admin_url(MCAI_MODULE . '?tab=' . $tab . $extra);
$date = fn ($ts) => (int) $ts ? _dt(date('Y-m-d H:i:s', (int) $ts)) : '-';
$csrf = '<input type="hidden" name="' . mcai_e($this->security->get_csrf_token_name()) . '" value="' . mcai_e($this->security->get_csrf_hash()) . '">';
?>
<div id="wrapper">
<div class="content">
<div class="row"><div class="col-md-12">
<h4 class="tw-mt-0 tw-font-semibold tw-text-lg"><?= mcai_e(_l('mcai_name')) ?> <small class="text-muted"><?= MCAI_VERSION ?> · <?= mcai_e(_l('mcai_your_level')) ?>: <?= mcai_e(Mcai\Levels::label($level)) ?></small></h4>
<ul class="nav nav-tabs tw-mb-4">
<?php foreach ($tabs as $t) { ?>
<li class="<?= $t === $tab ? 'active' : '' ?>"><a href="<?= $url($t) ?>"><?= mcai_e(_l('mcai_tab_' . $t)) ?></a></li>
<?php } ?>
</ul>
<div class="panel_s"><div class="panel-body">

<?php if ($tab === 'start') { ?>
<h4><?= mcai_e(_l('mcai_start_title')) ?></h4>
<p><?= mcai_e(_l('mcai_start_intro')) ?></p>
<label for="mcai-url"><?= mcai_e(_l('mcai_mcp_url')) ?></label>
<div class="input-group tw-max-w-2xl"><input id="mcai-url" class="form-control" readonly value="<?= mcai_e($mcpUrl) ?>"><span class="input-group-btn"><button class="btn btn-default" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mcai-url').value);this.textContent='<?= mcai_e(_l('mcai_copied')) ?>'"><?= mcai_e(_l('mcai_copy')) ?></button></span></div>
<ol class="tw-mt-4">
<li><?= mcai_e(_l('mcai_start_step1')) ?></li>
<li><?= mcai_e(_l('mcai_start_step2')) ?></li>
<li><?= mcai_e(_l('mcai_start_step3')) ?></li>
</ol>
<p><a href="<?= $url('help') ?>"><?= mcai_e(_l('mcai_start_other_apps')) ?></a></p>
<hr>
<h4><?= mcai_e(_l('mcai_diag_title')) ?></h4>
<?php if ($checks === null) { ?>
<p><?= mcai_e(_l('mcai_diag_intro')) ?></p>
<a class="btn btn-default" href="<?= $url('start', '&check=1') ?>"><?= mcai_e(_l('mcai_diag_run')) ?></a>
<?php } else {
    $ok = !array_filter($checks, fn ($c) => $c['required'] && !$c['valid']); ?>
<table class="table"><tbody>
<?php foreach ($checks as $c) { ?>
<tr><td><code><?= mcai_e($c['url']) ?></code></td><td><?= $c['status'] ?></td><td><?= $c['valid'] ? '<span class="text-success">' . mcai_e(_l('mcai_diag_ok')) . '</span>' : ($c['required'] ? '<span class="text-danger">' . mcai_e(_l('mcai_diag_bad')) . '</span>' : '<span class="text-muted">' . mcai_e(_l('mcai_diag_optional')) . '</span>') ?></td></tr>
<?php } ?>
</tbody></table>
<?php if ($ok) { ?>
<div class="alert alert-success"><?= mcai_e(_l('mcai_diag_all_ok')) ?></div>
<?php } else { ?>
<div class="alert alert-warning"><?= mcai_e(_l('mcai_diag_fix')) ?></div>
<p><strong>CloudPanel</strong> (<?= mcai_e(_l('mcai_diag_cloudpanel')) ?>)</p>
<pre>location ^~ /<?= MCAI_MODULE ?>/.well-known/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}</pre>
<p><strong>nginx + PHP-FPM</strong></p>
<pre>location ^~ /<?= MCAI_MODULE ?>/.well-known/ {
    try_files $uri /index.php?$args;
}</pre>
<p class="text-muted"><?= mcai_e(_l('mcai_diag_fix_note')) ?></p>
<?php }
} ?>

<?php } elseif ($tab === 'connections') { ?>
<?php if ($token) { ?>
<div class="alert alert-warning"><strong><?= mcai_e(_l('mcai_token_once')) ?></strong><br><code style="word-break:break-all"><?= mcai_e($token) ?></code></div>
<?php } ?>
<h4><?= mcai_e(_l('mcai_tab_connections')) ?></h4>
<table class="table dt-table"><thead><tr><th><?= mcai_e(_l('mcai_col_name')) ?></th><?php if ($isAdmin) { ?><th><?= mcai_e(_l('mcai_col_person')) ?></th><?php } ?><th><?= mcai_e(_l('mcai_col_scope')) ?></th><th><?= mcai_e(_l('mcai_col_created')) ?></th><th><?= mcai_e(_l('mcai_col_expires')) ?></th><th><?= mcai_e(_l('mcai_col_used')) ?></th><th><?= mcai_e(_l('mcai_col_state')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($connections as $c) {
    $active = Mcai\Connections::effective($c) !== null; ?>
<tr><td><?= mcai_e($c['name']) ?> <span class="text-muted">(<?= mcai_e($c['token_hash'] !== '' ? _l('mcai_manual') : 'OAuth') ?>)</span></td>
<?php if ($isAdmin) { ?><td><?= mcai_e($names[(int) $c['staff_id']] ?? '#' . $c['staff_id']) ?></td><?php } ?>
<td><?= mcai_e(Mcai\Levels::label($c['scope'])) ?></td><td><?= $date($c['created_at']) ?></td><td><?= (int) $c['expires_at'] ? $date($c['expires_at']) : mcai_e(_l('mcai_unlimited')) ?></td><td><?= $date($c['last_used']) ?></td>
<td><?= $active ? '<span class="text-success">' . mcai_e(_l('mcai_state_active')) . '</span>' : '<span class="text-muted">' . mcai_e(_l('mcai_state_inactive')) . '</span>' ?></td>
<td><?php if (!(int) $c['revoked']) { ?><form method="post" action="<?= admin_url(MCAI_MODULE . '/connection_revoke') ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= mcai_e($c['id']) ?>"><button class="btn btn-danger btn-xs"><?= mcai_e(_l('mcai_revoke')) ?></button></form><?php } ?></td></tr>
<?php } ?>
</tbody></table>
<?php if ($isAdmin && $connections) { ?>
<form method="post" action="<?= admin_url(MCAI_MODULE . '/connections_revoke_all') ?>" onsubmit="return confirm('<?= mcai_e(_l('mcai_revoke_all_confirm')) ?>')"><?= $csrf ?><button class="btn btn-default"><?= mcai_e(_l('mcai_revoke_all')) ?></button></form>
<?php } ?>
<hr>
<h4><?= mcai_e(_l('mcai_manual_title')) ?></h4>
<p class="text-muted"><?= mcai_e(_l('mcai_manual_intro')) ?></p>
<form method="post" action="<?= admin_url(MCAI_MODULE . '/manual_create') ?>" class="tw-max-w-xl"><?= $csrf ?>
<div class="form-group"><label><?= mcai_e(_l('mcai_col_name')) ?></label><input name="name" class="form-control" required maxlength="100" value="Codex"></div>
<div class="form-group"><label><?= mcai_e(_l('mcai_app')) ?></label><select name="client" class="form-control"><option value="codex">Codex</option><option value="gemini">Gemini CLI</option><option value="claude-code">Claude Code</option><option value="other"><?= mcai_e(_l('mcai_other')) ?></option></select></div>
<div class="form-group"><label><?= mcai_e(_l('mcai_col_scope')) ?></label><select name="scope" class="form-control">
<?php foreach (array_keys(Mcai\Levels::ORDER) as $s) { if (Mcai\Levels::atLeast($level, $s)) { ?><option value="<?= $s ?>"><?= mcai_e(Mcai\Levels::label($s)) ?></option><?php } } ?></select></div>
<div class="form-group"><label><?= mcai_e(_l('mcai_consent_expiry')) ?></label>
<div class="radio"><label><input type="radio" name="expiry_mode" value="date" checked> <?= mcai_e(_l('mcai_consent_until')) ?> <input type="date" name="expiry_date" value="<?= date('Y-m-d', time() + 30 * 86400) ?>"></label></div>
<div class="radio"><label><input type="radio" name="expiry_mode" value="unlimited"> <?= mcai_e(_l('mcai_consent_unlimited')) ?></label></div></div>
<button class="btn btn-primary"><?= mcai_e(_l('mcai_manual_create')) ?></button>
</form>

<?php } elseif ($tab === 'operators') { ?>
<h4><?= mcai_e(_l('mcai_tab_operators')) ?></h4>
<p class="text-muted"><?= mcai_e(_l('mcai_operators_intro')) ?></p>
<table class="table"><thead><tr><th><?= mcai_e(_l('mcai_col_person')) ?></th><th>E-mail</th><th><?= mcai_e(_l('mcai_col_scope')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($operators as $o) { ?>
<tr><td><?= mcai_e($o['firstname'] . ' ' . $o['lastname']) ?><?= (int) $o['active'] ? '' : ' <span class="text-muted">(' . mcai_e(_l('mcai_state_inactive')) . ')</span>' ?></td><td><?= mcai_e($o['email']) ?></td>
<?php if ((int) $o['admin']) { ?>
<td><?= mcai_e(Mcai\Levels::label('full')) ?> <span class="text-muted">(<?= mcai_e(_l('mcai_admin_auto')) ?>)</span></td><td></td>
<?php } else { ?>
<td><form method="post" action="<?= admin_url(MCAI_MODULE . '/operator_save') ?>" class="form-inline"><?= $csrf ?><input type="hidden" name="staff_id" value="<?= (int) $o['staffid'] ?>">
<select name="level" class="form-control input-sm"><?php foreach (array_keys(Mcai\Levels::ORDER) as $s) { ?><option value="<?= $s ?>"<?= $o['level'] === $s ? ' selected' : '' ?>><?= mcai_e(Mcai\Levels::label($s)) ?></option><?php } ?></select>
<button class="btn btn-default btn-sm"><?= mcai_e(_l('mcai_save')) ?></button></form></td>
<td><form method="post" action="<?= admin_url(MCAI_MODULE . '/operator_remove') ?>" onsubmit="return confirm('<?= mcai_e(_l('mcai_remove_confirm')) ?>')"><?= $csrf ?><input type="hidden" name="staff_id" value="<?= (int) $o['staffid'] ?>"><button class="btn btn-danger btn-sm"><?= mcai_e(_l('mcai_remove')) ?></button></form></td>
<?php } ?></tr>
<?php } ?>
</tbody></table>
<?php if ($candidates) { ?>
<h4><?= mcai_e(_l('mcai_operator_add')) ?></h4>
<form method="post" action="<?= admin_url(MCAI_MODULE . '/operator_save') ?>" class="form-inline"><?= $csrf ?>
<select name="staff_id" class="form-control"><?php foreach ($candidates as $s) { ?><option value="<?= (int) $s['staffid'] ?>"><?= mcai_e($s['firstname'] . ' ' . $s['lastname'] . ' (' . $s['email'] . ')') ?></option><?php } ?></select>
<select name="level" class="form-control"><?php foreach (array_keys(Mcai\Levels::ORDER) as $s) { ?><option value="<?= $s ?>"><?= mcai_e(Mcai\Levels::label($s)) ?></option><?php } ?></select>
<button class="btn btn-primary"><?= mcai_e(_l('mcai_add')) ?></button></form>
<?php } ?>
<hr>
<dl><?php foreach (array_keys(Mcai\Levels::ORDER) as $s) { ?><dt><?= mcai_e(Mcai\Levels::label($s)) ?></dt><dd class="tw-mb-2"><?= mcai_e(_l('mcai_level_' . $s . '_desc')) ?></dd><?php } ?></dl>

<?php } elseif ($tab === 'activity') { ?>
<h4><?= mcai_e(_l('mcai_tab_activity')) ?></h4>
<p class="text-muted"><?= mcai_e(_l('mcai_activity_intro')) ?></p>
<table class="table"><thead><tr><th><?= mcai_e(_l('mcai_col_time')) ?></th><?php if ($isAdmin) { ?><th><?= mcai_e(_l('mcai_col_person')) ?></th><?php } ?><th><?= mcai_e(_l('mcai_col_event')) ?></th><th><?= mcai_e(_l('mcai_col_result')) ?></th><th><?= mcai_e(_l('mcai_col_operation')) ?></th></tr></thead><tbody>
<?php foreach ($logs as $l) { ?>
<tr><td><?= $date($l['created_at']) ?></td><?php if ($isAdmin) { ?><td><?= mcai_e($names[(int) $l['staff_id']] ?? '#' . $l['staff_id']) ?></td><?php } ?><td><code><?= mcai_e($l['event']) ?></code></td><td><?= mcai_e($l['result']) ?></td><td><?= mcai_e($l['operation_id']) ?></td></tr>
<?php } ?>
</tbody></table>

<?php } else { $this->load->view('help', ['mcpUrl' => $mcpUrl]); } ?>

</div></div>
</div></div>
</div>
</div>
<?php init_tail(); ?>
</body></html>
