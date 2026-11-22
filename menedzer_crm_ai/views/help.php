<?php defined('BASEPATH') or exit('No direct script access allowed');
$this->load->view(_l('mcai_help_view') === 'help_polish' ? 'help_polish' : 'help_english', ['mcpUrl' => $mcpUrl]);
