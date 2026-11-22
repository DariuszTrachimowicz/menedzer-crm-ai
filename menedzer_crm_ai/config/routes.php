<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Issuer OAuth ma ścieżkę /menedzer_crm_ai, więc klienci szukają dokumentów pod nią (OIDC i RFC 8414).
$route['menedzer_crm_ai/\.well-known/openid-configuration']    = 'oauth/openid';
$route['menedzer_crm_ai/\.well-known/oauth-authorization-server'] = 'oauth/server';
$route['menedzer_crm_ai/\.well-known/oauth-protected-resource(.*)'] = 'oauth/resource';
