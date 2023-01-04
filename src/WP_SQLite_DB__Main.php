<?php

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
class WP_SQLite_DB__Main
{
    public static function bootstrap()
    {
        self::define_constants();
        self::remove_site_health_checks();
        $GLOBALS['wpdb'] = new WP_SQLite_DB\wpsqlitedb();
    }

    private static function define_constants()
    {
        /**
         * FQDBDIR is a directory where the sqlite database file is placed.
         * If DB_DIR is defined, it is used as FQDBDIR.
         */
        if (defined('DB_DIR')) {
            if (substr(DB_DIR, -1, 1) != '/') {
                define('FQDBDIR', DB_DIR . '/');
            } else {
                define('FQDBDIR', DB_DIR);
            }
        } else {
            if (defined('WP_CONTENT_DIR')) {
                define('FQDBDIR', WP_CONTENT_DIR . '/database/');
            } else {
                define('FQDBDIR', ABSPATH . 'wp-content/database/');
            }
        }

        /**
         * FQDB is a database file name. If DB_FILE is defined, it is used
         * as FQDB.
         */
        if (defined('DB_FILE')) {
            define('FQDB', FQDBDIR . DB_FILE);
        } else {
            define('FQDB', FQDBDIR . '.ht.sqlite');
        }
    }

    private static function remove_site_health_checks()
    {
        add_filter('site_status_tests', function ($tests) {
            unset($tests['direct']['sql_server'], $tests['direct']['utf8mb4_support']);

            return $tests;
        });
    }
}
