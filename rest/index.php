<?php
require "db_strings.inc";
require "ArrestDB.php";

$dsn = "mysql://$mysql_user:$mysql_password@$mysql_hostname/$mysql_schema";
$clients = [];

/**
* The MIT License
* http://creativecommons.org/licenses/MIT/
*
* ArrestDB 1.9.0 (github.com/alixaxel/ArrestDB/)
* Copyright (c) 2014 Alix Axel <alix.axel@gmail.com>
*
* Hackery (C) 2015 Peter L Jones <pljones@users.sf.net>
**/

if (strcmp(PHP_SAPI, 'cli') === 0)
{
	exit('ArrestDB should not be run from CLI.' . PHP_EOL);
}

if ((empty($clients) !== true) && (in_array($_SERVER['REMOTE_ADDR'], (array) $clients) !== true))
{
	http_response_code(403);
	exit();
}

else if (ArrestDB::Query($dsn) === false)
{
	http_response_code(503);
	exit();
}

if (array_key_exists('_method', $_GET) === true)
{
	$_SERVER['REQUEST_METHOD'] = strtoupper(trim($_GET['_method']));
}

else if (array_key_exists('HTTP_X_HTTP_METHOD_OVERRIDE', $_SERVER) === true)
{
	$_SERVER['REQUEST_METHOD'] = strtoupper(trim($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
}

ArrestDB::Serve('GET', '/\*', function () {
	if (isset($_GET['query']) !== true)
	{
		http_response_code(400);
		exit('Missing query name');
	}
	$filename = dirname(__FILE__) . '/' . preg_replace('/[^0-9A-Za-z_]/', '', $_GET['query']) . '.sql';
	if (!is_file($filename))
	{
		http_response_code(400);
		exit('Query not found');
	}

	$query = file_get_contents($filename);
	if ($query === FALSE)
	{
		http_response_code(400);
		exit('Failed to load query');
	}
	$query = explode("\n", $query);
	$query = implode(' ', $query);
	$query = preg_replace('/\s\s+/', ' ', $query);

	if (isset($_GET['arguments']) === true)
	{
		$arguments = explode(",", $_GET['arguments']);
	}
	else
	{
		$arguments = [];
	}

	if (count($arguments))
	{
		array_unshift($arguments, $query);
		$result = call_user_func_array('ArrestDB::Query', $arguments);
	}
	else
	{
		$result = ArrestDB::Query($query);
	}

	if ($result === false)
	{
		http_response_code(404);
		return;
	}

	else if (empty($result) === true)
	{
		http_response_code(204);
		return;
	}

	return ArrestDB::Reply($result);
});

ArrestDB::Serve('GET', '/(#any)/(#any)/(#any)', function ($table, $id, $data)
{
	$query = array
	(
		sprintf('SELECT * FROM "%s"', $table),
		sprintf('WHERE "%s" %s ?', $id, (ctype_digit($data) === true) ? '=' : 'LIKE'),
	);

	if (isset($_GET['by']) === true)
	{
		if (isset($_GET['order']) !== true)
		{
			$_GET['order'] = 'ASC';
		}

		$query[] = sprintf('ORDER BY "%s" %s', $_GET['by'], $_GET['order']);
	}

	if (isset($_GET['limit']) === true)
	{
		$query[] = sprintf('LIMIT %u', $_GET['limit']);

		if (isset($_GET['offset']) === true)
		{
			$query[] = sprintf('OFFSET %u', $_GET['offset']);
		}
	}

	$query = sprintf('%s;', implode(' ', $query));
	$result = ArrestDB::Query($query, $data);

	if ($result === false)
	{
		http_response_code(404);
		return;
	}

	else if (empty($result) === true)
	{
		http_response_code(204);
		return;
	}

	return ArrestDB::Reply($result);
});


ArrestDB::Serve('GET', '/(#any)/(#num)?', function ($table, $id = null)
{
	$arguments = array();
	$query = array
	(
		sprintf('SELECT %s FROM "%s"', isset($_GET['count']) === true ? 'COUNT(*) as count' : '*', $table),
	);

	if (isset($id) === true)
	{
		$query[] = sprintf('WHERE "%s" = ? LIMIT 1', 'id');
		$arguments[] = $id;
	}

	else
	{
		$where = array();
		foreach($_GET as $k => $v) {
			$filter = explode(",", $k);
			if ($filter[0] === 'filter') {
				switch ($filter[1]) {
					case 'like':
						$where[] = sprintf('%s LIKE ?', $filter[2]);
						$arguments[] = $filter[3];
						break;
					case 'min':
						$where[] = sprintf('%s >= ?', $filter[2]);
						$arguments[] = $filter[3];
						break;
					case 'max':
						$where[] = sprintf('%s <= ?', $filter[2]);
						$arguments[] = $filter[3];
						break;
					default:
						http_response_code(400);
						exit('Malformed filter');
				}
			}
		}
		if (count($where)) {
			$query[] = sprintf('WHERE %s', implode(' AND ', $where));
		}

		if (isset($_GET['by']) === true)
		{
			if (isset($_GET['order']) !== true)
			{
				$_GET['order'] = 'ASC';
			}

			$query[] = sprintf('ORDER BY "%s" %s', $_GET['by'], $_GET['order']);
		}

		if (isset($_GET['limit']) === true)
		{
			$query[] = sprintf('LIMIT %u', $_GET['limit']);

			if (isset($_GET['offset']) === true)
			{
				$query[] = sprintf('OFFSET %u', $_GET['offset']);
			}
		}
	}

	$query = sprintf('%s;', implode(' ', $query));
	if (count($arguments))
	{
		array_unshift($arguments, $query);
		$result = call_user_func_array('ArrestDB::Query', $arguments);
	}
	else
	{
		$result = ArrestDB::Query($query);
	}

	if ($result === false)
	{
		http_response_code(404);
		return;
	}

	else if (empty($result) === true)
	{
		http_response_code(204);
		return;
	}

	else if (isset($id) === true || isset($_GET['count']) === true)
	{
		$result = array_shift($result);
	}

	return ArrestDB::Reply($result);
});

ArrestDB::Serve('DELETE', '/(#any)/(#num)', function ($table, $id)
{
	$query = array
	(
		sprintf('DELETE FROM "%s" WHERE "%s" = ?', $table, 'id'),
	);

	$query = sprintf('%s;', implode(' ', $query));
	$result = ArrestDB::Query($query, $id);

	if ($result === false)
	{
		http_response_code(404);
		return;
	}

	else if (empty($result) === true)
	{
		http_response_code(204);
		return;
	}

	else
	{
		$result = ArrestDB::$HTTP[200];
	}

	return ArrestDB::Reply($result);
});

if (in_array($http = strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT']) === true)
{
	if (preg_match('~^\x78[\x01\x5E\x9C\xDA]~', $data = file_get_contents('php://input')) > 0)
	{
		$data = gzuncompress($data);
	}

	if ((array_key_exists('CONTENT_TYPE', $_SERVER) === true) && (empty($data) !== true))
	{
		if (strncasecmp($_SERVER['CONTENT_TYPE'], 'application/json', 16) === 0)
		{
			$GLOBALS['_' . $http] = json_decode($data, true);
		}

		else if ((strncasecmp($_SERVER['CONTENT_TYPE'], 'application/x-www-form-urlencoded', 33) === 0) && (strncasecmp($_SERVER['REQUEST_METHOD'], 'PUT', 3) === 0))
		{
			parse_str($data, $GLOBALS['_' . $http]);
		}
	}

	if ((isset($GLOBALS['_' . $http]) !== true) || (is_array($GLOBALS['_' . $http]) !== true))
	{
		$GLOBALS['_' . $http] = [];
	}

	unset($data);
}

ArrestDB::Serve('POST', '/(#any)', function ($table)
{
	if (empty($_POST) === true || is_array($_POST) !== true)
	{
		http_response_code(400);
		return false;
	}

	$queries = [];

	if (count($_POST) == count($_POST, COUNT_RECURSIVE))
	{
		$_POST = [$_POST];
	}

	foreach ($_POST as $row)
	{
		$data = [];

		foreach ($row as $key => $value)
		{
			$data[sprintf('"%s"', $key)] = $value;
		}

		$query = array
		(
			sprintf('INSERT INTO "%s" (%s) VALUES (%s)', $table, implode(', ', array_keys($data)), implode(', ', array_fill(0, count($data), '?'))),
		);

		$queries[] = array
		(
			sprintf('%s;', implode(' ', $query)),
			$data,
		);
	}

	$ids = array();
	$expected = count($queries);
	if ($expected > 1)
	{

		ArrestDB::Query()->beginTransaction();

		while (is_null($query = array_shift($queries)) !== true)
		{
			$result = ArrestDB::Query($query[0], $query[1]);
			if ($result === false)
			{
				break;
			}
			array_push($ids, $result);
		}

		if (ArrestDB::Query()->inTransaction() === true)
		{
			if ($expected !== count($ids))
			{
				ArrestDB::Query()->rollBack();
			}
			else
			{
				ArrestDB::Query()->commit();
			}
		}

	}

	else if (is_null($query = array_shift($queries)) !== true)
	{
		$result = ArrestDB::Query($query[0], $query[1]);
		if ($result !== false)
		{
			array_push($ids, $result);
		}
	}

	if ($expected !== count($ids))
	{
		http_response_code(500);
		$result = [ 'error' => sprintf('Not all rows inserted.  Expected %d.  Got %d', $expected, count($ids)) ];
		for($i = 0; $i < count($ids); $i++)
		{
			array_push($result, [$i => $ids[$i]]);
		}
	}

	else
	{
		http_response_code(201);
		if (count($ids) === 1)
		{
			header(sprintf('Location: %s/%s', $_SERVER['PHP_SELF'], $result));
		}

		$query = sprintf('SELECT * FROM "%s" WHERE id in (%s)', $table, implode(', ', $ids));
		$result = ArrestDB::Query($query);
		if ($result === false)
		{
			http_response_code(500);
			$result = [ 'error' => 'Error retrieving inserted rows.' ];
		}
		else if (count($ids) === 1)
		{
			$result = array_shift($result);
		}
	}

	return ArrestDB::Reply($result);
});

ArrestDB::Serve('PUT', '/(#any)/(#num)', function ($table, $id)
{
	if (empty($GLOBALS['_PUT']) === true || is_array($GLOBALS['_PUT']) !== true)
	{
		http_response_code(400);
		return false;
	}

	$data = [];

	foreach ($GLOBALS['_PUT'] as $key => $value)
	{
		$data[$key] = sprintf('"%s" = ?', $key);
	}

	$query = array
	(
		sprintf('UPDATE "%s" SET %s WHERE "%s" = ?', $table, implode(', ', $data), 'id'),
	);

	$query = sprintf('%s;', implode(' ', $query));
	$result = ArrestDB::Query($query, $GLOBALS['_PUT'], $id);

	if ($result === false)
	{
		http_response_code(404);
		return;
	}

	else
	{
		//$result = ArrestDB::$HTTP[200];
	}

	return ArrestDB::Reply($result);
});

http_response_code(400);
exit('Request failed to match');
?>
