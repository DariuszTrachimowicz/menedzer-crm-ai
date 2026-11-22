<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Klienci MCP i OAuth nie mają ciasteczka CSRF Perfexa. Uwierzytelnia ich token albo PKCE.
return ['menedzer_crm_ai/mcp', 'menedzer_crm_ai/oauth/(token|register|revoke)'];
