<?php
return [
    'DB_PATH' => getenv('DB_PATH') ?: (getenv('HOME') ?: dirname(__DIR__, 2)) . '/chat-data/news.sqlite',
    'WORKER_TOKEN' => getenv('WORKER_TOKEN') ?: '',
];