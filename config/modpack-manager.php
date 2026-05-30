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
];
