<?php
/**
 * db.php — PostgreSQL connection + schema bootstrap
 */

$_env_cfg = is_file(__DIR__ . '/.env.php') ? require __DIR__ . '/.env.php' : [];
$_local_db = file_exists(__DIR__ . '/local-db') ? ($_env_cfg['local_db'] ?? []) : [];
define('DB_HOST', getenv('DB_HOST') ?: ($_local_db['host'] ?? 'localhost'));
define('DB_PORT', getenv('DB_PORT') ?: ($_local_db['port'] ?? '5432'));
define('DB_NAME', getenv('DB_NAME') ?: ($_local_db['name'] ?? 'stagdctc_mnmdb'));
define('DB_USER', getenv('DB_USER') ?: ($_local_db['user'] ?? 'stagdctc_mnm'));
define('DB_PASS', getenv('DB_PASS') ?: ($_local_db['pass'] ?? 'LevataDev2026!'));

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    dbBootstrap($pdo);
    return $pdo;
}

function dbBootstrap(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kv_store (
            bucket  TEXT        NOT NULL,
            key     TEXT        NOT NULL,
            value   JSONB       NOT NULL DEFAULT '\"\"',
            PRIMARY KEY (bucket, key)
        );

        CREATE TABLE IF NOT EXISTS users (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS leads (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS meetings (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS emails (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS templates (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS ms_tokens (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS targets (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS chat_channels (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS chat_messages (
            id         TEXT PRIMARY KEY,
            thread_id  TEXT        NOT NULL,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE INDEX IF NOT EXISTS chat_messages_thread_idx ON chat_messages (thread_id);

        CREATE TABLE IF NOT EXISTS chat_last_read (
            user_id    TEXT        NOT NULL,
            thread_id  TEXT        NOT NULL,
            last_read  TEXT        NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, thread_id)
        );

        CREATE TABLE IF NOT EXISTS activity_pings (
            id         BIGSERIAL   PRIMARY KEY,
            user_id    TEXT        NOT NULL,
            user_name  TEXT        NOT NULL DEFAULT '',
            user_role  TEXT        NOT NULL DEFAULT '',
            page       TEXT        NOT NULL DEFAULT '',
            pinged_at  TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE INDEX IF NOT EXISTS activity_pings_user_idx ON activity_pings (user_id, pinged_at DESC);
        CREATE INDEX IF NOT EXISTS activity_pings_at_idx  ON activity_pings (pinged_at DESC);

        CREATE TABLE IF NOT EXISTS sop_records (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
}

function dbLoadAll(string $table): array {
    $rows = db()->query("SELECT data FROM {$table} ORDER BY updated_at ASC")->fetchAll();
    return array_map(fn($r) => json_decode($r['data'], true), $rows);
}

function dbSaveAll(string $table, array $records, string $idKey = 'id'): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newIds = [];
        foreach ($records as $rec) {
            $id = $rec[$idKey] ?? null;
            if ($id === null) continue;
            $newIds[] = $id;
            $json = json_encode($rec, JSON_UNESCAPED_UNICODE);
            $pdo->prepare("
                INSERT INTO {$table} (id, data, updated_at)
                VALUES (:id, :data::jsonb, now())
                ON CONFLICT (id) DO UPDATE
                    SET data = EXCLUDED.data,
                        updated_at = now()
            ")->execute([':id' => $id, ':data' => $json]);
        }
        if (!empty($newIds)) {
            $placeholders = implode(',', array_map(fn($i) => ":del{$i}", array_keys($newIds)));
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id NOT IN ({$placeholders})");
            foreach ($newIds as $i => $id) $stmt->bindValue(":del{$i}", $id);
            $stmt->execute();
        } else {
            $pdo->exec("DELETE FROM {$table}");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function kvGet(string $bucket, string $key, $default = null) {
    $stmt = db()->prepare("SELECT value FROM kv_store WHERE bucket=:b AND key=:k");
    $stmt->execute([':b' => $bucket, ':k' => $key]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    $v = json_decode($row['value'], true);
    return $v !== null ? $v : $default;
}

function kvSet(string $bucket, string $key, $value): void {
    db()->prepare("
        INSERT INTO kv_store (bucket, key, value)
        VALUES (:b, :k, :v::jsonb)
        ON CONFLICT (bucket, key) DO UPDATE SET value = EXCLUDED.value
    ")->execute([':b' => $bucket, ':k' => $key, ':v' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function dbLoadMessages(string $threadId): array {
    $stmt = db()->prepare("SELECT data FROM chat_messages WHERE thread_id=:t ORDER BY (data->>'sent_at') ASC");
    $stmt->execute([':t' => $threadId]);
    return array_map(fn($r) => json_decode($r['data'], true), $stmt->fetchAll());
}

function dbSaveMessages(string $threadId, array $messages): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newIds = [];
        foreach ($messages as $msg) {
            $id = $msg['id'] ?? null;
            if ($id === null) continue;
            $newIds[] = $id;
            $pdo->prepare("
                INSERT INTO chat_messages (id, thread_id, data, updated_at)
                VALUES (:id, :t, :data::jsonb, now())
                ON CONFLICT (id) DO UPDATE
                    SET data = EXCLUDED.data,
                        thread_id = EXCLUDED.thread_id,
                        updated_at = now()
            ")->execute([':id' => $id, ':t' => $threadId, ':data' => json_encode($msg, JSON_UNESCAPED_UNICODE)]);
        }
        if (!empty($newIds)) {
            $placeholders = implode(',', array_map(fn($i) => ":del{$i}", array_keys($newIds)));
            $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE thread_id=:t AND id NOT IN ({$placeholders})");
            $stmt->bindValue(':t', $threadId);
            foreach ($newIds as $i => $id) $stmt->bindValue(":del{$i}", $id);
            $stmt->execute();
        } else {
            $pdo->prepare("DELETE FROM chat_messages WHERE thread_id=:t")->execute([':t' => $threadId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function dbGetLastRead(string $userId, string $threadId): string {
    $stmt = db()->prepare("SELECT last_read FROM chat_last_read WHERE user_id=:u AND thread_id=:t");
    $stmt->execute([':u' => $userId, ':t' => $threadId]);
    $row = $stmt->fetch();
    return $row ? $row['last_read'] : '';
}

function dbSetLastRead(string $userId, string $threadId, string $lastRead): void {
    db()->prepare("
        INSERT INTO chat_last_read (user_id, thread_id, last_read)
        VALUES (:u, :t, :lr)
        ON CONFLICT (user_id, thread_id) DO UPDATE SET last_read = EXCLUDED.last_read
    ")->execute([':u' => $userId, ':t' => $threadId, ':lr' => $lastRead]);
}
