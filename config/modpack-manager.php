<?php

return [
    'curseforge_api_key' => env('MODPACK_MANAGER_CURSEFORGE_API_KEY', ''),
    'modrinth_token'     => env('MODPACK_MANAGER_MODRINTH_TOKEN', ''),

    // Files always preserved during a modpack update
    'preserved_files' => [
        'server.properties',
        'eula.txt',
        'ops.json',
        'whitelist.json',
        'banned-players.json',
        'banned-ips.json',
        'usercache.json',
    ],

    // Max download size in MB before job times out
    'download_timeout' => 300,

    // ─── Scheduled update checks ───────────────────────────────────────────────
    // Periodically check installed modpacks for newer versions and notify the
    // server owner (panel bell) when a new version first appears.
    'update_checks_enabled' => env('MODPACK_MANAGER_UPDATE_CHECKS', true),

    // How often the scheduler runs the check. One of:
    //   'hourly' | 'every_six_hours' | 'twice_daily' | 'daily' | 'weekly'
    'update_check_frequency' => env('MODPACK_MANAGER_UPDATE_FREQUENCY', 'daily'),
];
