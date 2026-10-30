<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = [];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $loadedConfig = require $configFile;
    if (is_array($loadedConfig)) {
        $config = $loadedConfig;
    }
}

function setting(string $key, $default = null)
{
    global $config;
    $value = getenv($key);
    return $value !== false && $value !== '' ? $value : ($config[$key] ?? $default);
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $homeDirectory = getenv('HOME') ?: dirname(__DIR__, 2);
    $databasePath = (string) setting('DB_PATH', $homeDirectory . '/chat-data/news.sqlite');
    if ($databasePath !== ':memory:') {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the SQLite database directory.');
        }
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 10000');
    initialize_schema($pdo);
    return $pdo;
}

function initialize_schema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            feed_url TEXT NOT NULL,
            feed_url_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id INTEGER NOT NULL,
            guid_hash TEXT NOT NULL,
            url TEXT NOT NULL,
            title TEXT NOT NULL,
            summary TEXT NULL,
            content TEXT NOT NULL,
            cover_image_url TEXT NULL,
            published_at TEXT NULL,
            embedding TEXT NULL,
            embedding_status TEXT NOT NULL DEFAULT 'pending',
            claimed_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source_id, guid_hash),
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS follows (
            user_id TEXT NOT NULL,
            source_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, source_id),
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS reads (
            user_id TEXT NOT NULL,
            article_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, article_id),
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS user_interests (
            user_id TEXT NOT NULL PRIMARY KEY,
            embedding TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS interest_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            source_text TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            claimed_at TEXT NULL
        )",
        "CREATE TABLE IF NOT EXISTS worker_status (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            phase TEXT NOT NULL DEFAULT 'offline',
            message TEXT NOT NULL DEFAULT 'Worker has not checked in yet',
            completed INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS article_published ON articles (published_at)',
        'CREATE INDEX IF NOT EXISTS article_embedding_status ON articles (embedding_status)',
        'CREATE INDEX IF NOT EXISTS read_user_date ON reads (user_id, created_at)',
        'CREATE INDEX IF NOT EXISTS interest_job_status ON interest_jobs (status, id)',
        'CREATE INDEX IF NOT EXISTS interest_job_user ON interest_jobs (user_id, id)',
    ];
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    ensure_column($pdo, 'articles', 'cover_image_url', 'TEXT NULL');
    $pdo->exec("INSERT OR IGNORE INTO worker_status (id) VALUES (1)");
    $initialized = true;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($columns as $existing) {
        if ($existing['name'] === $column) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > 3 * 1024 * 1024) {
        respond(['error' => 'Request body is too large.'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['error' => 'Request body must be valid JSON.'], 400);
    }
    return $data;
}

function require_worker(): void
{
    $expected = (string) setting('WORKER_TOKEN', '');
    $provided = '';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization' && preg_match('/^Bearer\s+(.+)$/i', (string) $value, $matches)) {
            $provided = $matches[1];
        }
    }
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        respond(['error' => 'Worker authentication required.'], 401);
    }
}

function valid_user_id($value): string
{
    $userId = is_string($value) ? $value : '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $userId)) {
        respond(['error' => 'A valid user_id is required.'], 400);
    }
    return $userId;
}

function text_value($value, int $limit): string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}

function queue_interest_job(PDO $pdo, string $userId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT a.title, a.summary, a.content, s.name AS source_name
         FROM reads r JOIN articles a ON a.id = r.article_id
         JOIN sources s ON s.id = a.source_id
         WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 30'
    );
    $stmt->execute([$userId]);
    $parts = [];
    foreach ($stmt->fetchAll() as $row) {
        $excerpt = $row['summary'] ?: $row['content'];
        $parts[] = text_value($row['source_name'] . ' - ' . $row['title'] . ': ' . $excerpt, 650);
    }

    $stmt = $pdo->prepare(
        'SELECT s.name FROM follows f JOIN sources s ON s.id = f.source_id WHERE f.user_id = ? ORDER BY s.name LIMIT 30'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $parts[] = 'Followed source: ' . $row['name'];
    }

    $sourceText = text_value(implode("\n", $parts), 18000);
    if ($sourceText === '') {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE interest_jobs SET status = 'superseded' WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $stmt = $pdo->prepare("INSERT INTO interest_jobs (user_id, source_text, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$userId, $sourceText]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function cosine_similarity(array $left, array $right): float
{
    if (count($left) !== count($right) || count($left) === 0) {
        return 0.0;
    }
    $dot = 0.0;
    $leftNorm = 0.0;
    $rightNorm = 0.0;
    foreach ($left as $index => $value) {
        $dot += $value * $right[$index];
        $leftNorm += $value * $value;
        $rightNorm += $right[$index] * $right[$index];
    }
    if ($leftNorm === 0.0 || $rightNorm === 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
}

function get_feed(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare('SELECT source_id FROM follows WHERE user_id = ?');
    $stmt->execute([$userId]);
    $following = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $stmt = $pdo->prepare('SELECT article_id FROM reads WHERE user_id = ?');
    $stmt->execute([$userId]);
    $read = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $stmt = $pdo->prepare('SELECT embedding, updated_at FROM user_interests WHERE user_id = ?');
    $stmt->execute([$userId]);
    $interestRow = $stmt->fetch();
    $interestVector = $interestRow ? json_decode($interestRow['embedding'], true) : null;

    $sources = $pdo->query('SELECT id, name, feed_url FROM sources ORDER BY name')->fetchAll();
    foreach ($sources as &$source) {
        $source['id'] = (int) $source['id'];
        $source['following'] = isset($following[$source['id']]);
    }
    unset($source);

    $rows = $pdo->query(
        'SELECT a.id, a.source_id, a.url, a.title, a.summary, a.cover_image_url, a.published_at, a.created_at,
                a.embedding, s.name AS source_name
         FROM articles a JOIN sources s ON s.id = a.source_id
         ORDER BY COALESCE(a.published_at, a.created_at) DESC LIMIT 200'
    )->fetchAll();
    $now = time();
    foreach ($rows as &$article) {
        $id = (int) $article['id'];
        $sourceId = (int) $article['source_id'];
        $vector = json_decode((string) $article['embedding'], true);
        $similarity = is_array($interestVector) && is_array($vector)
            ? cosine_similarity($interestVector, $vector)
            : 0.0;
        $date = strtotime((string) ($article['published_at'] ?: $article['created_at'])) ?: $now;
        $ageHours = max(0, ($now - $date) / 3600);
        $score = (($similarity + 1) / 2) * 0.72
            + (isset($following[$sourceId]) ? 0.16 : 0.0)
            + 0.06 * exp(-$ageHours / 72)
            - (isset($read[$id]) ? 0.05 : 0.0);
        $article['id'] = $id;
        $article['source_id'] = $sourceId;
        $article['following'] = isset($following[$sourceId]);
        $article['read'] = isset($read[$id]);
        $article['similarity'] = round($similarity, 4);
        $article['score'] = round($score, 4);
        unset($article['embedding']);
    }
    unset($article);
    usort($rows, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    $stmt = $pdo->prepare('SELECT status, created_at FROM interest_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $interestJob = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reads WHERE user_id = ?');
    $stmt->execute([$userId]);
    $readCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE user_id = ?');
    $stmt->execute([$userId]);
    $followCount = (int) $stmt->fetchColumn();
    $worker = $pdo->query('SELECT phase, message, completed, total, updated_at FROM worker_status WHERE id = 1')->fetch();

    return [
        'articles' => $rows,
        'sources' => $sources,
        'interest_updated_at' => $interestRow['updated_at'] ?? null,
        'interest_status' => $interestJob['status'] ?? ($interestRow ? 'complete' : 'idle'),
        'interest_job_created_at' => $interestJob['created_at'] ?? null,
        'read_count' => $readCount,
        'follow_count' => $followCount,
        'worker' => $worker ?: ['phase' => 'offline', 'message' => 'Worker has not checked in yet', 'completed' => 0, 'total' => 0, 'updated_at' => null],
    ];
}

function save_worker_status(PDO $pdo, array $data): array
{
    $allowedPhases = ['starting', 'crawling', 'ingesting', 'embedding', 'waiting', 'stopped', 'error'];
    $phase = text_value($data['phase'] ?? '', 20);
    if (!in_array($phase, $allowedPhases, true)) {
        respond(['error' => 'Unknown worker phase.'], 400);
    }
    $completed = max(0, min(100000, (int) ($data['completed'] ?? 0)));
    $total = max(0, min(100000, (int) ($data['total'] ?? 0)));
    $stmt = $pdo->prepare(
        'UPDATE worker_status SET phase = ?, message = ?, completed = ?, total = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
    );
    $stmt->execute([$phase, text_value($data['message'] ?? '', 180), $completed, $total]);
    return ['saved' => true];
}

function list_sources(PDO $pdo): array
{
    $sources = $pdo->query('SELECT name, feed_url AS url FROM sources ORDER BY id')->fetchAll();
    return ['sources' => $sources];
}

function validate_embedding($value): array
{
    if (!is_array($value) || count($value) < 1 || count($value) > 2048) {
        respond(['error' => 'Embedding must be a non-empty numeric vector.'], 400);
    }
    $vector = [];
    foreach ($value as $component) {
        if (!is_numeric($component) || !is_finite((float) $component)) {
            respond(['error' => 'Embedding contains an invalid value.'], 400);
        }
        $vector[] = (float) $component;
    }
    return $vector;
}

function ingest_articles(PDO $pdo, array $articles): array
{
    if (count($articles) > 100) {
        respond(['error' => 'At most 100 articles may be ingested per request.'], 400);
    }
    $pdo->beginTransaction();
    try {
        $saved = 0;
        foreach ($articles as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceUrl = text_value($item['source_url'] ?? '', 2048);
            $sourceName = text_value($item['source_name'] ?? '', 255);
            $url = text_value($item['url'] ?? '', 2048);
            $coverImageUrl = text_value($item['cover_image_url'] ?? '', 2048);
            $title = text_value($item['title'] ?? '', 512);
            $guid = text_value($item['guid'] ?? $url, 2048);
            if ($sourceUrl === '' || $sourceName === '' || $url === '' || $title === '' || $guid === '') {
                continue;
            }

            if ($coverImageUrl !== '') {
                $imageParts = parse_url($coverImageUrl);
                if (!is_array($imageParts) || !in_array(strtolower($imageParts['scheme'] ?? ''), ['http', 'https'], true)) {
                    $coverImageUrl = '';
                }
            }
            $feedHash = hash('sha256', $sourceUrl);
            $stmt = $pdo->prepare(
                'INSERT INTO sources (name, feed_url, feed_url_hash) VALUES (?, ?, ?)
                 ON CONFLICT(feed_url_hash) DO UPDATE SET name = excluded.name, feed_url = excluded.feed_url'
            );
            $stmt->execute([$sourceName, $sourceUrl, $feedHash]);
            $stmt = $pdo->prepare('SELECT id FROM sources WHERE feed_url_hash = ?');
            $stmt->execute([$feedHash]);
            $sourceId = (int) $stmt->fetchColumn();

            $publishedAt = text_value($item['published_at'] ?? '', 19);
            if ($publishedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $publishedAt)) {
                $publishedAt = null;
            }
                $stmt = $pdo->prepare(
                     "INSERT INTO articles (source_id, guid_hash, url, title, summary, content, cover_image_url, published_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                      ON CONFLICT(source_id, guid_hash) DO UPDATE SET
                          url = excluded.url, title = excluded.title, summary = excluded.summary,
                          content = excluded.content, cover_image_url = excluded.cover_image_url,
                          published_at = excluded.published_at,
                        embedding = NULL, embedding_status = 'pending', claimed_at = NULL
                      WHERE articles.url IS NOT excluded.url OR articles.title IS NOT excluded.title
                          OR articles.summary IS NOT excluded.summary OR articles.content IS NOT excluded.content
                          OR articles.cover_image_url IS NOT excluded.cover_image_url
                          OR articles.published_at IS NOT excluded.published_at"
            );
            $stmt->execute([
                $sourceId,
                hash('sha256', $guid),
                $url,
                $title,
                text_value($item['summary'] ?? '', 10000),
                text_value($item['content'] ?? '', 50000),
                $coverImageUrl !== '' ? $coverImageUrl : null,
                $publishedAt,
            ]);
            $saved++;
        }
        $pdo->commit();
        return ['saved' => $saved];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function claim_jobs(PDO $pdo, int $limit): array
{
    $limit = max(1, min(25, $limit));
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE articles SET embedding_status = 'pending', claimed_at = NULL WHERE embedding_status = 'processing' AND claimed_at < datetime('now', '-30 minutes')");
        $pdo->exec("UPDATE interest_jobs SET status = 'pending', claimed_at = NULL WHERE status = 'processing' AND claimed_at < datetime('now', '-30 minutes')");

        $articles = $pdo->query("SELECT id, title, summary, content FROM articles WHERE embedding_status = 'pending' ORDER BY id LIMIT {$limit}")->fetchAll();
        $articleUpdate = $pdo->prepare("UPDATE articles SET embedding_status = 'processing', claimed_at = CURRENT_TIMESTAMP WHERE id = ?");
        foreach ($articles as $article) {
            $articleUpdate->execute([$article['id']]);
        }

        $remaining = $limit - count($articles);
        $interests = [];
        if ($remaining > 0) {
            $interests = $pdo->query("SELECT id, user_id, source_text FROM interest_jobs WHERE status = 'pending' ORDER BY id LIMIT {$remaining}")->fetchAll();
            $interestUpdate = $pdo->prepare("UPDATE interest_jobs SET status = 'processing', claimed_at = CURRENT_TIMESTAMP WHERE id = ?");
            foreach ($interests as $job) {
                $interestUpdate->execute([$job['id']]);
            }
        }
        $pdo->commit();

        $jobs = [];
        foreach ($articles as $article) {
            $jobs[] = [
                'type' => 'article',
                'article_id' => (int) $article['id'],
                'text' => text_value($article['title'] . "\n" . $article['summary'] . "\n" . $article['content'], 50000),
            ];
        }
        foreach ($interests as $job) {
            $jobs[] = [
                'type' => 'interest',
                'job_id' => (int) $job['id'],
                'user_id' => $job['user_id'],
                'text' => $job['source_text'],
            ];
        }
        return ['jobs' => $jobs];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function complete_job(PDO $pdo, array $data): array
{
    $vector = validate_embedding($data['embedding'] ?? null);
    $encoded = json_encode($vector, JSON_THROW_ON_ERROR);
    if (($data['type'] ?? '') === 'article') {
        $articleId = (int) ($data['article_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE articles SET embedding = ?, embedding_status = 'complete', claimed_at = NULL WHERE id = ? AND embedding_status = 'processing'");
        $stmt->execute([$encoded, $articleId]);
        if ($stmt->rowCount() !== 1) {
            respond(['error' => 'Article job was not found or is no longer claimed.'], 409);
        }
        return ['saved' => true];
    }

    if (($data['type'] ?? '') !== 'interest') {
        respond(['error' => 'Unknown job type.'], 400);
    }
    $jobId = (int) ($data['job_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('UPDATE interest_jobs SET status = status WHERE id = ?');
        $lock->execute([$jobId]);
        $stmt = $pdo->prepare('SELECT user_id, status FROM interest_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job || $job['status'] !== 'processing') {
            $pdo->rollBack();
            respond(['error' => 'Interest job was not found or is no longer claimed.'], 409);
        }
        $stmt = $pdo->prepare('SELECT MAX(id) FROM interest_jobs WHERE user_id = ?');
        $stmt->execute([$job['user_id']]);
        $latestJobId = (int) $stmt->fetchColumn();
        if ($latestJobId !== $jobId) {
            $stmt = $pdo->prepare("UPDATE interest_jobs SET status = 'superseded' WHERE id = ?");
            $stmt->execute([$jobId]);
            $pdo->commit();
            return ['saved' => false, 'superseded' => true];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO user_interests (user_id, embedding) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET embedding = excluded.embedding, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$job['user_id'], $encoded]);
        $stmt = $pdo->prepare("UPDATE interest_jobs SET status = 'complete' WHERE id = ?");
        $stmt->execute([$jobId]);
        $pdo->commit();
        return ['saved' => true];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = $method === 'POST' ? request_data() : [];
    $action = text_value($_GET['action'] ?? $data['action'] ?? '', 40);
    $pdo = database();

    if ($method === 'GET' && $action === 'feed') {
        respond(get_feed($pdo, valid_user_id($_GET['user_id'] ?? null)));
    }

    if ($method !== 'POST') {
        respond(['error' => 'Unsupported request.'], 405);
    }

    if (in_array($action, ['ingest', 'claim_jobs', 'complete_job', 'list_sources', 'worker_status'], true)) {
        require_worker();
    }

    switch ($action) {
        case 'add_source':
            $userId = valid_user_id($data['user_id'] ?? null);
            $sourceName = text_value($data['name'] ?? '', 255);
            $feedUrl = text_value($data['feed_url'] ?? '', 2048);
            $urlParts = parse_url($feedUrl);
            if ($sourceName === '' || !filter_var($feedUrl, FILTER_VALIDATE_URL) || !is_array($urlParts)
                || !in_array(strtolower($urlParts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($urlParts['host']) || isset($urlParts['user']) || isset($urlParts['pass'])) {
                respond(['error' => 'Enter a source name and a valid public HTTP(S) RSS URL.'], 400);
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn() >= 500) {
                respond(['error' => 'The source directory is full.'], 429);
            }
            $feedHash = hash('sha256', $feedUrl);
            $stmt = $pdo->prepare(
                'INSERT INTO sources (name, feed_url, feed_url_hash) VALUES (?, ?, ?)
                 ON CONFLICT(feed_url_hash) DO NOTHING'
            );
            $stmt->execute([$sourceName, $feedUrl, $feedHash]);
            $saved = $stmt->rowCount() === 1;
            $stmt = $pdo->prepare('SELECT id, name FROM sources WHERE feed_url_hash = ?');
            $stmt->execute([$feedHash]);
            $source = $stmt->fetch();
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO follows (user_id, source_id) VALUES (?, ?)');
            $stmt->execute([$userId, $source['id']]);
            $interestJobId = $stmt->rowCount() === 1 ? queue_interest_job($pdo, $userId) : null;
            respond(['saved' => true, 'duplicate' => !$saved, 'source' => $source, 'following' => true, 'interest_job_id' => $interestJobId]);

        case 'follow':
            $userId = valid_user_id($data['user_id'] ?? null);
            $sourceId = (int) ($data['source_id'] ?? 0);
            if ($sourceId < 1) {
                respond(['error' => 'A valid source_id is required.'], 400);
            }
            if (!empty($data['following'])) {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO follows (user_id, source_id) VALUES (?, ?)');
                $stmt->execute([$userId, $sourceId]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM follows WHERE user_id = ? AND source_id = ?');
                $stmt->execute([$userId, $sourceId]);
            }
            $jobId = queue_interest_job($pdo, $userId);
            respond(['saved' => true, 'interest_job_id' => $jobId]);

        case 'read':
            $userId = valid_user_id($data['user_id'] ?? null);
            $articleId = (int) ($data['article_id'] ?? 0);
            if ($articleId < 1) {
                respond(['error' => 'A valid article_id is required.'], 400);
            }
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO reads (user_id, article_id) VALUES (?, ?)');
            $stmt->execute([$userId, $articleId]);
            respond(['saved' => true, 'interest_job_id' => queue_interest_job($pdo, $userId)]);

        case 'refresh_interests':
            $userId = valid_user_id($data['user_id'] ?? null);
            $jobId = queue_interest_job($pdo, $userId);
            respond(['queued' => $jobId !== null, 'job_id' => $jobId]);

        case 'ingest':
            respond(ingest_articles($pdo, is_array($data['articles'] ?? null) ? $data['articles'] : []));

        case 'claim_jobs':
            respond(claim_jobs($pdo, (int) ($data['limit'] ?? 15)));

        case 'complete_job':
            respond(complete_job($pdo, $data));

        case 'list_sources':
            respond(list_sources($pdo));

        case 'worker_status':
            respond(save_worker_status($pdo, $data));

        default:
            respond(['error' => 'Unknown action.'], 404);
    }
} catch (Throwable $error) {
    error_log('News API error: ' . $error->getMessage());
    respond(['error' => 'The API could not complete the request.'], 500);
}