<?php
/**
 * The MIT License
 * http://creativecommons.org/licenses/MIT/
    *
 * ArrestDB 1.9.0 (github.com/alixaxel/ArrestDB/)
 * Copyright (c) 2014 Alix Axel <alix.axel@gmail.com>
    *
 * Hackery (C) 2015 Peter L Jones <pljones@users.sf.net>
    *
**/

require "rest/db_strings.inc";
require "rest/ArrestDB.php";
ArrestDB::SetOption('MYSQL_NOTRDQUOTE', true);
ArrestDB::SetOption('RETURN_RAW', true);

$dsn = "mysql://$mysql_user:$mysql_password@$mysql_hostname/$mysql_schema";
$ARCHIVE_DIR = '/tmp/inge-diet/archive';
$SYMLINK = dirname(__FILE__) . '/archive';
$ARCHIVE_URI_PREFIX = dirname($_SERVER['SCRIPT_NAME']) . '/archive';

if (!is_dir($ARCHIVE_DIR)) {
    $mask = umask(0);
    mkdir($ARCHIVE_DIR, 01777, true);
    $stat = stat($ARCHIVE_DIR);
    if ($stat['mode'] !== 01777) {
        chmod($ARCHIVE_DIR, 01777);
    }
    umask($mask);
}
if (file_exists($SYMLINK) && !is_link($SYMLINK)) {
    unlink($SYMLINK);
}
if (!file_exists($SYMLINK)) {
    symlink($ARCHIVE_DIR, $SYMLINK);
}



// Connect to the database (this is horrid - should be a different method!)
if (ArrestDB::Query($dsn) === false)
{
    http_response_code(503);
	exit('Cannot connect to database');
}

ArrestDB::Serve('GET', '/', function () {
    global $ARCHIVE_DIR, $SYMLINK, $ARCHIVE_URI_PREFIX;

    $archiveList = scandir($ARCHIVE_DIR);
    if ($archiveList === false)
    {
        http_response_code(503);
        exit();
    }
    $response = Array();
    foreach($archiveList as $archiveEntry)
    {
        if (is_file(sprintf("%s/%s", $ARCHIVE_DIR, $archiveEntry)) && (strrpos($archiveEntry, '.sql') === (strlen($archiveEntry) - 4)))
        {
            $response[] = $ARCHIVE_URI_PREFIX . '/' . $archiveEntry;
        }
    }
    
    http_response_code(200);
	return ArrestDB::Reply($response);
});

ArrestDB::Serve('GET', '/(#any)', function () {
    
    if (!isset($_GET['action'])) {
        http_response_code(400);
        exit('Required argument omitted');
    }

    http_response_code(400);
    exit('Not yet implemented');

});

ArrestDB::Serve('POST', '/', function () {
    global $ARCHIVE_DIR, $SYMLINK, $ARCHIVE_URI_PREFIX;

    if (!isset($_GET['archive'], $_GET['start_date'], $_GET['end_date'])) {
        http_response_code(400);
        exit('Required argument omitted');
    }

    $query = <<<'SQL'
        SELECT
            'INSERT INTO diet_entries (id, food_id, quantity, entry_date) VALUES'

        UNION ALL

        SELECT
            CONCAT(
                '(',
                id,
                ', ',
                food_id,
                ', ',
                quantity,
                ", '",
                entry_date,
                " 00:00:00.000'),"
            )
        FROM
            diet_entries d
        WHERE
            d.entry_date >= ? AND
            d.entry_date <= ?

        INTO OUTFILE
            '%s'
            LINES TERMINATED BY '\n'
    ;
SQL;
    $start_date = preg_replace('[^-0-9]', '', $_GET['start_date']);
    $end_date   = preg_replace('[^-0-9]', '', $_GET['end_date']);
    $archive    = sprintf('%s/%s-%s-%s-%s.sql', $SYMLINK, preg_replace('[^A-Za-z0-9_]', '', $_GET['archive']), str_replace('-', '', $start_date), str_replace('-', '', $end_date), date('YmdHid'));

    $query = sprintf(preg_replace('/\s\s+/', ' ', (implode(' ', explode("\n", $query)))), $archive);
    $arguments = [ $start_date, $end_date ];

    $result = ArrestDB::Query($query, $arguments);
	if ($result === false)
	{
        http_response_code(404);
        return;
	}

    $rowCount = $result['result']->rowCount() - 1;
    if ($rowCount < 1) {
        try { unlink($archive); } catch (Exception $e) {
            syslog(LOG_DEBUG, 'Unable to delete ' . $archive);
        }
        http_response_code(404);
        return;
    }

    $result = ArrestDB::Query('BEGIN');
	if ($result === false)
	{
        http_response_code(400);
        exit('Unable to BEGIN transaction');
	}

    $query = <<<'SQL'
        DELETE FROM
            diet_entries
        WHERE
            entry_date >= ? AND
            entry_date <= ?
        ;
SQL;
    $query = preg_replace('/\s\s+/', ' ', (implode(' ', explode("\n", $query))));

    try
    {
        $result = ArrestDB::Query($query, $arguments);
        if ($result === false)
        {
            http_response_code(400);
            return;
        }
        if ($rowCount != $result['result']->rowCount())
        {
            syslog(LOG_ERR, sprintf('Archived unloaded %d rows but delete removed %d rows - rolling back.', $rowCount, $result['result']->rowCount()));
            $result = ArrestDB::Query('ROLLBACK');
            if ($result === false)
            {
                http_response_code(400);
                exit('Unable to ROLLBACK transaction!! Oh noes...');
            }
        }
        $result = ArrestDB::Query('COMMIT');
        if ($result === false)
        {
            http_response_code(400);
            exit('Unable to COMMIT transaction');
        }
    }
    catch (Exception $e)
    {
        $result = ArrestDB::Query('ROLLBACK');
        if ($result === false)
        {
            http_response_code(400);
            exit('Unable to ROLLBACK transaction!! Oh noes...');
        }
    }

    http_response_code(201);
    header(sprintf('Location: %s/%s', $ARCHIVE_URI_PREFIX, basename($archive)));
	return ArrestDB::Reply(['count' => $rowCount]);
});

ArrestDB::Serve('DELETE', '/(#any)', function () {
    
    http_response_code(400);
    exit('Not yet implemented');

});

http_response_code(400);
exit('Request failed to match');
?>
