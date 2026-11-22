<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="<?= mcai_e(_l('mcai_html_lang')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= mcai_e(_l('mcai_name')) ?></title>
<?php $this->load->view(MCAI_MODULE . '/page_style'); ?>
</head>
<body><main><h1><?= mcai_e(_l('mcai_name')) ?></h1><p><?= mcai_e($message) ?></p></main></body>
</html>
