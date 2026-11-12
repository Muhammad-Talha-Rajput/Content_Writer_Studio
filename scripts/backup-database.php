<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if (! $app->environment('local') || config('database.default') !== 'mysql'
    || $connection['database'] !== 'grands_digital_studio' || $connection['host'] !== '127.0.0.1') {
    fwrite(STDERR, "This backup helper is restricted to the local Content Studio database.\n");
    exit(1);
}
foreach ([$root.'/backups'] as $directory) {
    if (! is_dir($directory)) mkdir($directory, 0770, true);
}
$output = $root.'/backups/grands_digital_studio_'.now()->setTimezone('Asia/Karachi')->format('Ymd_His').'.sql';
$temporaryOutput = $output.'.partial';
$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$stream = null;
try {
    $stream = fopen($temporaryOutput, 'xb');
    if (! $stream) throw new RuntimeException('Cannot create the database backup.');
    $write = function (string $text) use ($stream) {
        if (fwrite($stream, $text) !== strlen($text)) throw new RuntimeException('Database backup write failed.');
    };
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $write("-- Grands Digital local database backup\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nCREATE DATABASE IF NOT EXISTS `grands_digital_studio` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `grands_digital_studio`;\n");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $identifier = '`'.str_replace('`', '``', $table).'`';
        $create = $pdo->query('SHOW CREATE TABLE '.$identifier)->fetch(PDO::FETCH_NUM)[1];
        $write("\nDROP TABLE IF EXISTS ".$identifier.";\n".$create.";\n");
        $statement = $pdo->query('SELECT * FROM '.$identifier);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(fn ($column) => '`'.str_replace('`', '``', $column).'`', array_keys($row));
            $values = array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row));
            $write('INSERT INTO '.$identifier.' ('.implode(',', $columns).') VALUES ('.implode(',', $values).");\n");
        }
    }
    $pdo->commit();
    $write("\nSET FOREIGN_KEY_CHECKS=1;\n-- Backup completed successfully\n");
    fflush($stream); fclose($stream); $stream = null;
    if (! rename($temporaryOutput, $output)) throw new RuntimeException('Cannot finalize the backup.');
    $hash = hash_file('sha256', $output);
    file_put_contents($output.'.sha256', $hash.'  '.basename($output)."\n");
    echo json_encode(['backup' => $output, 'bytes' => filesize($output), 'sha256' => $hash], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
} finally {
    if (is_resource($stream)) fclose($stream);
}
