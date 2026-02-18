<?php

declare(strict_types=1);

namespace Spot;

use Doctrine\DBAL;

/**
 * @package Spot
 */
class Config
{
    protected ?string $_defaultConnection = null;

    /** @var array<string, DBAL\Connection> */
    protected array $_connections = [];

    public function __serialize(): array
    {
        return [];
    }

    /** @param array<mixed> $serialized */
    public function __unserialize(array $serialized): void
    {
    }

    /**
     * Taken from PHPUnit 3.4:
     *
     * @link http://github.com/sebastianbergmann/phpunit/blob/3.4/PHPUnit/Util/PDO.php
     *
     * Returns the Data Source Name as a structure containing the various parts of the DSN.
     *
     * Additional keys can be added by appending a URI query string to the
     * end of the DSN.
     *
     * The format of the supplied DSN is in its fullest form:
     * <code>
     *  adapter(dbsyntax)://user:password@protocol+host/database?option=8&another=true
     * </code>
     *
     * Most variations are allowed:
     * <code>
     *  adapter://user:password@protocol+host:110//usr/db_file.db?mode=0644
     *  adapter://user:password@host/database_name
     *  adapter://user:password@host
     *  adapter://user@host
     *  adapter://host/database
     *  adapter://host
     *  adapter(dbsyntax)
     *  adapter
     * </code>
     *
     * This function is 'borrowed' from PEAR /DB.php .
     *
     * @param array<string, mixed>|string $dsn Data Source Name to be parsed
     *
     * @return array<string, mixed>|false an associative array with the following keys:
     *                                    + adapter:  Database backend used in PHP (mysql, odbc etc.)
     *                                    + dbsyntax: Database used with regards to SQL syntax etc.
     *                                    + protocol: Communication protocol to use (tcp, unix etc.)
     *                                    + host: Host specification (hostname[:port])
     *                                    + dbname: Database to use on the DBMS server
     *                                    + user: User name for login
     *                                    + password: Password for login
     */
    public static function parseDsn(array|string $dsn): array|false
    {
        if ($dsn === 'sqlite::memory:') {
            $dsn = 'sqlite://:memory:';
        }

        $parsed = [
            'adapter'  => null,
            'dbsyntax' => null,
            'user'     => null,
            'password' => null,
            'protocol' => null,
            'host'     => null,
            'port'     => null,
            'socket'   => null,
            'dbname'   => null,
        ];

        if (is_array($dsn)) {
            $dsn = array_merge($parsed, $dsn);

            if (!$dsn['dbsyntax']) {
                $dsn['dbsyntax'] = $dsn['adapter'];
            }

            return $dsn;
        }

        // Find phptype and dbsyntax
        if (($pos = strpos($dsn, '://')) !== false) {
            $str = substr($dsn, 0, $pos);
            $dsn = substr($dsn, $pos + 3);
        } elseif (($pos = strpos($dsn, ':')) !== false) {
            $str = substr($dsn, 0, $pos);
            $dsn = substr($dsn, $pos + 1);
        } else {
            $str = $dsn;
            $dsn = null;
        }

        // Get phptype and dbsyntax
        // $str => phptype(dbsyntax)
        if (preg_match('|^(.+?)\((.*?)\)$|', $str, $arr)) {
            $parsed['adapter']  = $arr[1];
            $parsed['dbsyntax'] = !$arr[2] ? $arr[1] : $arr[2];
        } else {
            $parsed['adapter']  = $str;
            $parsed['dbsyntax'] = $str;
        }

        if (empty($dsn)) {
            return $parsed;
        }

        // Get (if found): user and password
        // $dsn => user:password@protocol+host/database
        if (($at = strrpos((string) $dsn, '@')) !== false) {
            $str = substr($dsn, 0, $at);
            $dsn = substr($dsn, $at + 1);

            if (($pos = strpos($str, ':')) !== false) {
                $parsed['user']     = rawurldecode(substr($str, 0, $pos));
                $parsed['password'] = rawurldecode(substr($str, $pos + 1));
            } else {
                $parsed['user'] = rawurldecode($str);
            }
        }

        // Find protocol and host
        if (preg_match('|^([^(]+)\((.*?)\)/?(.*?)$|', $dsn, $match)) {
            // $dsn => proto(proto_opts)/database
            $proto      = $match[1];
            $proto_opts = $match[2] ?: null;
            $dsn        = $match[3];
        } else {
            // $dsn => protocol+host/database (old format)
            if (strpos($dsn, '+') !== false) {
                [$proto, $dsn] = explode('+', $dsn, 2);
            }

            if (strpos($dsn, '/') !== false) {
                [$proto_opts, $dsn] = explode('/', $dsn, 2);
            } else {
                $proto_opts = $dsn;
                $dsn        = null;
            }
        }

        // process the different protocol options
        $parsed['protocol'] = (!empty($proto)) ? $proto : 'tcp';
        $proto_opts         = rawurldecode((string) $proto_opts);

        if ($parsed['protocol'] === 'tcp') {
            if (strpos($proto_opts, ':') !== false) {
                [$parsed['host'], $parsed['port']] = explode(':', $proto_opts);
            } else {
                $parsed['host'] = $proto_opts;
            }
        } elseif ($parsed['protocol'] === 'unix') {
            $parsed['socket'] = $proto_opts;
        }

        // Get database if any
        // $dsn => database
        if ($dsn) {
            if (($pos = strpos($dsn, '?')) === false) {
                // /database
                $parsed['dbname'] = rawurldecode($dsn);
            } else {
                // /database?param1=value1&param2=value2
                $parsed['dbname'] = rawurldecode(substr($dsn, 0, $pos));
                $dsn              = substr($dsn, $pos + 1);

                if (strpos($dsn, '&') !== false) {
                    $opts = explode('&', $dsn);
                } else { // database?param1=value1
                    $opts = [$dsn];
                }

                foreach ($opts as $opt) {
                    [$key, $value] = explode('=', $opt);

                    if (!isset($parsed[$key])) {
                        // don't allow params overwrite
                        $parsed[$key] = rawurldecode($value);
                    }
                }
            }
        }

        // Replace 'adapter' with 'driver' and add 'pdo_'
        $driver = null;

        if (isset($parsed['adapter'])) {
            $driver           = $parsed['adapter'];
            $parsed['driver'] = 'pdo_' . $driver;
            unset($parsed['adapter']);
        }

        // For SQLite :memory:, DBAL4 expects 'memory' => true instead of a dbname
        if ($driver === 'sqlite' && ($parsed['dbname'] === ':memory:' || empty($parsed['dbname']))) {
            $parsed['memory'] = true;
            unset($parsed['dbname']);
        }

        // DBAL4 rejects unknown connection parameters. Strip Spot-internal keys
        // that are not part of the DBAL connection params contract.
        $dbalKeys = [
            'driver', 'host', 'port', 'dbname', 'user', 'password',
            'charset', 'memory', 'path', 'unix_socket', 'url',
            'driverClass', 'wrapperClass', 'driverOptions',
        ];

        foreach (array_keys($parsed) as $key) {
            if (!in_array($key, $dbalKeys, true) || $parsed[$key] === null) {
                unset($parsed[$key]);
            }
        }

        return $parsed;
    }

    /**
     * Add database connection
     *
     * @param string                                      $name    Unique name for the connection
     * @param DBAL\Connection|array<string, mixed>|string $dsn     DSN string, params array, or existing Connection
     * @param bool                                        $default Use this connection as the default?
     *
     * @throws Exception
     */
    public function addConnection(string $name, DBAL\Connection|array|string $dsn, bool $default = false): DBAL\Connection
    {
        // Connection name must be unique
        if (isset($this->_connections[$name])) {
            throw new Exception("Connection for '" . $name . "' already exists. Connection name must be unique.");
        }

        if ($dsn instanceof DBAL\Connection) {
            $connection = $dsn;
        } else {
            if (is_array($dsn)) {
                $connectionParams = $dsn;
            } else {
                $connectionParams = $this->parseDsn($dsn);

                if ($connectionParams === false) {
                    throw new Exception('Unable to parse given DSN string');
                }
            }

            $config     = new DBAL\Configuration();
            $connection = DBAL\DriverManager::getConnection($connectionParams, $config);
        }

        // Set as default connection?
        if ($default === true || $this->_defaultConnection === null) {
            $this->_defaultConnection = $name;
        }

        // Store connection and return adapter instance
        $this->_connections[$name] = $connection;

        return $connection;
    }

    /**
     * Get connection by name, or the default connection when no name is given.
     *
     * @throws Exception
     */
    public function connection(?string $name = null): DBAL\Connection|false
    {
        if ($name === null) {
            return $this->defaultConnection();
        }

        if (!isset($this->_connections[$name])) {
            return false;
        }

        return $this->_connections[$name];
    }

    /**
     * Get all connections
     *
     * @return array<string, DBAL\Connection>
     */
    public function connections(): array
    {
        return $this->_connections;
    }

    /**
     * Get default connection
     *
     * @throws Exception
     */
    public function defaultConnection(): DBAL\Connection
    {
        if ($this->_defaultConnection === null || !isset($this->_connections[$this->_defaultConnection])) {
            throw new Exception('No database connection specified! Please add at least one database connection!');
        }

        return $this->_connections[$this->_defaultConnection];
    }
}
