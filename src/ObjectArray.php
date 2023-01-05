<?php

namespace WP_SQLite_DB;

/**
 * Class to change queried data to PHP object.
 *
 * @author kjm
 */
class ObjectArray
{
    public function __construct($data = null, &$node = null)
    {
        foreach ($data as $key => $value) {
            if (!$node) {
                $node = &$this;
            }

            if (is_array($value)) {
                $node->$key = new \stdClass();
                self::__construct($value, $node->$key);
            } else {
                $node->$key = $value;
            }
        }
    }
}
