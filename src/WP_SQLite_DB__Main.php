<?php
class WP_SQLite_DB__Main
{
    public static function bootstrap()
    {
        self::defineConstants();
        self::checkRequirements();
        self::alterSiteHealthChecks();

        $GLOBALS['wpdb'] = new WP_SQLite_DB\wpsqlitedb();
    }

    private static function defineConstants()
    {
        /**
         * FQDBDIR is a directory where the sqlite database file is placed.
         * If DB_DIR is defined, it is used as FQDBDIR.
         */
        if (defined('DB_DIR')) {
            define('FQDBDIR', trailingslashit(DB_DIR));
        } elseif (defined('WP_CONTENT_DIR')) {
            define('FQDBDIR', WP_CONTENT_DIR . '/database/');
        } else {
            define('FQDBDIR', ABSPATH . 'wp-content/database/');
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

    private static function checkRequirements()
    {
        if (!extension_loaded('pdo')) {
            wp_die(
                new WP_Error(
                    'pdo_not_loaded',
                    sprintf(
                        '<h1>%1$s</h1><p>%2$s</p>',
                        'PHP PDO Extension is not loaded',
                        'Your PHP installation appears to be missing the PDO extension which is required for this version of WordPress and the type of database you have specified.'
                    )
                ),
                'PHP PDO Extension is not loaded.'
            );
        }

        if (!extension_loaded('pdo_sqlite')) {
            wp_die(
                new WP_Error(
                    'pdo_driver_not_loaded',
                    sprintf(
                        '<h1>%1$s</h1><p>%2$s</p>',
                        'PDO Driver for SQLite is missing',
                        'Your PHP installation appears not to have the right PDO drivers loaded. These are required for this version of WordPress and the type of database you have specified.'
                    )
                ),
                'PDO Driver for SQLite is missing.'
            );
        }
    }

    private static function alterSiteHealthChecks()
    {
        add_filter('site_status_tests', function ($tests) {
            unset(
                $tests['direct']['sql_server'],
                $tests['direct']['utf8mb4_support'],
                $tests['direct']['persistent_object_cache']
            );

            return $tests;
        });

        add_filter('debug_information', function ($info) {
            $info['wp-database']['fields']['database_type'] = [
                'label' => __('Database type'),
                'value' => 'SQLite',
            ];

            $info['wp-database']['fields']['database_file'] = [
                'label'   => __('Database file'),
                'value'   => FQDB,
                'private' => true,
            ];

            $info['wp-database']['fields']['database_size'] = [
                'label' => __('Database size'),
                'value' => size_format(filesize(FQDB)),
            ];

            unset($info['wp-database']['fields']['database_host']);
            unset($info['wp-database']['fields']['database_user']);
            unset($info['wp-database']['fields']['database_name']);
            unset($info['wp-database']['fields']['database_charset']);
            unset($info['wp-database']['fields']['database_collate']);
            unset($info['wp-database']['fields']['max_connections']);

            return $info;
        });
    }
}
