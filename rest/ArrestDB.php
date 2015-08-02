<?php
class ArrestDB
{
    static $Options = [];
    public static function SetOption($option, $value)
    {
        self::$Options[$option] = $value;
    }
    public static function GetOption($option)
    {
        return isset(self::$Options[$option]) ? self::$Options[$option] : false;
    }

	public static function Query($query = null)
	{
		static $db = null;
		static $result = [];

		try
		{
			if (isset($db, $query) === true)
			{
                if ($query === 'BEGIN')
                {
                    if ($db->beginTransaction())
                    {
                        return ArrestDB::GetOption('RETURN_RAW') ? [ 'db' => $db ] : true;
                    }
                    syslog(LOG_ERR, sprintf('ArrestDB::Query - BEGIN failed: %s - %s %s %s', $db->errorCode(), $db->errorInfo()[0], $db->errorInfo()[1], $db->errorInfo()[2]));
                    return false;
                }
                if ($query === 'COMMIT')
                {
                    if ($db->commit())
                    {
                        return ArrestDB::GetOption('RETURN_RAW') ? [ 'db' => $db ] : true;
                    }
                    syslog(LOG_ERR, sprintf('ArrestDB::Query - COMMIT failed: %s - %s %s %s', $db->errorCode(), $db->errorInfo()[0], $db->errorInfo()[1], $db->errorInfo()[2]));
                    return false;
                }
                if ($query === 'ROLLBACK')
                {
                    if ($db->rollBack())
                    {
                        return ArrestDB::GetOption('RETURN_RAW') ? [ 'db' => $db ] : true;
                    }
                    syslog(LOG_ERR, sprintf('ArrestDB::Query - ROLLBACK failed: %s - %s %s %s', $db->errorCode(), $db->errorInfo()[0], $db->errorInfo()[1], $db->errorInfo()[2]));
                    return false;
                }

				if (strncasecmp($db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mysql', 5) === 0 && !ArrestDB::GetOption('MYSQL_NOTRDQUOTE'))
				{
					$query = strtr($query, '"', '`');
				}

				if (empty($result[$hash = crc32($query)]) === true)
				{
					$result[$hash] = $db->prepare($query);
				}

				$data = array_slice(func_get_args(), 1);

				if (count($data, COUNT_RECURSIVE) > count($data))
				{
					$data = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveArrayIterator($data)), false);
				}

				if ($result[$hash]->execute($data) === true)
				{
                    if (ArrestDB::GetOption('RETURN_RAW'))
                    {
                        return [ 'db' => $db, 'result' => $result[$hash] ];
                    }

					$sequence = null;

					if ((strncmp($db->getAttribute(\PDO::ATTR_DRIVER_NAME), 'pgsql', 5) === 0) && (sscanf($query, 'INSERT INTO %s', $sequence) > 0))
					{
						$sequence = sprintf('%s_id_seq', trim($sequence, '"'));
					}

					switch (strstr($query, ' ', true))
					{
						case 'INSERT':
						case 'REPLACE':
							return $db->lastInsertId($sequence);

						case 'UPDATE':
						case 'DELETE':
							return $result[$hash]->rowCount();

						case 'SELECT':
						case 'EXPLAIN':
						case 'PRAGMA':
						case 'SHOW':
							return $result[$hash]->fetchAll();
					}

					return true;
				}

                syslog(LOG_ERR, sprintf('ArrestDB::Query - query failed: %s - %s %s %s', $result[$hash]->errorCode(), $result[$hash]->errorInfo()[0], $result[$hash]->errorInfo()[1], $result[$hash]->errorInfo()[2]));
				return false;
			}

			else if (isset($query) === true)
			{
				$options = array
				(
					\PDO::ATTR_CASE => \PDO::CASE_NATURAL,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
					\PDO::ATTR_EMULATE_PREPARES => false,
					\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
					\PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
					\PDO::ATTR_STRINGIFY_FETCHES => false,
				);

				if (preg_match('~^sqlite://([[:print:]]++)$~i', $query, $dsn) > 0)
				{
					$options += array
					(
						\PDO::ATTR_TIMEOUT => 3,
					);

					$db = new \PDO(sprintf('sqlite:%s', $dsn[1]), null, null, $options);
					$pragmas = array
					(
						'automatic_index' => 'ON',
						'cache_size' => '8192',
						'foreign_keys' => 'ON',
						'journal_size_limit' => '67110000',
						'locking_mode' => 'NORMAL',
						'page_size' => '4096',
						'recursive_triggers' => 'ON',
						'secure_delete' => 'ON',
						'synchronous' => 'NORMAL',
						'temp_store' => 'MEMORY',
						'journal_mode' => 'WAL',
						'wal_autocheckpoint' => '4096',
					);

					if (strncasecmp(PHP_OS, 'WIN', 3) !== 0)
					{
						$memory = 131072;

						if (($page = intval(shell_exec('getconf PAGESIZE'))) > 0)
						{
							$pragmas['page_size'] = $page;
						}

						if (is_readable('/proc/meminfo') === true)
						{
							if (is_resource($handle = fopen('/proc/meminfo', 'rb')) === true)
							{
								while (($line = fgets($handle, 1024)) !== false)
								{
									if (sscanf($line, 'MemTotal: %d kB', $memory) == 1)
									{
										$memory = round($memory / 131072) * 131072; break;
									}
								}

								fclose($handle);
							}
						}

						$pragmas['cache_size'] = intval($memory * 0.25 / ($pragmas['page_size'] / 1024));
						$pragmas['wal_autocheckpoint'] = $pragmas['cache_size'] / 2;
					}

					foreach ($pragmas as $key => $value)
					{
						$db->exec(sprintf('PRAGMA %s=%s;', $key, $value));
					}
				}

				else if (preg_match('~^(mysql|pgsql)://(?:(.+?)(?::(.+?))?@)?([^/:@]++)(?::(\d++))?/(\w++)/?$~i', $query, $dsn) > 0)
				{
					if (strncasecmp($query, 'mysql', 5) === 0)
					{
						$options += array
						(
							\PDO::ATTR_AUTOCOMMIT => true,
							\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES "utf8" COLLATE "utf8_general_ci", time_zone = "+00:00";',
							\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
						);
					}

					$db = new \PDO(sprintf('%s:host=%s;port=%s;dbname=%s', $dsn[1], $dsn[4], $dsn[5], $dsn[6]), $dsn[2], $dsn[3], $options);
				}
			}
		}

		catch (\Exception $exception)
		{
			syslog(LOG_ERR, sprintf('ArrestDB::Query - query failed: %s %s', $exception->getCode(), $exception->getMessage()));
			foreach(explode("\n", $exception->getTraceAsString()) as $e)
            {
				syslog(LOG_ERR, 'ArrestDB::Query - query failed: ' . $e);
			}
			foreach(explode("\n", $query) as $e)
            {
				syslog(LOG_ERR, 'ArrestDB::Query - query failed: ' . $e);
			}
            foreach (self::$Options as $k => $v)
            {
                syslog(LOG_ERR, 'ArrestDB::Query - query failed: ' . $k . ' => ' . $v);
            }
			return false;
		}

		return (isset($db) === true) ? $db : false;
	}

	public static function Reply($data)
	{
		$bitmask = 0;
		$options = ['UNESCAPED_SLASHES', 'UNESCAPED_UNICODE'];

		if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) === true)
		{
			$options[] = 'PRETTY_PRINT';
		}

		foreach ($options as $option)
		{
			$bitmask |= (defined('JSON_' . $option) === true) ? constant('JSON_' . $option) : 0;
		}

		if (($result = json_encode($data, $bitmask)) !== false)
		{
			$callback = null;

			if (array_key_exists('callback', $_GET) === true)
			{
				$callback = trim(preg_replace('~[^[:alnum:]\[\]_.]~', '', $_GET['callback']));

				if (empty($callback) !== true)
				{
					$result = sprintf('%s(%s);', $callback, $result);
				}
			}

			if (headers_sent() !== true)
			{
				header(sprintf('Content-Type: application/%s; charset=utf-8', (empty($callback) === true) ? 'json' : 'javascript'));
			}
		}

		return $result;
	}

	public static function Serve($on = null, $route = null, $callback = null)
	{
		static $root = null;

		if (isset($_SERVER['REQUEST_METHOD']) !== true)
		{
			$_SERVER['REQUEST_METHOD'] = 'CLI';
		}

		if ((empty($on) === true) || (strcasecmp($_SERVER['REQUEST_METHOD'], $on) === 0))
		{
			if (is_null($root) === true)
			{
				$root = preg_replace('~/++~', '/', substr($_SERVER['PHP_SELF'], strlen($_SERVER['SCRIPT_NAME'])) . '/');
			}

			if (preg_match('~^' . str_replace(['#any', '#num'], ['[^/]++', '-?[0-9]++'], $route) . '~i', $root, $parts) > 0)
			{
				return (empty($callback) === true) ? true : exit(call_user_func_array($callback, array_slice($parts, 1)));
			}
		}

		return false;
	}
}
?>
