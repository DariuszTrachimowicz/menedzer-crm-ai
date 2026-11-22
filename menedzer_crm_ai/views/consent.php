<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="<?= mcai_e(_l('mcai_html_lang')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= mcai_e(_l('mcai_consent_title')) ?> | <?= mcai_e(_l('mcai_name')) ?></title>
<?php $this->load->view(MCAI_MODULE . '/page_style'); ?>
</head>
<body>
<main>
<h1><?= mcai_e(_l('mcai_consent_title')) ?></h1>
<p><?= mcai_e(_l('mcai_consent_app')) ?> <strong><?= mcai_e($client['client_name']) ?></strong></p>
<p class="muted"><?= mcai_e(_l('mcai_consent_declared')) ?></p>
<p><?= mcai_e(_l('mcai_consent_return')) ?> <strong><?= mcai_e($host) ?></strong></p>
<p><?= mcai_e(_l('mcai_consent_crm')) ?> <?= mcai_e(Mcai\Store::base()) ?> · <?= mcai_e(get_staff_full_name()) ?></p>
<form method="post" action="<?= mcai_e($action) ?>">
<?php foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'scope', 'state'] as $k) {
    if (isset($request[$k])) { ?>
<input type="hidden" name="<?= mcai_e($k) ?>" value="<?= mcai_e($request[$k]) ?>">
<?php }
} ?>
<input type="hidden" name="consent_context" value="<?= mcai_e($context) ?>">
<input type="hidden" name="<?= mcai_e($this->security->get_csrf_token_name()) ?>" value="<?= mcai_e($this->security->get_csrf_hash()) ?>">
<fieldset>
<legend><?= mcai_e(_l('mcai_consent_scope')) ?></legend>
<?php foreach ($offered as $i => $scope) { ?>
<label><input type="radio" name="selected_scope" value="<?= mcai_e($scope) ?>"<?= $i === count($offered) - 1 ? ' checked' : '' ?>> <strong><?= mcai_e(_l('mcai_level_' . $scope)) ?></strong><br><span class="muted"><?= mcai_e(_l('mcai_level_' . $scope . '_desc')) ?></span></label>
<?php } ?>
</fieldset>
<fieldset>
<legend><?= mcai_e(_l('mcai_consent_expiry')) ?></legend>
<label><input type="radio" name="expiry_mode" value="date" checked> <?= mcai_e(_l('mcai_consent_until')) ?></label>
<label><?= mcai_e(_l('mcai_consent_end_date')) ?> <input type="date" name="expiry_date" value="<?= date('Y-m-d', time() + 30 * 86400) ?>" min="<?= date('Y-m-d', time() + 86400) ?>"></label>
<label><input type="radio" name="expiry_mode" value="unlimited"> <?= mcai_e(_l('mcai_consent_unlimited')) ?></label>
<p class="muted"><?= mcai_e(_l('mcai_consent_unlimited_desc')) ?></p>
</fieldset>
<p class="muted"><?= mcai_e(_l('mcai_consent_rules')) ?></p>
<button type="submit" name="decision" value="allow"><?= mcai_e(_l('mcai_consent_allow')) ?></button>
<button type="submit" name="decision" value="deny" class="secondary"><?= mcai_e(_l('mcai_consent_deny')) ?></button>
</form>
</main>
</body>
</html>
