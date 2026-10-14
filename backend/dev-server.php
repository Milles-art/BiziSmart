<?php

// Local PHP server only; reuse Laravel's router and keep code edits immediately visible.
if (PHP_SAPI !== 'cli-server') {
    throw new RuntimeException('Start this router with the local PHP development server.');
}
$previousDirectory = getcwd();
if (! chdir(__DIR__.'/public')) {
    throw new RuntimeException('The public directory is unavailable.');
}
try {
    return require __DIR__.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
} finally {
    chdir($previousDirectory);
}
