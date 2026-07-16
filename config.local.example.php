<?php
declare(strict_types=1);

return [
    'sku_db_host' => 'localhost',
    'sku_db_port' => '3306',
    'sku_db_name' => '',
    'sku_db_user' => '',
    'sku_db_password' => '',
    'sku_db_charset' => 'utf8mb4',
    'admin_code_hash' => '',
    'shopee_ingest_base_url' => 'https://api.jenanggemi.com',
    'shopee_ingest_setup_token' => '',
    'tiktok_ingest_setup_token' => '',
    'shopee_accounts' => 'jenang-gemi-shopee,zero-shopee,zfit-shopee',
    'tiktok_accounts' => 'jenang-gemi-tiktok,zero-tiktok,zfit-tiktok',
    // Required before Big Set activation; include every API Ingest automatic source.
    'marketplace_sources' => 'shopee:jenang-gemi-shopee,shopee:zero-shopee,shopee:zfit-shopee,tiktok:jenang-gemi-tiktok,tiktok:zero-tiktok,tiktok:zfit-tiktok',
    'partner_db_host' => '',
    'partner_db_port' => '3306',
    'partner_db_name' => '',
    'partner_db_user' => '',
    'partner_db_password' => '',
    'partner_db_charset' => 'utf8mb4',
];
