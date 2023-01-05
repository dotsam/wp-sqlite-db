<?php

namespace WP_SQLite_DB;

use PDO;
use PDOException;
use PDOStatement;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * This class extends PDO class and does the real work.
 *
 * It accepts a request from wpdb class, initialize PDO instance,
 * execute SQL statement, and returns the results to WordPress.
 */
class PDOEngine extends PDO
{
    /**
     * Client string for WP Debug page
     *
     * @var string
     */
    public $client_info = '';

    /**
     * Class variable to check if there is an error.
     *
     * @var boolean
     */
    public $is_error = false;

    /**
     * Class variable which is used for CALC_FOUND_ROW query.
     *
     * @var unsigned integer
     */
    public $found_rows_result = null;

    /**
     * Class variable used for query with ORDER BY FIELD()
     *
     * @var array of the object
     */
    public $pre_ordered_results = null;

    /**
     * Class variable to store the rewritten queries.
     *
     * @var    array
     * @access private
     */
    private $rewritten_query;

    /**
     * Class variable to have what kind of query to execute.
     *
     * @var    string
     * @access private
     */
    private $query_type;

    /**
     * Class variable to store the result of the query.
     *
     * @var    array reference to the PHP object
     * @access private
     */
    private $results = null;

    /**
     * Class variable to store the results of the query.
     *
     * This is for the backward compatibility.
     *
     * @var    array reference to the PHP object
     * @access private
     */
    private $_results = null;

    /**
     * Class variable to reference to the PDO instance.
     *
     * @var    PDO object
     * @access private
     */
    private $pdo;

    /**
     * Class variable to store the query string prepared to execute.
     *
     * @var string|array
     */
    private $prepared_query;

    /**
     * Class variable to store the values in the query string.
     *
     * @var    array
     * @access private
     */
    private $extracted_variables = [];

    /**
     * Class variable to store the error messages.
     *
     * @var    array
     * @access private
     */
    private $error_messages = [];

    /**
     * Class variable to store the query strings.
     *
     * @var array
     */
    public $queries = [];

    /**
     * Class variable to store the affected row id.
     *
     * @var    unsigned integer
     * @access private
     */
    private $last_insert_id;

    /**
     * Class variable to store the number of rows affected.
     *
     * @var unsigned integer
     */
    private $affected_rows;

    /**
     * Class variable to store the queried column info.
     *
     * @var array
     */
    private $column_data;

    /**
     * Variable to emulate MySQL affected row.
     *
     * @var integer
     */
    private $num_rows;

    /**
     * Return value from query().
     *
     * Each query has its own return value.
     *
     * @var mixed
     */
    private $return_value;

    /**
     * Variable to determine which insert query to use.
     *
     * Whether VALUES clause in the INSERT query can take multiple values or not
     * depends on the version of SQLite library. We check the version and set
     * this varable to true or false.
     *
     * @var boolean
     */
    private $can_insert_multiple_rows = false;

    /**
     * Variable to track the current parameter number
     *
     * @var integer
     */
    private $param_num;

    /**
     * Varible to check if there is an active transaction.
     *
     * @var    boolean
     * @access protected
     */
    protected $has_active_transaction = false;

    /**
     * The rewrite engine
     *
     * @var QueryRewriter
     */
    private $rewrite_engine;

    /**
     * Constructor
     *
     * Create PDO object, set user defined functions and initialize other settings.
     * Don't use parent::__construct() because this class does not only returns
     * PDO instance but many others jobs.
     *
     * Constructor definition is changed since version 1.7.1.
     *
     * @param none
     */
    public function __construct()
    {
        register_shutdown_function([$this, '__destruct']);

        if (!is_file(FQDB)) {
            $this->prepare_directory();
        }

        $this->rewrite_engine = new QueryRewriter();

        if (isset($GLOBALS['@pdo'])) {
            $this->pdo = $GLOBALS['@pdo'];
            $this->init();
            return;
        }

        $reason = 0;

        do {
            try {
                $this->pdo = new PDO('sqlite:' . FQDB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                new PDOSQLiteUDFS($this->pdo);
                $GLOBALS['@pdo'] = $this->pdo;
            } catch (PDOException $err) {
                $reason = $err->errorInfo[1];
                $err_message = $err->getMessage();
            }
        } while ($reason == 5 || $reason == 6);

        if ($reason > 0) {
            $message = sprintf(
                '<p>%s</p><p>%s</p><p>%s</p>',
                'Database initialization error!',
                sprintf('Code: %d', $reason),
                sprintf('Error Message: %s', $err_message)
            );
            $this->set_error(__LINE__, __METHOD__, $message, $reason);

            return false;
        }

        $this->init();
    }

    /**
     * Destructor
     *
     * If SQLITE_MEM_DEBUG constant is defined, append information about
     * memory usage into database/mem_debug.txt.
     *
     * This definition is changed since version 1.7.
     *
     * @return boolean
     */
    public function __destruct()
    {
        if (defined('SQLITE_MEM_DEBUG') && \SQLITE_MEM_DEBUG) {
            $max = ini_get('memory_limit');

            if (is_null($max)) {
                $message = sprintf(
                    "[%s] Memory_limit is not set in php.ini file.",
                    date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'])
                );
                file_put_contents(FQDBDIR . 'mem_debug.txt', $message, FILE_APPEND);

                return true;
            }

            if (stripos($max, 'M') !== false) {
                $max = (int) $max * 1024 * 1024;
            }

            $peak = memory_get_peak_usage(true);
            $used = round((int) $peak / (int) $max * 100, 2);

            if ($used > 90) {
                $message = sprintf(
                    "[%s] Memory peak usage warning: %s %% used. (max: %sM, now: %sM)\n",
                    date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']),
                    $used,
                    $max,
                    $peak
                );
                file_put_contents(FQDBDIR . 'mem_debug.txt', $message, FILE_APPEND);
            }
        }

        return true;
    }

    /**
     * Method to initialize database, executed in the constructor.
     *
     * It checks if WordPress is in the installing process and does the required
     * jobs. SQLite library version specific settings are also in this function.
     *
     * Some developers use WP_INSTALLING constant for other purposes, if so, this
     * function will do no harms.
     */
    private function init()
    {
        $this->client_info = $this->pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION);

        if (version_compare($this->client_info, '3.7.11', '>=')) {
            $this->can_insert_multiple_rows = true;
        }

        $statement = $this->pdo->query('PRAGMA foreign_keys');

        if ($statement->fetchColumn(0) == '0') {
            $this->pdo->query('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * This method makes database direcotry and .htaccess file.
     *
     * It is executed only once when the installation begins.
     */
    private function prepare_directory()
    {
        $u = umask(0000);

        if (!is_dir(FQDBDIR)) {
            if (!@mkdir(FQDBDIR, 0704, true)) {
                umask($u);
                wp_die('Unable to create the required directory! Please check your server settings.', 'WP SQLite DB Error');
            }
        }

        if (!is_writable(FQDBDIR)) {
            umask($u);
            wp_die('Unable to create a file in the directory! Please check your server settings.', 'WP SQLite DB Error');
        }

        if (!is_file(FQDBDIR . '.htaccess')) {
            $fh = fopen(FQDBDIR . '.htaccess', "w");
            if (!$fh) {
                umask($u);
                wp_die('Unable to create a file in the directory! Please check your server settings.', 'WP SQLite DB Error');
            }
            fwrite($fh, 'DENY FROM ALL');
            fclose($fh);
        }

        if (!is_file(FQDBDIR . 'index.php')) {
            $fh = fopen(FQDBDIR . 'index.php', "w");
            if (!$fh) {
                umask($u);
                wp_die('Unable to create a file in the directory! Please check your server settings.', 'WP SQLite DB Error');
            }
            fwrite($fh, '<?php // Silence is golden. ?>');
            fclose($fh);
        }

        umask($u);

        return true;
    }

    /**
     * Method to execute query().
     *
     * Divide the query types into seven different ones. That is to say:
     *
     * 1. SELECT SQL_CALC_FOUND_ROWS
     * 2. INSERT
     * 3. CREATE TABLE(INDEX)
     * 4. ALTER TABLE
     * 5. SHOW VARIABLES
     * 6. DROP INDEX
     * 7. THE OTHERS
     *
     * #1 is just a tricky play. See the private function handle_sql_count() in query.class.php.
     * From #2 through #5 call different functions respectively.
     * #6 call the ALTER TABLE query.
     * #7 is a normal process: sequentially call prepare_query() and execute_query().
     *
     * #1 process has been changed since version 1.5.1.
     *
     * @param string $statement full SQL statement string
     * @param int $mode
     * @param array $fetch_mode_args
     *
     * @return mixed according to the query type
     * @see    PDO::query()
     */
    #[\ReturnTypeWillChange]
    public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, ...$fetch_mode_args)
    {
        $this->flush();

        $this->queries[] = "Raw query:\n$statement";
        $res             = $this->determine_query_type($statement);

        if (!$res && defined('PDO_DEBUG') && \PDO_DEBUG) {
            $bailoutString = "<h1>Unknown query type</h1><p>Sorry, we cannot determine the type of query that is requested.</p>";
            $this->set_error(__LINE__, __FUNCTION__, $bailoutString, 0);
        }

        switch (strtolower($this->query_type)) {
            case 'set':
                $this->return_value = false;
                break;
            case 'foundrows':
                $_column = ['FOUND_ROWS()' => ''];
                $column  = [];
                if (!is_null($this->found_rows_result)) {
                    $this->num_rows          = $this->found_rows_result;
                    $_column['FOUND_ROWS()'] = $this->num_rows;
                    $column[]                = new ObjectArray($_column);
                    $this->results           = $column;
                    $this->found_rows_result = null;
                }
                break;
            case 'insert':
                if ($this->can_insert_multiple_rows) {
                    $this->execute_insert_query_new($statement);
                    break;
                }
                $this->execute_insert_query($statement);
                break;
            case 'create':
                $this->return_value = $this->execute_create_query($statement);
                break;
            case 'alter':
                $this->return_value =  $this->execute_alter_query($statement);
                break;
            case 'show_variables':
                $this->return_value = $this->show_variables_workaround($statement);
                break;
            case 'showstatus':
                $this->return_value = $this->show_status_workaround($statement);
                break;
            case 'drop_index':
                $this->return_value = false;

                if (preg_match('/^\\s*(DROP\\s*INDEX\\s*.*?)\\s*ON\\s*(.*)/im', $statement, $match)) {
                    $this->query_type   = 'alter';
                    $this->return_value = $this->execute_alter_query('ALTER TABLE ' . trim($match[2]) . ' ' . trim($match[1]));
                }
                break;
            default:
                $this->rewritten_query = $this->rewrite_engine->rewrite_query($statement, $this->query_type);

                if (!is_null($this->pre_ordered_results)) {
                    $this->results             = $this->pre_ordered_results;
                    $this->num_rows            = $this->return_value = count($this->results);
                    $this->pre_ordered_results = null;
                    break;
                }

                $this->queries[] = "Rewritten:\n$this->rewritten_query";
                $this->extract_variables();
                $prepared_query = $this->prepare_query();
                $this->execute_query($prepared_query);

                if (!$this->is_error) {
                    $this->process_results();
                }
                break;
        }

        if (defined('PDO_DEBUG') && \PDO_DEBUG === true) {
            file_put_contents(FQDBDIR . 'debug.txt', $this->get_debug_info(), FILE_APPEND);
        }

        return $this->return_value;
    }

    /**
     * Method to return inserted row id.
     */
    public function get_insert_id()
    {
        return $this->last_insert_id;
    }

    /**
     * Method to return the number of rows affected.
     */
    public function get_affected_rows()
    {
        return $this->affected_rows;
    }

    /**
     * Method to return the queried column names.
     *
     * These data are meaningless for SQLite. So they are dummy emulating
     * MySQL columns data.
     *
     * @return array of the object
     */
    public function get_columns()
    {
        if (!empty($this->results)) {
            $primary_key = [
              'meta_id',
              'comment_ID',
              'link_ID',
              'option_id',
              'blog_id',
              'option_name',
              'ID',
              'term_id',
              'object_id',
              'term_taxonomy_id',
              'umeta_id',
              'id',
            ];

            $unique_key  = ['term_id', 'taxonomy', 'slug'];

            $data        = [
              'name'         => '', // column name
              'table'        => '', // table name
              'max_length'   => 0,  // max length of the column
              'not_null'     => 1,  // 1 if not null
              'primary_key'  => 0,  // 1 if column has primary key
              'unique_key'   => 0,  // 1 if column has unique key
              'multiple_key' => 0,  // 1 if column doesn't have unique key
              'numeric'      => 0,  // 1 if column has numeric value
              'blob'         => 0,  // 1 if column is blob
              'type'         => '', // type of the column
              'unsigned'     => 0,  // 1 if column is unsigned integer
              'zerofill'     => 0,   // 1 if column is zero-filled
            ];

            $table_name = '';
            if (preg_match("/\s*FROM\s*(.*)?\s*/i", $this->rewritten_query, $match)) {
                $table_name = trim($match[1]);
            }

            foreach ($this->results[0] as $key => $value) {
                $data['name']  = $key;
                $data['table'] = $table_name;
                if (in_array($key, $primary_key)) {
                    $data['primary_key'] = 1;
                } elseif (in_array($key, $unique_key)) {
                    $data['unique_key'] = 1;
                } else {
                    $data['multiple_key'] = 1;
                }

                $this->column_data[]  = new ObjectArray($data);

                $data['name']         = '';
                $data['table']        = '';
                $data['primary_key']  = 0;
                $data['unique_key']   = 0;
                $data['multiple_key'] = 0;
            }

            return $this->column_data;
        }

        return null;
    }

    /**
     * Method to return the queried result data.
     *
     * @return mixed
     */
    public function get_query_results()
    {
        return $this->results;
    }

    /**
     * Method to return the number of rows from the queried result.
     */
    public function get_num_rows()
    {
        return $this->num_rows;
    }

    /**
     * Method to return the queried results according to the query types.
     *
     * @return mixed
     */
    public function get_return_value()
    {
        return $this->return_value;
    }

    /**
     * Method to return error messages.
     *
     * @return string
     */
    public function get_error_message()
    {
        if (count($this->error_messages) === 0) {
            $this->is_error       = false;
            $this->error_messages = [];

            return '';
        }

        // $output  = '<div style="clear:both">&nbsp;</div>';
        $output = '';

        foreach ($this->error_messages as $error_message) {
            // $output .= '<div style="clear:both;margin_bottom:2px;border:red dotted thin;" class="error_message" style="border-bottom:dotted blue thin;">';
            // $output .= sprintf(
            //     'Error occurred at line %1$d in Function %2$s. Error message was: %3$s.',
            //     (int) $error_message['line'],
            //     '<code>' . $error_message['function'] . '</code>',
            //     $error_message['message']
            // );
            // $output .= '</div>';
            $output .= $error_message['message'];
        }

        // $output .= '<div class="queries" style="clear:both;margin_bottom:2px;border:red dotted thin;">';
        // $output .= '<p>Queries made or created this session were: </p>';
        // $output .= '<ol>';
        // foreach ($this->queries as $q) {
        //     $output .= '<li>' . $q . '</li>';
        // }
        // $output .= '</ol>';
        // $output .= '</div>';

        // ob_start();
        // debug_print_backtrace();
        // $output .= '<pre>' . ob_get_contents() . '</pre>';
        // ob_end_clean();

        return $output;
    }

    /**
     * Method to return information about query string for debugging.
     *
     * @return string
     */
    private function get_debug_info()
    {
        $output = '';

        foreach ($this->queries as $q) {
            $output .= $q . "\n";
        }

        return $output;
    }

    /**
     * Method to clear previous data.
     */
    private function flush()
    {
        $this->rewritten_query     = '';
        $this->query_type          = '';
        $this->results             = null;
        $this->_results            = null;
        $this->last_insert_id      = null;
        $this->affected_rows       = null;
        $this->column_data         = [];
        $this->num_rows            = null;
        $this->return_value        = null;
        $this->extracted_variables = [];
        $this->error_messages      = [];
        $this->is_error            = false;
        $this->queries             = [];
        $this->param_num           = 0;
    }

    /**
     * Method to create a PDO statement object from the query string.
     *
     * @return PDOStatement
     */
    private function prepare_query()
    {
        $this->queries[] = "Prepare:\n{$this->prepared_query}";
        $reason          = 0;
        $message         = '';
        $statement       = null;

        do {
            try {
                $statement = $this->pdo->prepare($this->prepared_query);
            } catch (PDOException $err) {
                $reason  = $err->errorInfo[1];
                $message = $err->getMessage();
            }
        } while (5 == $reason || 6 == $reason);

        if ($reason > 0) {
            $err_message = sprintf("Problem preparing the PDO SQL Statement. %s", $message);
            $this->set_error(__LINE__, __FUNCTION__, $err_message, $reason);
        }

        return $statement;
    }

    /**
     * Method to execute PDO statement object.
     *
     * This function executes query and sets the variables to give back to WordPress.
     * The variables are class fields. So if success, no return value. If failure, it
     * returns void and stops.
     *
     * @param PDOStatement $statement
     *
     * @return boolean
     */
    private function execute_query($statement)
    {
        $reason  = 0;
        $message = '';

        if (!is_object($statement)) {
            return false;
        }

        $params = count($this->extracted_variables) > 0 ? $this->extracted_variables : null;
        $this->queries[] = "Executing:\n" . ($params ? var_export($params, true) : '(No Parameters)');

        do {
            try {
                $statement->execute($params);
            } catch (PDOException $err) {
                $reason  = $err->errorInfo[1];
                $message = $err->getMessage();
            }
        } while (5 == $reason || 6 == $reason);

        if ($reason > 0) {
            $err_message = sprintf("Error while executing query! %s", $message);
            $this->set_error(__LINE__, __FUNCTION__, $err_message, $reason);

            return false;
        }

        $this->_results = $statement->fetchAll(PDO::FETCH_OBJ);

        // Generate the results that $wpdb will want to see
        switch ($this->query_type) {
            case 'insert':
            case 'update':
            case 'replace':
                $this->last_insert_id = $this->pdo->lastInsertId();
                $this->affected_rows  = $statement->rowCount();
                $this->return_value   = $this->affected_rows;
                break;
            case 'select':
            case 'show':
            case 'showcolumns':
            case 'showindex':
            case 'describe':
            case 'desc':
            case 'check':
            case 'analyze':
                //case "foundrows":
                $this->num_rows     = count($this->_results);
                $this->return_value = $this->num_rows;
                break;
            case 'delete':
                $this->affected_rows = $statement->rowCount();
                $this->return_value  = $this->affected_rows;
                break;
            case 'alter':
            case 'drop':
            case 'create':
            case 'optimize':
            case 'truncate':
                $this->return_value = true;

                if ($this->is_error) {
                    $this->return_value = false;
                }
                break;
        }
    }

    /**
     * Method to extract field data to an array and prepare the query statement.
     *
     * If original SQL statement is CREATE query, this function does nothing.
     */
    private function extract_variables()
    {
        if ($this->query_type == 'create') {
            $this->prepared_query = $this->rewritten_query;

            return;
        }

        // Long queries can really kill this
        $pattern = '/(?<!\\\\)([\'"])(.*?)(?<!\\\\)\\1/imsx';

        $_limit  = $limit = ini_get('pcre.backtrack_limit');
        // If user's setting is more than default * 10, make PHP do the job.
        if ($limit > 10000000) {
            $query = preg_replace_callback(
                $pattern,
                [$this, 'replace_variables_with_placeholders'],
                $this->rewritten_query
            );
        } else {
            do {
                if ($limit > 10000000) {
                    $this->set_error(__LINE__, __FUNCTION__, 'The query is too big to parse properly', 0);
                    break; //no point in continuing execution, would get into a loop
                } else {
                    ini_set('pcre.backtrack_limit', $limit);
                    $query = preg_replace_callback(
                        $pattern,
                        [$this, 'replace_variables_with_placeholders'],
                        $this->rewritten_query
                    );
                }
                $limit = $limit * 10;
            } while (is_null($query));

            // Reset the pcre.backtrack_limit
            ini_set('pcre.backtrack_limit', $_limit);
        }

        if (isset($query)) {
            $this->queries[]      = "With Placeholders:\n{$query}";
            $this->prepared_query = $query;
        }
    }

    /**
     * Call back function to replace field data with PDO parameter.
     *
     * @param string $matches
     *
     * @return string
     */
    private function replace_variables_with_placeholders($matches)
    {
        //remove the wordpress escaping mechanism
        $param = stripslashes($matches[0]);

        //remove trailing spaces
        $param = trim($param);

        //remove the quotes at the end and the beginning
        if (in_array($param[strlen($param) - 1], ["'", '"'])) {
            $param = substr($param, 0, -1); //end
        }
        if (in_array($param[0], ["'", '"'])) {
            $param = substr($param, 1); //start
        }

        $key                         = ':param_' . $this->param_num++;
        $this->extracted_variables[] = $param;

        return " $key ";
    }

    /**
     * Method to determine which query type the argument is.
     *
     * It takes the query string ,determines the type and returns the type string.
     * If the query is the type that SQLite Integration can't executes, returns false.
     *
     * @param string $query
     *
     * @return boolean|string
     */
    private function determine_query_type($query)
    {
        $result = preg_match(
            '/^\\s*(SET|EXPLAIN|PRAGMA|SELECT\\s*FOUND_ROWS|SELECT|INSERT|UPDATE|REPLACE|DELETE|ALTER|CREATE|DROP\\s*INDEX|DROP|SHOW\\s*\\w+\\s*\\w+\\s*|DESCRIBE|DESC|TRUNCATE|OPTIMIZE|CHECK|ANALYZE|START TRANSACTION|COMMIT|ROLLBACK)/i',
            $query,
            $match
        );

        if (!$result) {
            return false;
        }

        $this->query_type = strtolower($match[1]);

        if (stripos($this->query_type, 'found') !== false) {
            $this->query_type = 'foundrows';
        }

        if (stripos($this->query_type, 'start transaction') !== false) {
            $this->query_type = 'begin';
        }

        if (stripos($this->query_type, 'show') !== false) {
            if (stripos($this->query_type, 'show table status') !== false) {
                $this->query_type = 'showstatus';
            } elseif (
                stripos($this->query_type, 'show tables') !== false
                || stripos($this->query_type, 'show full tables') !== false
            ) {
                $this->query_type = 'show';
            } elseif (
                stripos($this->query_type, 'show columns') !== false
                || stripos($this->query_type, 'show fields') !== false
                || stripos($this->query_type, 'show full columns') !== false
            ) {
                $this->query_type = 'showcolumns';
            } elseif (
                stripos($this->query_type, 'show index') !== false
                || stripos($this->query_type, 'show indexes') !== false
                || stripos($this->query_type, 'show keys') !== false
            ) {
                $this->query_type = 'showindex';
            } elseif (
                stripos($this->query_type, 'show variables') !== false
                || stripos($this->query_type, 'show global variables') !== false
                || stripos($this->query_type, 'show session variables') !== false
            ) {
                $this->query_type = 'show_variables';
            } else {
                return false;
            }
        }

        if (stripos($this->query_type, 'drop index') !== false) {
            $this->query_type = 'drop_index';
        }

        return true;
    }

    /**
     * Method to execute INSERT query for SQLite version 3.7.11 or later.
     *
     * SQLite version 3.7.11 began to support multiple rows insert with values
     * clause. This is for that version or later.
     *
     * @param string $query
     */
    private function execute_insert_query_new($query)
    {
        $this->rewritten_query = $this->rewrite_engine->rewrite_query($query, $this->query_type);
        $this->queries[]       = "Rewritten:\n{$this->rewritten_query}";
        $this->extract_variables();
        $statement = $this->prepare_query();
        $this->execute_query($statement);
    }

    /**
     * Method to execute INSERT query for SQLite version 3.7.10 or lesser.
     *
     * It executes the INSERT query for SQLite version 3.7.10 or lesser. It is
     * necessary to rewrite multiple row values.
     *
     * @param string $query
     */
    private function execute_insert_query($query)
    {
        global $wpdb;

        $multi_insert = false;
        $statement    = null;

        if (preg_match('/(INSERT.*?VALUES\\s*)(\(.*\))/imsx', $query, $matched)) {
            $query_prefix = $matched[1];
            $values_data  = $matched[2];

            if (
                stripos($values_data, 'ON DUPLICATE KEY') !== false
                || stripos($query_prefix, "INSERT INTO $wpdb->comments") !== false
            ) {
                $exploded_parts = $values_data;
            } else {
                $exploded_parts = $this->parse_multiple_inserts($values_data);
            }

            if (count($exploded_parts) > 1) {
                $multi_insert = true;
            }
        }

        if ($multi_insert) {
            $first = true;

            foreach ($exploded_parts as $value) {
                $suffix = (substr($value, -1, 1) === ')') ? '' : ')';
                $query_string              = "{$query_prefix} {$value}{$suffix}";
                $this->rewritten_query     = $this->rewrite_engine->rewrite_query($query_string, $this->query_type);
                $this->queries[]           = "Rewritten:\n{$this->rewritten_query}";
                $this->extracted_variables = [];
                $this->extract_variables();

                if ($first) {
                    $statement = $this->prepare_query();
                    $first = false;
                }

                $this->execute_query($statement);
            }
        } else {
            $this->rewritten_query = $this->rewrite_engine->rewrite_query($query, $this->query_type);
            $this->queries[]       = "Rewritten:\n{$this->rewritten_query}";
            $this->extract_variables();
            $statement = $this->prepare_query();
            $this->execute_query($statement);
        }
    }

    /**
     * Method to help rewriting multiple row values insert query.
     *
     * It splits the values clause into an array to execute separately.
     *
     * @param string $values
     *
     * @return array
     */
    private function parse_multiple_inserts($values)
    {
        $tokens         = preg_split("/(''|(?<!\\\\)'|(?<!\()\),(?=\s*\())/s", $values, -1, PREG_SPLIT_DELIM_CAPTURE);
        $exploded_parts = [];
        $part           = '';
        $literal        = false;

        foreach ($tokens as $token) {
            switch ($token) {
                case "),":
                    if (!$literal) {
                        $exploded_parts[] = $part;
                        $part             = '';
                    } else {
                        $part .= $token;
                    }
                    break;
                case "'":
                    $literal = ! $literal;
                    $part .= $token;
                    break;
                default:
                    $part .= $token;
                    break;
            }
        }

        if (!empty($part)) {
            $exploded_parts[] = $part;
        }

        return $exploded_parts;
    }

    /**
     * Method to execute CREATE query.
     *
     * @param string $query
     *
     * @return boolean
     */
    private function execute_create_query($query)
    {
        $rewritten_query = $this->rewrite_engine->rewrite_query($query, $this->query_type);
        $reason          = 0;
        $message         = '';

        try {
            foreach ($rewritten_query as $single_query) {
                $this->queries[] = "Executing:\n{$single_query}";
                $single_query    = trim($single_query);
                if (empty($single_query)) {
                    continue;
                }
                $this->pdo->exec($single_query);
            }
        } catch (PDOException $err) {
            $reason  = $err->errorInfo[1];
            $message = $err->getMessage();
        }

        if ($reason > 0) {
            $err_message = sprintf("Problem in creating table or index. %s", $message);
            $this->set_error(__LINE__, __FUNCTION__, $err_message, $reason);

            return false;
        }

        return true;
    }

    /**
     * Method to execute ALTER TABLE query.
     *
     * @param string $query
     *
     * @return boolean
     */
    private function execute_alter_query($query)
    {
        $reason          = 0;
        $message         = '';
        $re_query        = '';
        $rewritten_query = $this->rewrite_engine->rewrite_query($query, $this->query_type);

        if (is_array($rewritten_query) && array_key_exists('recursion', $rewritten_query)) {
            $re_query = $rewritten_query['recursion'];
            unset($rewritten_query['recursion']);
        }

        try {
            foreach ((array) $rewritten_query as $single_query) {
                $this->queries[] = "Executing:\n{$single_query}";
                $single_query    = trim($single_query);
                if (empty($single_query)) {
                    continue;
                }
                $this->pdo->exec($single_query);
            }
        } catch (PDOException $err) {
            $reason  = $err->errorInfo[1];
            $message = $err->getMessage();

            if (5 == $reason || 6 == $reason) {
                usleep(10000);
            }
        }

        if (!empty($re_query)) {
            $this->query($re_query);
        }

        if ($reason > 0) {
            $err_message = sprintf("Problem in executing alter query. %s", $message);
            $this->set_error(__LINE__, __FUNCTION__, $err_message, $reason);

            return false;
        }

        return true;
    }

    /**
     * Method to execute SHOW VARIABLES query
     *
     * This query is meaningless for SQLite. This function returns null data with some
     * exceptions and only avoids the error message.
     *
     * @param string $query
     *
     * @return bool
     */
    private function show_variables_workaround($query)
    {
        $dummy_data = [
            'Variable_name' => '',
            'Value' => null,
        ];

        if (preg_match('/SHOW\\s*VARIABLES\\s*LIKE\\s*(.*)?$/im', $query, $match)) {
            $dummy_data['Variable_name'] = trim(str_replace("'", '', $match[1]));

            switch ($dummy_data['Variable_name']) {
                case 'max_allowed_packet':
                    // This is set for Wordfence Security Plugin
                    $dummy_data['Value'] = 1047552;
                    break;
                default:
                    $dummy_data['Value'] = '';
            }
        }

        $_results[]         = new ObjectArray($dummy_data);
        $this->results      = $_results;
        $this->num_rows     = count($this->results);
        $this->return_value = $this->num_rows;

        return true;
    }

    /**
     * Method to execute SHOW TABLE STATUS query.
     *
     * This query is meaningless for SQLite. This function return dummy data.
     *
     * @param string $query
     *
     * @return bool
     */
    private function show_status_workaround($query)
    {
        $table_name = '';
        if (preg_match('/^SHOW\\s*TABLE\\s*STATUS\\s*LIKE\\s*(.*?)$/im', $query, $match)) {
            $table_name = str_replace("'", '', $match[1]);
        }

        $dummy_data         = [
          'Name'            => $table_name,
          'Engine'          => '',
          'Version'         => '',
          'Row_format'      => '',
          'Rows'            => 0,
          'Avg_row_length'  => 0,
          'Data_length'     => 0,
          'Max_data_length' => 0,
          'Index_length'    => 0,
          'Data_free'       => 0,
          'Auto_increment'  => 0,
          'Create_time'     => '',
          'Update_time'     => '',
          'Check_time'      => '',
          'Collation'       => '',
          'Checksum'        => '',
          'Create_options'  => '',
          'Comment'         => '',
        ];
        $_results[]         = new ObjectArray($dummy_data);
        $this->results      = $_results;
        $this->num_rows     = count($this->results);
        $this->return_value = $this->num_rows;

        return true;
    }

    /**
     * Method to format the queried data to that of MySQL.
     */
    private function process_results()
    {
        if (in_array($this->query_type, ['describe', 'desc', 'showcolumns'], true)) {
            $this->convert_to_columns_object();
        } elseif ('showindex' === $this->query_type) {
            $this->convert_to_index_object();
        } elseif (in_array($this->query_type, ['check', 'analyze'], true)) {
            $this->convert_result_check_or_analyze();
        } else {
            $this->results = $this->_results;
        }
    }

    /**
     * Method to format the error messages and put out to the file.
     *
     * When $wpdb::suppress_errors is set to true or $wpdb::show_errors is set to false,
     * the error messages are ignored.
     *
     * @param string $line where the error occurred.
     * @param string $function to indicate the function name where the error occurred.
     * @param string $message
     * @param string $code
     *
     * @return boolean
     */
    private function set_error($line, $function, $message, $code)
    {
        $this->is_error         = true;

        $this->error_messages[] = [
            'message' => $message,
            'code' => $code,
            'line' => $line,
            'function' => $function,
        ];

        // FIXME: Errors when $wpdb is not set up yet/is being set up
        // if ($wpdb->suppress_errors) {
        //     return false;
        // }

        // if (!$wpdb->show_errors) {
        //     return false;
        // }

        // file_put_contents(FQDBDIR . 'debug.txt', "Line $line, Function: $function, Message: $message \n", FILE_APPEND);
    }

    /**
     * Method to convert the SHOW COLUMNS query data to an object.
     *
     * It rewrites pragma results to mysql compatible array
     * when query_type is describe, we use sqlite pragma function.
     *
     * @access private
     */
    private function convert_to_columns_object()
    {
        $_results = [];
        // Field names MySQL SHOW COLUMNS returns
        $_columns = [
          'Field'   => '',
          'Type'    => '',
          'Null'    => '',
          'Key'     => '',
          'Default' => '',
          'Extra'   => '',
        ];

        if (count($this->_results) == 0) {
            echo $this->get_error_message();
        } else {
            foreach ($this->_results as $row) {
                if (! is_object($row)) {
                    continue;
                }
                if (property_exists($row, 'name')) {
                    $_columns['Field'] = $row->name;
                }
                if (property_exists($row, 'type')) {
                    $_columns['Type'] = $row->type;
                }
                if (property_exists($row, 'notnull')) {
                    $_columns['Null'] = $row->notnull ? 'NO' : 'YES';
                }
                if (property_exists($row, 'pk')) {
                    $_columns['Key'] = $row->pk ? 'PRI' : '';
                }
                if (property_exists($row, 'dflt_value')) {
                    $_columns['Default'] = $row->dflt_value;
                }

                $_results[]          = new ObjectArray($_columns);
            }
        }

        $this->results = $_results;
    }

    /**
     * Method to convert SHOW INDEX query data to PHP object.
     *
     * It rewrites the result of SHOW INDEX to the Object compatible with MySQL
     * added the WHERE clause manipulation (ver 1.3.1)
     *
     * @access private
     */
    private function convert_to_index_object()
    {
        $_results = [];
        $_columns = [
          'Table'        => '',
          'Non_unique'   => '', // unique -> 0, not unique -> 1
          'Key_name'     => '', // the name of the index
          'Seq_in_index' => '', // column sequence number in the index. begins at 1
          'Column_name'  => '',
          'Collation'    => '', //A(scend) or NULL
          'Cardinality'  => '',
          'Sub_part'     => '', // set to NULL
          'Packed'       => '', // How to pack key or else NULL
          'Null'         => '', // If column contains null, YES. If not, NO.
          'Index_type'   => '', // BTREE, FULLTEXT, HASH, RTREE
          'Comment'      => '',
        ];
        if (count($this->_results) == 0) {
            echo $this->get_error_message();
        } else {
            foreach ($this->_results as $row) {
                if ($row->type == 'table' && !stripos($row->sql, 'primary')) {
                    continue;
                }
                if ($row->type == 'index' && stripos($row->name, 'sqlite_autoindex') !== false) {
                    continue;
                }

                switch ($row->type) {
                    case 'table':
                        $pattern1 = '/^\\s*PRIMARY.*\((.*)\)/im';
                        $pattern2 = '/^\\s*(\\w+)?\\s*.*PRIMARY.*(?!\()/im';
                        if (preg_match($pattern1, $row->sql, $match)) {
                            $col_name                = trim($match[1]);
                            $_columns['Key_name']    = 'PRIMARY';
                            $_columns['Non_unique']  = 0;
                            $_columns['Column_name'] = $col_name;
                        } elseif (preg_match($pattern2, $row->sql, $match)) {
                            $col_name                = trim($match[1]);
                            $_columns['Key_name']    = 'PRIMARY';
                            $_columns['Non_unique']  = 0;
                            $_columns['Column_name'] = $col_name;
                        }
                        break;
                    case 'index':
                        $_columns['Non_unique'] = 1;
                        if (stripos($row->sql, 'unique') !== false) {
                            $_columns['Non_unique'] = 0;
                        }

                        if (preg_match('/^.*\((.*)\)/i', $row->sql, $match)) {
                            $col_name                = str_replace("'", '', $match[1]);
                            $_columns['Column_name'] = trim($col_name);
                        }
                        $_columns['Key_name'] = $row->name;
                        break;
                }

                $_columns['Table']       = $row->tbl_name;
                $_columns['Collation']   = null;
                $_columns['Cardinality'] = 0;
                $_columns['Sub_part']    = null;
                $_columns['Packed']      = null;
                $_columns['Null']        = 'NO';
                $_columns['Index_type']  = 'BTREE';
                $_columns['Comment']     = '';

                $_results[]              = new ObjectArray($_columns);
            }

            if (stripos($this->queries[0], 'WHERE') !== false) {
                preg_match('/WHERE\\s*(.*)$/im', $this->queries[0], $match);
                list($key, $value) = explode('=', $match[1]);
                $key   = trim($key);
                $value = preg_replace("/[\';]/", '', $value);
                $value = trim($value);

                foreach ($_results as $result) {
                    if (
                        !empty($result->$key)
                        && is_scalar($result->$key)
                        && stripos($value, $result->$key) !== false
                    ) {
                        unset($_results);
                        $_results[] = $result;
                        break;
                    }
                }
            }
        }

        $this->results = $_results;
    }

    /**
     * Method to the CHECK query data to an object.
     *
     * @access private
     */
    private function convert_result_check_or_analyze()
    {
        $is_check      = 'check' === $this->query_type;
        $_results[]    = new ObjectArray([
            'Table'    => '',
            'Op'       => $is_check ? 'check' : 'analyze',
            'Msg_type' => 'status',
            'Msg_text' => $is_check ? 'OK' : 'Table is already up to date',
        ]);

        $this->results = $_results;
    }

    /**
     * Method to call PDO::beginTransaction().
     *
     * @return boolean
     * @see    PDO::beginTransaction()
     */
    #[\ReturnTypeWillChange]
    public function beginTransaction(): bool
    {
        if ($this->has_active_transaction) {
            return false;
        }

        $this->has_active_transaction = $this->pdo->beginTransaction();

        return $this->has_active_transaction;
    }

    /**
     * Method to call PDO::commit().
     *
     * @see PDO::commit()
     */
    #[\ReturnTypeWillChange]
    public function commit(): bool
    {
        $isSuccess = $this->pdo->commit();
        $this->has_active_transaction = false;

        return $isSuccess;
    }

    /**
     * Method to call PDO::rollBack().
     *
     * @see PDO::rollBack()
     */
    #[\ReturnTypeWillChange]
    public function rollBack(): bool
    {
        $isSuccess = $this->pdo->rollBack();
        $this->has_active_transaction = false;

        return $isSuccess;
    }
}
