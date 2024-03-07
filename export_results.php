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
    file_put_contents('log/export_results.log', $log, FILE_APPEND | LOCK_EX);
}

function export_results()
{
    global $db;
    logg('Export results');
    $query = "SELECT * FROM results ORDER BY url";
    $req   = $db->query($query);
    if (!$req) {
        logg('DB error');
        logg(json_encode($db->errorInfo()));
        exit();
    }
    $file_name = 'results.csv';
    logg('Write into CSV: '.$file_name);
    $file = fopen('export/'.$file_name, "w");
    if (!$file) {
        logg("Unable to open file ".$file_name);
        exit();
    }
    $header = [
        'Tournament',
        'Tournament_URL',
        'Location',
        'Tournamentdates',
        'SGL',
        'Surface',
        'Round',
        'Winner',
        'Winner_URL',
        'Winner_rank',
        'Winner_seed',
        'WinnerSet1',
        'WinnerSet2',
        'WinnerSet3',
        'WinnerSet4',
        'WinnerSet5',
        'Loser',
        'Loser_URL',
        'Loser_rank',
        'Loser_seed',
        'LoserSet1',
        'LoserSet2',
        'LoserSet3',
        'LoserSet4',
        'LoserSet5',
        'RET W/O DEF'
    ];
    fputcsv($file, $header);
    $cnt = 0;
    foreach ($req as $row) {
        // Prepare array to put into CSV
        $arr = [];
        $arr[] = $row['tournament'];
        $arr[] = $row['url'];
        $arr[] = $row['location'];
        $arr[] = $row['dates'];
        $arr[] = $row['sgl'];
        $arr[] = $row['surface'];
        $arr[] = $row['round'];
        $arr[] = $row['winner'];
        $arr[] = $row['winner_url'];
        $arr[] = $row['winner_rank'];
        $arr[] = $row['winner_seed'];
        $arr[] = $row['winner_set1'];
        $arr[] = $row['winner_set2'];
        $arr[] = $row['winner_set3'];
        $arr[] = $row['winner_set4'];
        $arr[] = $row['winner_set5'];
        $arr[] = $row['loser'];
        $arr[] = $row['loser_url'];
        $arr[] = $row['loser_rank'];
        $arr[] = $row['loser_seed'];
        $arr[] = $row['loser_set1'];
        $arr[] = $row['loser_set2'];
        $arr[] = $row['loser_set3'];
        $arr[] = $row['loser_set4'];
        $arr[] = $row['loser_set5'];
        $arr[] = $row['ret'];
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
export_results();
