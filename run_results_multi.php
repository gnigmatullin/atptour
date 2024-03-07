<?php
require_once 'ResultsScraper.php';

if (!empty($argv[1])) {
    // Child thread
    $scraper = new ResultsScraper($argv);
} else {
    // Master thread
    $scraper = new ResultsScraper();
    $scraper->parse_catalog();
    $nThreads = 20;
    $queue = [];
    $k = 0;
    foreach ($scraper->years as $link) {
        $queue[$k % $nThreads][] = $link;
        $k++;
    }
    //var_dump($queue);
    for ($i = 0; $i < $nThreads; $i++) {
        $param = escapeshellarg(implode(',',$queue[$i]));
        $cmd = "php " . __FILE__ . " --category=$param >/dev/null 2>&1 &";
        echo $cmd."\r\n";
        exec($cmd);
    }
}