<?php

return [
    'curseforge_api_key' => env('MODPACK_MANAGER_CURSEFORGE_API_KEY', ''),
    'modrinth_token'     => env('MODPACK_MANAGER_MODRINTH_TOKEN', ''),

    // The Modpacks page only shows on servers whose egg has one of these tags
    // (case-insensitive). Default: minecraft. An empty list disables the filter
    // and shows the page on every server. Edited via the plugin settings UI,
    // persisted as a comma-separated MODPACK_MANAGER_EGG_TAGS env value.
    'required_egg_tags' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('MODPACK_MANAGER_EGG_TAGS', 'minecraft'))),
        fn ($t) => $t !== ''
    )),

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
