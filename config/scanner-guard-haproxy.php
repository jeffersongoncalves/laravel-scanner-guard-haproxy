<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Denylist Path
    |--------------------------------------------------------------------------
    |
    | Relative to base_path(). Load it with `src -f` in your HAProxy frontend.
    | The file is written to a temp file and renamed over the target, so HAProxy
    | never reads a half-written list, and only when its content changed.
    |
    */
    'path' => env('SCANNER_GUARD_HAPROXY_PATH', 'storage/app/scanner-guard/haproxy-denylist.lst'),

    /*
    |--------------------------------------------------------------------------
    | Reload Command
    |--------------------------------------------------------------------------
    |
    | HAProxy only re-reads an ACL file on reload. Runs (e.g. "sudo systemctl
    | reload haproxy") only when the file content changed; a non-zero exit or a
    | timeout is logged, never thrown, and the next sync runs it again.
    | Null = don't reload.
    |
    */
    'reload_command' => env('SCANNER_GUARD_HAPROXY_RELOAD_COMMAND'),

];
