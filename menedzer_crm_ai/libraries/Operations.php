<?php

declare(strict_types=1);

namespace Mcai;

defined('BASEPATH') or exit('No direct script access allowed');

// shortcut: zapisy dochodzą w kroku 4 planu; do tego czasu moduł tylko czyta.
final class Operations
{
    public static function schemas(string $level): array { return []; }
    public static function catalog(string $level): array { return []; }
    public static function call(array $c, string $level, string $tool, array $a): array
    {
        throw new Failure('unknown_tool', _l('mcai_err_tool'), 404);
    }
}
