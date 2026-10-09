<?php

// Local development setup only. Credentials and data stay in ignored .local/.
$root = dirname(__DIR__, 2);
$local = $root.'/.local';
$mode = $argv[1] ?? 'verify';

function replaceEnvironment(string $path, array $values): void
{
    $content = file_get_contents($path);
    foreach ($values as $key => $value) {
        $line = $key.'='.$value;
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $content = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content)
            : rtrim($content)."\n".$line."\n";
    }
    file_put_contents($path, $content);
}

try {
    if ($mode === 'prepare') {
        if (is_file($local.'/mysql-credentials.json')) {
            throw new RuntimeException('Credentials already exist; do not reinitialise an existing database.');
        }
        if (! is_file($root.'/backend/.env')) {
            throw new RuntimeException('Set up backend/.env and its application key first.');
        }
        $credentials = [
            'root' => bin2hex(random_bytes(32)),
            'development' => bin2hex(random_bytes(32)),
            'testing' => bin2hex(random_bytes(32)),
        ];
        file_put_contents($local.'/mysql-credentials.json', json_encode($credentials, JSON_PRETTY_PRINT));
        $runtime = str_replace('\\', '/', $local);
        $ini = "[mysqld]\nbasedir=$runtime/mysql-8.4.11-winx64\ndatadir=$runtime/mysql-data\n";
        $ini .= "bind-address=127.0.0.1\nport=3307\nmysqlx=0\ncharacter-set-server=utf8mb4\n";
        $ini .= "collation-server=utf8mb4_unicode_ci\ndefault-storage-engine=InnoDB\ndefault-time-zone=+00:00\n";
        $ini .= "innodb-buffer-pool-size=128M\nlog-error=$runtime/mysql-error.log\npid-file=$runtime/mysql.pid\n";
        file_put_contents($local.'/mysql.ini', $ini);
        file_put_contents($local.'/mysql-bootstrap.ini', $ini."init-file=$runtime/mysql-init.sql\n");
        $sql = "ALTER USER 'root'@'localhost' IDENTIFIED BY '{$credentials['root']}';\n";
        $sql .= "CREATE DATABASE bizismart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE DATABASE bizismart_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        foreach (['development' => ['bizismart', 'bizismart'], 'testing' => ['bizismart_test', 'bizismart_test']] as $key => [$user, $database]) {
            $sql .= "CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '{$credentials[$key]}';\n";
            $sql .= "GRANT ALL PRIVILEGES ON $database.* TO '$user'@'127.0.0.1';\n";
        }
        file_put_contents($local.'/mysql-init.sql', $sql);
        file_put_contents($local.'/mysqladmin.cnf', "[client]\nhost=127.0.0.1\nport=3307\nuser=root\npassword={$credentials['root']}\n");
        echo "Local configuration prepared; credentials were not printed.\n";
        exit(0);
    }

    $credentials = json_decode(file_get_contents($local.'/mysql-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['development' => ['bizismart', 'bizismart'], 'testing' => ['bizismart_test', 'bizismart_test']] as $key => [$user, $database]) {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=$database;charset=utf8mb4", $user, $credentials[$key], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (! str_starts_with($version, '8.4.')) {
            throw new RuntimeException('Unexpected database version.');
        }
        $engine = $pdo->query('SELECT @@default_storage_engine')->fetchColumn();
        if ($engine !== 'InnoDB') {
            throw new RuntimeException('Unexpected storage engine.');
        }
        if ($pdo->query('SELECT @@session.time_zone')->fetchColumn() !== '+00:00') {
            throw new RuntimeException('MySQL session timezone must be UTC.');
        }
        // A temporary table verifies transactional writes without touching application data.
        $pdo->exec('CREATE TEMPORARY TABLE setup_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO setup_probe VALUES (1)');
        $pdo->rollBack();
        if ((int) $pdo->query('SELECT COUNT(*) FROM setup_probe')->fetchColumn() !== 0) {
            throw new RuntimeException('Transaction rollback failed.');
        }
        $other = $key === 'development' ? 'bizismart_test' : 'bizismart';
        try {
            $pdo->exec("USE $other");
            throw new RuntimeException('Database account can access the other environment.');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1044) {
                throw $exception;
            }
        }
        echo "$database: MySQL $version, UTC, InnoDB rollback and separate database permissions verified.\n";
    }

    if ($mode === 'configure') {
        $common = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307'];
        replaceEnvironment($root.'/backend/.env', $common + ['DB_DATABASE' => 'bizismart', 'DB_USERNAME' => 'bizismart', 'DB_PASSWORD' => $credentials['development']]);
        $testEnv = $root.'/backend/.env.testing';
        if (! is_file($testEnv)) {
            copy($root.'/backend/.env', $testEnv);
        }
        replaceEnvironment($testEnv, $common + [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'bizismart_test',
            'DB_USERNAME' => 'bizismart_test', 'DB_PASSWORD' => $credentials['testing'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        echo "Laravel development/test environment files configured privately.\n";
    } elseif ($mode !== 'verify') {
        throw new RuntimeException('Use prepare, configure or verify.');
    }
} catch (Throwable $exception) {
    // Do not expose credentials in connection exception traces.
    fwrite(STDERR, 'MySQL setup failed ('.get_class($exception).', code '.$exception->getCode()."). Check the local server/configuration.\n");
    exit(1);
}
