<?php

// Destructive only to a random database CREATED BY THIS PROCESS. Requires a
// exclusive database window. Never run this concurrently with suites.
// Calls the real production helpers; proves plaintext SQL roundtrip, not encrypted
// offsite recovery, operational data restoration, or a deployed backup schedule.
function drillProcess(array $arguments, array $environment = []): string
{
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a']], $pipes, null, array_merge(getenv(), $environment), ['bypass_shell' => true]);
    if (! is_resource($process)) { throw new RuntimeException('Could not start drill helper.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Drill helper failed; no successful recovery recorded.'); }
    return $output;
}

$repo = dirname(__DIR__, 2);
$configFile = $repo.'/.local/mysqladmin.cnf';
if (! is_file($configFile)) { fwrite(STDERR, "Missing private local MySQL admin option file.\n"); exit(1); }
$ini = parse_ini_file($configFile, false, INI_SCANNER_RAW);
if (! is_array($ini) || ($ini['host'] ?? '') !== '127.0.0.1' || (string) ($ini['port'] ?? '') !== '3307') {
    fwrite(STDERR, "Drill refuses any connection except 127.0.0.1:3307.\n"); exit(1);
}
foreach (['user', 'password'] as $key) {
    if (! isset($ini[$key]) || ! is_string($ini[$key]) || preg_match('/[\r\n\x00]/', $ini[$key])) {
        fwrite(STDERR, "Invalid local connection configuration.\n"); exit(1);
    }
}
$windows = PHP_OS_FAMILY === 'Windows';
$bin = $repo.'/.local/mysql-8.4.11-winx64/bin/';
$mysql = $windows ? $bin.'mysql.exe' : 'mysql';
$dump = $windows ? $bin.'mysqldump.exe' : 'mysqldump';
$drillDb = 'bizismart_drill_'.bin2hex(random_bytes(12));
$directory = sys_get_temp_dir().'/'.$drillDb;
$createdDatabase = false;
$createdDirectory = false;
$pdo = null;
$environment = [];
$error = null;
try {
    if (file_exists($directory)) { throw new RuntimeException('Disposable drill path already exists.'); }
    if ($windows) {
        drillProcess(['pwsh', '-NoProfile', '-File', $repo.'/infrastructure/prod/prepare-private-directory.ps1', '-Path', $directory]);
    } elseif (! mkdir($directory, 0700)) { throw new RuntimeException('Could not create private drill directory.'); }
    $createdDirectory = true;
    // Credentials are never interpolated into a shell command or emitted in logs.
    $quoted = fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    $connectionFile = $directory.'/client.cnf';
    $client = "[client]\nhost=127.0.0.1\nport=3307\nuser=".$quoted($ini['user'])."\npassword=".$quoted($ini['password'])."\nprotocol=tcp\n";
    if (file_put_contents($connectionFile, $client, LOCK_EX) === false) { throw new RuntimeException('Could not write private drill connection.'); }
    if (! $windows && ! chmod($connectionFile, 0600)) { throw new RuntimeException('Could not protect drill connection.'); }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', $ini['user'], $ini['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $pdo->query('SELECT @@port')->fetchColumn() !== 3307) { throw new RuntimeException('Unexpected connected MySQL runtime.'); }
    // No IF EXISTS: a pre-existing database is never adopted, touched or dropped.
    $pdo->exec("CREATE DATABASE `$drillDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdDatabase = true;
    $pdo->exec("CREATE TABLE `$drillDb`.drill_rows (id INT PRIMARY KEY, label VARCHAR(160), payload VARBINARY(80)) ENGINE=InnoDB");
    $rows = [[1, 'pos-receipt', "\x00\x01\xff"], [2, 'Chai — bei ya leo', "\x80\x00ABC"], [3, 'Mteja 🛒 中文 café', "quoted'\"\\\n"]];
    $insert = $pdo->prepare("INSERT INTO `$drillDb`.drill_rows (id,label,payload) VALUES (?,?,?)");
    foreach ($rows as $row) { $insert->execute($row); }
    $environment = ['MYSQL_DEFAULTS_FILE' => $connectionFile, 'MYSQL_DATABASE' => $drillDb, 'BIZISMART_BACKUP_CONFIRM_DB' => $drillDb,
        'BACKUP_DIR' => $directory.'/backups', 'MYSQLDUMP_BIN' => $dump, 'MYSQL_BIN' => $mysql, 'RETENTION_DAYS' => '30'];
    $command = fn (string $name): array => $windows ? ['pwsh', '-NoProfile', '-File', $repo.'/infrastructure/prod/'.$name.'.ps1'] : ['sh', $repo.'/infrastructure/prod/'.$name.'.sh'];
    drillProcess($command('backup'), $environment);
    $backups = glob($environment['BACKUP_DIR'].'/*.sql');
    if ($backups === false || count($backups) !== 1) { throw new RuntimeException('Expected exactly one completed production-helper backup.'); }
    $pdo->exec("DROP TABLE `$drillDb`.drill_rows");
    drillProcess($command('restore'), array_merge($environment, ['BACKUP_FILE' => $backups[0]]));
    $restored = $pdo->query("SELECT id,label,payload FROM `$drillDb`.drill_rows ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    foreach ($restored as &$row) { $row[0] = (int) $row[0]; }
    unset($row);
    if ($restored !== $rows) { throw new RuntimeException('Exact Unicode/binary row comparison failed.'); }
} catch (Throwable $failure) {
    $error = 'Drill failed; no recovery claim. Review prerequisites and private server diagnostics.';
} finally {
    if ($createdDatabase && $pdo instanceof PDO) {
        try { $pdo->exec("DROP DATABASE `$drillDb`"); }
        catch (Throwable) { $error = 'Drill cleanup failed; inspect the random disposable database with the operator.'; }
    }
    // Only known files in this process-created directory; no recursive deletion.
    if ($createdDirectory) {
        foreach (glob($directory.'/backups/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
        if (is_dir($directory.'/backups')) { rmdir($directory.'/backups'); }
        if (is_file($directory.'/client.cnf')) { unlink($directory.'/client.cnf'); }
        if (is_dir($directory)) { rmdir($directory); }
    }
}
if ($error !== null) { fwrite(STDERR, $error."\n"); exit(1); }
echo "Plaintext production-helper drill passed: all 3 Unicode/binary rows match exactly; own random database and staging files removed.\n";
