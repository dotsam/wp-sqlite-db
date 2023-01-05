<?php

use ClassPreloader\ClassLoader;
use ClassPreloader\ClassLoader\Config;
use WP_SQLite_DB\ObjectArray;
use WP_SQLite_DB\PDOEngine;
use WP_SQLite_DB\QueryRewriter;
use WP_SQLite_DB\QueryRewriterAlter;
use WP_SQLite_DB\QueryRewriterCreate;
use WP_SQLite_DB\wpsqlitedb;

$config = new Config();
// Exclude all WordPress core classes (e.g. wpdb) from being included.
$config->addExclusiveFilter('#/wordpress/#');
// Based on ClassLoader::getIncludes.
$loader = new ClassLoader();
call_user_func(function (ClassLoader $loader) {
    include dirname(__DIR__) . '/vendor/autoload.php';
    $loader->register();
    // Load files and classes in the order they should be written.

    // Load wpsqlitedb manually to avoid
    $loader->loadClass(wpsqlitedb::class);
    define('FQDB', '');
    define('FQDBDIR', dirname(__DIR__) . '/tests/test-wp-content/');
    new PDOEngine();
    new QueryRewriter();
    new QueryRewriterCreate();
    new QueryRewriterAlter();
    new ObjectArray([]);
    new WP_SQLite_DB__Main();
}, $loader);
$loader->unregister();

foreach ($loader->getFilenames() as $file) {
    $config->addFile($file);
}

$config->addFile(dirname(__DIR__) . '/partials/bootstrap.php');

return $config;
