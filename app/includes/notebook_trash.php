<?php
const BN_TRASH_TYPES = [
    'note' => ['notes', 'title'],
    'file' => ['notebook_media', 'name'],
    'course' => ['courses', 'course_name'],
];

function bnTrashChange(PDO $db, int $user, string $kind, int $id, bool $restore): bool
{
    if (!isset(BN_TRASH_TYPES[$kind]) || $id < 1) {
        return false;
    }
    [$table] = BN_TRASH_TYPES[$kind];
    $assignment = $restore ? 'NULL' : 'UTC_TIMESTAMP()';
    $condition = $restore
        ? 'trashed_at > UTC_TIMESTAMP() - INTERVAL 30 DAY'
        : 'trashed_at IS NULL';
    // Keep the original modification date when moving or restoring a note.
    $preserveDate = $kind === 'note' ? ', updated_at = updated_at' : '';
    $query = $db->prepare("UPDATE {$table} SET trashed_at = {$assignment}{$preserveDate}
        WHERE id = ? AND user_id = ? AND {$condition}");
    $query->execute([$id, $user]);
    return $query->rowCount() === 1;
}

function bnTrashList(PDO $db, int $user): array
{
    $parts = [];
    foreach (BN_TRASH_TYPES as $kind => [$table, $label]) {
        $parts[] = "SELECT '{$kind}' AS kind, id, CONVERT({$label} USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name,
            UNIX_TIMESTAMP() + TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), trashed_at + INTERVAL 30 DAY) AS expiresAt
            FROM {$table} WHERE user_id = ? AND trashed_at > UTC_TIMESTAMP() - INTERVAL 30 DAY";
    }
    $query = $db->prepare(implode(' UNION ALL ', $parts) . ' ORDER BY expiresAt DESC, id DESC');
    $query->execute(array_fill(0, count($parts), $user));
    return $query->fetchAll();
}

function bnTrashCount(PDO $db, int $user): int
{
    $count = 0;
    foreach (BN_TRASH_TYPES as [$table]) {
        $query = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ? AND trashed_at IS NOT NULL");
        $query->execute([$user]);
        $count += (int)$query->fetchColumn();
    }
    return $count;
}

function bnTrashQueuePaths(PDO $db, array $paths): array
{
    $insert = $db->prepare('INSERT IGNORE INTO notebook_trash_files (file_path) VALUES (?)');
    $queued = [];
    foreach (array_unique($paths) as $path) {
        if (is_string($path) && preg_match('~^uploads/notes/[^/\\\\:\x00]+$~D', $path)
            && !in_array(basename($path), ['.', '..'], true)) {
            $insert->execute([$path]);
            $queued[] = $path;
        }
    }
    return $queued;
}

function bnTrashDetachFile(PDO $db, int $user, array $paths): void
{
    foreach (array_unique(array_filter($paths)) as $path) {
        $query = $db->prepare('SELECT id FROM notes WHERE user_id = ? AND file_path = ? FOR UPDATE');
        $query->execute([$user, $path]);
        $legacyNotes = $query->fetchAll(PDO::FETCH_COLUMN);

        $query = $db->prepare('SELECT s.id, s.note_id FROM notebook_sources s
            JOIN notes n ON n.id = s.note_id AND n.user_id = s.user_id
            WHERE s.user_id = ? AND s.file_path = ? FOR UPDATE');
        $query->execute([$user, $path]);
        $sources = $query->fetchAll();

        $annotations = $db->prepare('DELETE FROM notebook_pdf_annotations WHERE user_id = ? AND note_id = ? AND source_id = ?');
        foreach ($legacyNotes as $id) {
            $annotations->execute([$user, $id, 'legacy-' . $id]);
        }
        $clear = $db->prepare('UPDATE notes SET file_path = NULL, file_name = NULL, file_type = NULL,
            updated_at = updated_at WHERE user_id = ? AND file_path = ?');
        $clear->execute([$user, $path]);

        $delete = $db->prepare('DELETE FROM notebook_sources WHERE id = ? AND user_id = ?');
        foreach ($sources as $source) {
            $annotations->execute([$user, $source['note_id'], 'source-' . $source['id']]);
            $delete->execute([$source['id'], $user]);
        }
    }
}

function bnTrashPurgeItem(PDO $db, string $kind, int $id, ?int $owner = null): ?array
{
    [$table] = BN_TRASH_TYPES[$kind];
    $condition = $owner === null
        ? 'trashed_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY'
        : 'user_id = ? AND trashed_at IS NOT NULL';
    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT * FROM {$table} WHERE id = ? AND {$condition} FOR UPDATE");
        $query->execute($owner === null ? [$id] : [$id, $owner]);
        $row = $query->fetch();
        if (!$row) {
            $db->commit();
            return null;
        }
        $queued = [];
        if ($kind === 'note') {
            $query = $db->prepare('SELECT file_path FROM notebook_sources WHERE note_id = ?');
            $query->execute([$id]);
            $queued = bnTrashQueuePaths($db, array_merge([$row['file_path']], $query->fetchAll(PDO::FETCH_COLUMN)));
        } elseif ($kind === 'file') {
            $paths = [$row['file_path'], $row['canonical_path']];
            bnTrashDetachFile($db, (int)$row['user_id'], $paths);
            $queued = bnTrashQueuePaths($db, $paths);
        }
        $query = $db->prepare("DELETE FROM {$table} WHERE id = ?");
        $query->execute([$id]);
        $db->commit();
        return $queued;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function bnTrashCleanFiles(PDO $db, ?array $paths = null): void
{
    $root = realpath(__DIR__ . '/../uploads/notes');
    if ($root === false) {
        return;
    }
    $references = $db->prepare('SELECT (
        EXISTS (SELECT 1 FROM notes WHERE file_path = ?)
        OR EXISTS (SELECT 1 FROM notebook_sources WHERE file_path = ?)
        OR EXISTS (SELECT 1 FROM notebook_media WHERE file_path = ? OR canonical_path = ?)
    )');
    $remove = $db->prepare('DELETE FROM notebook_trash_files WHERE file_path = ?');
    $paths ??= $db->query('SELECT file_path FROM notebook_trash_files ORDER BY queued_at LIMIT 1000')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_unique($paths) as $path) {
        $references->execute([$path, $path, $path, $path]);
        if (!$references->fetchColumn()) {
            $file = $root . DIRECTORY_SEPARATOR . basename($path);
            $resolved = realpath($file);
            if ($resolved !== false && dirname($resolved) === $root && !is_link($file)
                && is_file($file) && !@unlink($file)) {
                continue; // Retry when permissions or an open file no longer block removal.
            }
        }
        $remove->execute([$path]);
    }
}

function bnTrashPurge(PDO $db): int
{
    $count = 0;
    foreach (BN_TRASH_TYPES as $kind => [$table]) {
        $ids = $db->query("SELECT id FROM {$table}
            WHERE trashed_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY trashed_at LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $count += (int)(bnTrashPurgeItem($db, $kind, (int)$id) !== null);
        }
    }
    bnTrashCleanFiles($db);
    return $count;
}

function bnTrashEmpty(PDO $db, int $user): int
{
    $count = 0;
    foreach (BN_TRASH_TYPES as $kind => [$table]) {
        $query = $db->prepare("SELECT id FROM {$table} WHERE user_id = ? AND trashed_at IS NOT NULL ORDER BY id");
        $query->execute([$user]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $paths = bnTrashPurgeItem($db, $kind, (int)$id, $user);
            if ($paths !== null) {
                ++$count;
                bnTrashCleanFiles($db, $paths);
            }
        }
    }
    return $count;
}
