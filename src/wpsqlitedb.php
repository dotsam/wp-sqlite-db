<?php

namespace WP_SQLite_DB;

use PDO;

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * This class extends wpdb and replaces it.
 *
 * It also rewrites some methods that use mysql specific functions.
 */
class wpsqlitedb extends \wpdb
{
    /**
     * Database Handle
     *
     * @var PDOEngine
     */
    protected $dbh;

    /**
     * Constructor
     *
     * Unlike wpdb, no credentials are needed.
     */
    public function __construct()
    {
        parent::__construct('', '', FQDB, 'sqlite');
    }

    /**
     * Method to set character set for the database.
     *
     * This overrides wpdb::set_charset(), only to dummy out the MySQL function.
     *
     * @param resource $dbh The resource given by mysql_connect
     * @param string|null $charset Optional. The character set. Default null.
     * @param string|null $collate Optional. The collation. Default null.
     *
     * @see wpdb::set_charset()
     */
    public function set_charset($dbh, $charset = null, $collate = null)
    {
    }

    /**
     * Method to dummy out wpdb::set_sql_mode()
     *
     * @param array $modes Optional. A list of SQL modes to set.
     *
     * @see wpdb::set_sql_mode()
     */
    public function set_sql_mode($modes = [])
    {
    }

    /**
     * Method to select the database connection.
     *
     * This overrides wpdb::select(), only to dummy out the MySQL function.
     *
     * @param string $db MySQL database name
     * @param resource|null $dbh Optional link identifier.
     *
     * @see wpdb::select()
     */
    public function select($db, $dbh = null)
    {
        $this->ready = true;
    }

    /**
     * Method to escape characters.
     *
     * This overrides wpdb::_real_escape() to avoid using mysql_real_escape_string().
     *
     * @param string $string to escape
     *
     * @return string escaped
     * @see    wpdb::_real_escape()
     */
    public function _real_escape($string)
    {
        return addslashes($string);
    }

    /**
     * Method to dummy out wpdb::esc_like() function.
     *
     * WordPress 4.0.0 introduced esc_like() function that adds backslashes to %,
     * underscore and backslash, which is not interpreted as escape character
     * by SQLite. So we override it and dummy out this function.
     *
     * @param string $text The raw text to be escaped. The input typed by the user should have no
     *                     extra or deleted slashes.
     *
     * @return string Text in the form of a LIKE phrase. The output is not SQL safe. Call $wpdb::prepare()
     *                or real_escape next.
     */
    public function esc_like($text)
    {
        return $text;
    }

    /**
     * Method to put out the error message.
     *
     * This overrides wpdb::print_error(), for we can't use the parent class method.
     *
     * @param string $str The error to display
     *
     * @return bool False if the showing of errors is disabled.
     * @see    wpdb::print_error()
     *
     * @global array $EZSQL_ERROR Stores error information of query and error string
     */
    public function print_error($str = '')
    {
        global $EZSQL_ERROR;

        if (!$str) {
            $err = $this->dbh->get_error_message();
            $str = $err;
            // $str = strip_tags($err);
        }
        $EZSQL_ERROR[] = ['query' => $this->last_query, 'error_str' => $str];

        if ($this->suppress_errors) {
            return false;
        }

        wp_load_translations_early();

        if ($caller = $this->get_caller()) {
            $error_str = sprintf(
                __('WordPress database error %1$s for query %2$s made by %3$s'),
                $str,
                $this->last_query,
                $caller
            );
        } else {
            $error_str = sprintf(__('WordPress database error %1$s for query %2$s'), $str, $this->last_query);
        }

        error_log($error_str);

        if (!$this->show_errors) {
            return false;
        }

        if (is_multisite()) {
            $msg = sprintf(
                "%s [%s]\n%s\n",
                __('WordPress database error:'),
                $str,
                $this->last_query
            );
            if (defined('ERRORLOGFILE')) {
                error_log($msg, 3, \ERRORLOGFILE);
            }
            if (defined('DIEONDBERROR')) {
                wp_die($msg);
            }
        } else {
            $str   = htmlspecialchars($str, ENT_QUOTES);
            $query = htmlspecialchars($this->last_query, ENT_QUOTES);

            printf(
                '<div id="error"><p class="wpdberror"><strong>%s</strong> [%s]<br /><code>%s</code></p></div>',
                __('WordPress database error:'),
                $str,
                $query
            );
        }
    }

    /**
     * Method to flush cached data.
     *
     * This overrides wpdb::flush(). This is not necessarily overridden, because
     * $result will never be resource.
     *
     * @see wpdb::flush
     */
    public function flush()
    {
        $this->last_result   = [];
        $this->col_info      = null;
        $this->last_query    = null;
        $this->rows_affected = 0;
        $this->num_rows      = 0;
        $this->last_error    = '';
        $this->result        = null;
    }

    /**
     * Method to do the database connection.
     *
     * This overrides wpdb::db_connect() to avoid using MySQL function.
     *
     * @param bool $allow_bail
     *
     * @see wpdb::db_connect()
     */
    public function db_connect($allow_bail = true)
    {
        $this->init_charset();
        $this->dbh   = new PDOEngine();

        if ($this->dbh->is_error) {
            $this->last_error = $this->dbh->get_error_message();
            $this->dbh = false;
        }

        if (!$this->dbh && $allow_bail) {
            wp_load_translations_early();

            // Load custom DB error template, if present.
            if (file_exists(WP_CONTENT_DIR . '/db-error.php')) {
                require_once WP_CONTENT_DIR . '/db-error.php';
                die();
            }

            $message = '<h1>' . __('Error establishing a database connection') . "</h1>\n";
            $message .= "<p>Could not open SQLite Database</p>\n";
            $message .= $this->last_error;
            $this->bail($message, 'db_connect_fail');

            return false;
        } elseif ($this->dbh) {
            $this->has_connected = true;
            $this->ready = true;

            return true;
        }

        return false;
    }

    /**
     * Method to dummy out wpdb::check_connection()
     *
     * @param bool $allow_bail
     *
     * @return bool
     */
    public function check_connection($allow_bail = true)
    {
        return true;
    }

    /**
     * Method to execute the query.
     *
     * This overrides wpdb::query(). In fact, this method does all the database
     * access jobs.
     *
     * @param string $query Database query
     *
     * @return int|false Number of rows affected/selected or false on error
     * @see    wpdb::query()
     */
    public function query($query)
    {
        if (!$this->ready) {
            return false;
        }

        /**
         * Filters the database query.
         *
         * Some queries are made before the plugins have been loaded,
         * and thus cannot be filtered with this method.
         *
         * @since 2.1.0
         *
         * @param string $query Database query.
         */
        $query = apply_filters('query', $query);

        $this->flush();

        // Log how the function was called.
        $this->func_call = "\$db->query(\"$query\")";

        // Keep track of the last query for debug.
        $this->last_query = $query;

        $this->_do_query($query);

        if ($this->last_error = $this->dbh->get_error_message()) {
            // Clear insert_id on a subsequent failed insert.
            if ($this->insert_id && preg_match('/^\s*(insert|replace)\s/i', $query)) {
                $this->insert_id = 0;
            }

            $this->print_error();
            return false;
        }

        if (preg_match('/^\\s*(create|alter|truncate|drop|optimize)\\s*/i', $query)) {
            return $this->dbh->get_return_value();
        } elseif (preg_match('/^\\s*(insert|delete|update|replace)\s/i', $query)) {
            $this->rows_affected = $this->dbh->get_affected_rows();

            if (preg_match('/^\s*(insert|replace)\s/i', $query)) {
                $this->insert_id = $this->dbh->get_insert_id();
            }

            return $this->rows_affected;
        }

        $this->last_result = $this->dbh->get_query_results();
        $this->num_rows    = $this->dbh->get_num_rows();

        return $this->num_rows;
    }

    /**
     * Internal function to perform the sqlite query() call.
     *
     * @since 3.9.0
     *
     * @see wpdb::query()
     *
     * @param string $query The query to run.
     */
    private function _do_query($query)
    {
        if (defined('SAVEQUERIES') && \SAVEQUERIES) {
            $this->timer_start();
        }

        $this->result = $this->dbh->query($query);
        $this->num_queries++;

        if (defined('SAVEQUERIES') && \SAVEQUERIES) {
            $this->log_query(
                $query,
                $this->timer_stop(),
                $this->get_caller(),
                $this->time_start,
                []
            );
        }
    }

    /**
     * Method to set the class variable $col_info.
     *
     * This overrides wpdb::load_col_info(), which uses a mysql function.
     *
     * @see    wpdb::load_col_info()
     * @access protected
     */
    protected function load_col_info()
    {
        if ($this->col_info) {
            return;
        }

        $this->col_info = $this->dbh->get_columns();
    }

    /**
     * Method to return what the database can do.
     *
     * This overrides wpdb::has_cap() to avoid using MySQL functions.
     * SQLite supports subqueries, but not support collation, group_concat and set_charset.
     *
     * @param string $db_cap The feature to check for. Accepts 'collation',
     *                       'group_concat', 'subqueries', 'set_charset',
     *                       'utf8mb4', or 'utf8mb4_520'.
     *
     * @return bool Whether the database feature is supported, false otherwise.
     */
    public function has_cap($db_cap)
    {
        switch (strtolower($db_cap)) {
            case 'subqueries':
                return true;
            case 'collation':
            case 'group_concat':
            case 'set_charset':
            default:
                return false;
        }
    }

    /**
     * Method to return database version number.
     *
     * This overrides wpdb::db_version() to avoid using MySQL function.
     * It returns mysql version number, but it means nothing for SQLite.
     * So it return the newest mysql version.
     *
     * @see wpdb::db_version()
     */
    public function db_version()
    {
        // WordPress currently requires this to be 5.0 or greater.
        return '5.5';
    }

    /**
     * Retrieves full database server information.
     *
     * @return string|false Server info on success, false on failure.
     */
    public function db_server_info()
    {
        return 'SQLite3-' . (new PDO('sqlite::memory:'))->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }
}
