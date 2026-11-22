<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Adres MCP: /menedzer_crm_ai/mcp */
class Mcp extends App_Controller
{
    public function index()
    {
        $headers = array_change_key_case($this->input->request_headers(), CASE_LOWER);
        $body = (string) file_get_contents('php://input', false, null, 0, Mcai\Server::MAX_BODY + 1);
        [$status, $extra, $payload] = Mcai\Server::handle(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $headers, $body);
        mcai_send_json($status, $payload, $extra + ['Cache-Control' => 'private, no-store']);
    }
}
