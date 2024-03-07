<?php
ini_set("memory_limit","2048M");
set_time_limit(0);
error_reporting(E_ALL);

require_once 'db.php';

/**
 * @brief Write log into console and file
 * @param string $text  [Log message text]
 */
function logg($text)
{
    $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    echo $log;
    file_put_contents('log/export_players.log', $log, FILE_APPEND | LOCK_EX);
}

function export_players()
{
    global $db;
    logg('Export players');
    $query = "SELECT * FROM players ORDER BY player";
    $req   = $db->query($query);
    if (!$req) {
        logg('DB error');
        logg(json_encode($db->errorInfo()));
        exit();
    }
    $file_name = 'players.csv';
    logg('Write into CSV: '.$file_name);
    $file = fopen('export/'.$file_name, "w");
    if (!$file) {
        logg("Unable to open file ".$file_name);
        exit();
    }
    $header = [
        'player',
        'url',
        'country',
        'DOB',
        'Birthplace',
        'Turned Pro',
        'Residence',
        'Weight',
        'Height',
        'Plays',
        'Coach'
    ];
    fputcsv($file, $header);
    $cnt = 0;
    foreach ($req as $row) {
        // Prepare array to put into CSV
        $arr = [];
        $arr[] = $row['player'];
        $arr[] = $row['url'];
        $arr[] = $row['country'];
        $arr[] = $row['dob'];
        $arr[] = $row['birthplace'];
        $arr[] = $row['turned_pro'];
        $arr[] = $row['residence'];
        $arr[] = $row['weight'];
        $arr[] = $row['height'];
        $arr[] = $row['plays'];
        $arr[] = $row['coach'];
        fputcsv($file, $arr);
        $cnt++;
        //break;
    }
    if ($cnt == 0) {
        logg('No rows found');
        $status = 0;
    } else {
        logg($cnt." rows exported");
        $status = 1;
    }
}

$db = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
export_players();
