<?php

defined('BASEPATH') or exit('No direct script access allowed');

/** Wysyła odpowiedź JSON i kończy żądanie. null przy 202 = pusta treść. */
function mcai_send_json(int $status, $payload, array $headers = []): void
{
    http_response_code($status);
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo Mcai\Store::json($payload);
    }
    exit;
}

function mcai_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
