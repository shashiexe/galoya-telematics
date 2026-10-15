<?php
// ==== StaffTrack configuration — edit these values on the server ====
return [
    'db_host' => 'localhost',
    'db_name' => 'galoya5_track',
    'db_user' => 'galoya5_viraj',
    'db_pass' => 'gopl@#$1809',

    // 'osm' (OpenStreetMap, free) or 'google'
    'map_provider' => 'osm',
    'google_maps_key' => '',

    // Default map centre (Galoya Plantation / Hingurana)
    'map_center' => [7.2050, 81.6580],
    'map_zoom' => 12,

    'timezone' => 'Asia/Colombo',
    'upload_dir' => __DIR__ . '/uploads',
    'upload_url' => 'uploads',
    'max_photo_mb' => 8,

    // Staff considered "offline" if no location for this many minutes
    'offline_after_min' => 5,
];
