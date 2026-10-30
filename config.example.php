<?php
return [
    'DB_DSN' => getenv('DB_DSN') ?: 'mysql:host=localhost;dbname=news;charset=utf8mb4',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'replace_me',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'replace_me',
    'WORKER_TOKEN' => getenv('WORKER_TOKEN') ?: '',
];