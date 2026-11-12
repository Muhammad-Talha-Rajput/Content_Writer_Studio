<?php

// Local XAMPP setup only. This script never changes an existing user's password.

declare(strict_types=1);

$root = dirname(__DIR__);
$backend = $root.'/backend';

if (! is_file($backend.'/vendor/autoload.php')) {
    fwrite(STDERR, "Install the backend Composer dependencies before running local setup.\n");
    exit(1);
}

require $backend.'/vendor/autoload.php';

function setLocalValue(string $path, string $key, string $value): void
{
    $contents = file_get_contents($path);
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $contents = preg_match($pattern, $contents)
        ? preg_replace_callback($pattern, fn () => $line, $contents)
        : rtrim($contents)."\n".$line."\n";

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write the local environment file.');
    }
}

try {
    $envPath = $backend.'/.env';
    if (! is_file($envPath) && ! copy($backend.'/.env.example', $envPath)) {
        throw new RuntimeException('Cannot create the backend environment file.');
    }

    $values = Dotenv\Dotenv::parse(file_get_contents($envPath));

    // Upgrade only Laravel's unconfigured SQLite starter settings.
    if (($values['DB_CONNECTION'] ?? '') === 'sqlite') {
        if (! empty($values['DB_DATABASE'])) {
            throw new RuntimeException('An existing SQLite database is configured. Local setup will not replace it.');
        }
        foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'grands_digital_studio', 'DB_USERNAME' => 'grands_studio', 'DB_PASSWORD' => ''] as $key => $value) {
            setLocalValue($envPath, $key, $value);
        }
    }
    $values = Dotenv\Dotenv::parse(file_get_contents($envPath));
    if (($values['DB_CONNECTION'] ?? '') !== 'mysql'
        || ($values['DB_HOST'] ?? '') !== '127.0.0.1'
        || ($values['DB_PORT'] ?? '') !== '3306'
        || ($values['DB_DATABASE'] ?? '') !== 'grands_digital_studio'
        || ($values['DB_USERNAME'] ?? '') !== 'grands_studio') {
        throw new RuntimeException('Local setup is restricted to the grands_digital_studio database and grands_studio user on 127.0.0.1:3306. Configure other environments manually.');
    }

    if (($values['APP_NAME'] ?? '') === 'Laravel') {
        setLocalValue($envPath, 'APP_NAME', '"Grands Digital Content Studio"');
    }
    if (($values['APP_URL'] ?? '') === 'http://localhost') {
        setLocalValue($envPath, 'APP_URL', 'http://127.0.0.1:8000');
    }
    foreach (['FRONTEND_URL' => 'http://127.0.0.1:5173',
        'CORS_ALLOWED_ORIGINS' => 'http://127.0.0.1:5173,http://localhost:5173',
        'OLLAMA_URL' => 'http://127.0.0.1:11434', 'OLLAMA_MODEL' => 'qwen2.5:7b'] as $key => $value) {
        if (empty($values[$key])) {
            setLocalValue($envPath, $key, $value);
        }
    }
    if (empty($values['APP_KEY'])) {
        setLocalValue($envPath, 'APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
    }
    if (empty($values['DB_PASSWORD'])) {
        setLocalValue($envPath, 'DB_PASSWORD', bin2hex(random_bytes(24)));
    }
    $values = Dotenv\Dotenv::parse(file_get_contents($envPath));

    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
    $dsn = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
    $admin = new PDO($dsn, getenv('DB_ADMIN_USERNAME') ?: 'root', getenv('DB_ADMIN_PASSWORD') ?: '', $options);
    $admin->exec('CREATE DATABASE IF NOT EXISTS `grands_digital_studio` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $account = $admin->quote('grands_studio').'@'.$admin->quote('127.0.0.1');
    $admin->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$admin->quote($values['DB_PASSWORD']));
    $admin->exec('GRANT ALL PRIVILEGES ON `grands_digital_studio`.* TO '.$account);

    $app = new PDO($dsn.';dbname=grands_digital_studio', 'grands_studio', $values['DB_PASSWORD'], $options);
    $app->query('SELECT 1');

    $frontendEnv = $root.'/frontend/.env';
    if (! is_file($frontendEnv) && ! copy($root.'/frontend/.env.example', $frontendEnv)) {
        throw new RuntimeException('Cannot create the frontend environment file.');
    }

    echo "Local environments configured.\n";
    echo "Database grands_digital_studio is reachable using the dedicated grands_studio account.\n";
    echo "Application key and database password are stored only in backend/.env.\n";
    echo "Next: run php artisan migrate from the backend directory.\n";
} catch (Throwable $exception) {
    // Connection exception messages can contain credentials; print only a safe explanation.
    if ($exception instanceof PDOException) {
        fwrite(STDERR, "Database setup failed. Check that XAMPP MySQL is running, that the administrator credentials are correct, and that an existing grands_studio account matches backend/.env. No existing user's password was changed.\n");
    } else {
        fwrite(STDERR, $exception->getMessage()."\n");
    }
    exit(1);
}
