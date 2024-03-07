<?php
/**
* @file ResultsScraper.php
* @brief Scrape results from https://www.atptour.com/en/scores/results-archive
*/
require_once( 'ScraperClass.php' );

class ResultsScraper extends ScraperClass
{
    var $years = [];
    var $tournaments = [];
    var $players = [];
    var $ranks = [];

    /**
     * @brief Scraper constructor
     * @param array $arg [Command line arguments]
     */
    public function __construct($arg = null)
    {
        parent::__construct($arg);
        // Setup log file
        if (!$this->category)
            $this->scraper_log = './log/results_scraper.log';
        else
            $this->scraper_log = './log/results_'.$this->categories[0].'.log';
        if (file_exists($this->scraper_log))
            unlink($this->scraper_log);
        // Start log
        $this->logg('---------------');
        $this->logg('Scraper started');

        $this->baseurl = 'https://www.atptour.com';
        $this->entryurl = 'https://www.atptour.com/en/scores/results-archive';

        // Load players
        $this->select_players();

        foreach ($this->categories as $category)
            $this->parse_year($this->entryurl.'?year='.$category);

    }

    public function parse_catalog()
    {
        $this->logg('Parse catalog');
        $html = $this->get_page($this->entryurl);
        $this->loadDOM($html);
        // Get all years
        $this->years = [];
        foreach ( $this->xpath->query('//ul[ @id = "resultsArchiveYearDropdown" ]/li') as $node )
        {
            $year = trim($node->nodeValue);
            $this->years[] = $year;
            if ($this->debug) break;
        }
        //var_dump($this->years);
    }

    private function parse_year($url)
    {
        $this->logg('Parse year: '.$url);
        $html = $this->get_page($url);
        $this->loadDOM($html);
        // Load tournaments
        $this->select_tournaments();

        // Get all tournaments
        foreach ( $this->xpath->query('//td[ @class = "tourney-details" ]/a[ contains(., "Results") ]') as $node )
        {
            $link = $this->baseurl.$node->getAttribute("href");
            //$link = 'https://www.atptour.com/en/scores/archive/australian-open/580/2002/results';
            //$link = 'https://www.atptour.com/en/scores/archive/bstad/316/2012/results';
            //$link = 'https://www.atptour.com/en/scores/archive/australian-championships/580/1939/results';
            //$link = 'https://www.atptour.com/en/scores/archive/australian-championships/580/1925/results';
            //$link = 'https://www.atptour.com/en/scores/archive/atlanta/6116/2015/results';
            if (!strpos($link, 'archive') !== false)
                continue;
            if (in_array($link, $this->tournaments)) {
                $this->logg("Tournament $link already in DB");
                continue;
            }
            $this->parse_tournament($link);
            if ($this->debug) break;
        }
    }

    // Parse current ranks on date Y-m-d
    private function parse_ranks($date)
    {
        $this->logg('Parse ranks on date: '.$date);
        $url = $this->baseurl.'/en/rankings/singles?rankDate='.$date.'&rankRange=0-1000';
        $html = $this->get_page($url);
        $this->loadDOM($html);
        $this->ranks = [];
        foreach ($this->xpath->query('//div[ @class = "table-rankings-wrapper" ]/table/tbody') as $table) {
            foreach ($this->xpath->query('./tr', $table) as $row) {
                $name = $this->get_node_value('(./td[ @class = "player-cell" ])/a', $row);
                $this->ranks[$name] = $this->get_node_value('(./td[ @class = "rank-cell" ])', $row);
            }
            break;
        }
        //var_dump($this->ranks);
        $this->logg(count($this->ranks).' ranks found');
    }

    private function parse_tournament($url)
    {
        // Load players
        $this->select_players();

        $this->logg('Parse tournament: '.$url);
        $html = $this->get_page($url);
        $this->loadDOM($html);
        // Tournament
        $tournament = $this->get_node_value('//td[ @class = "title-content" ]/a');
        if (!$tournament) {
            $this->logg('No tournament name found', 2);
            return false;
        }
        // Location
        $location = $this->get_node_value('//span[ @class = "tourney-location" ]');
        if (!$location) {
            $this->logg('No location found', 1);
            $location = '';
        }
        // SGL
        $sgl = $this->get_node_value('(//td[ @class = "tourney-details" ])[1]');
        if (!$sgl) {
            $this->logg('No SGL found', 1);
            $sgl = '';
        }
        if (preg_match('#SGL\s*\d+#', $sgl))
            $sgl = $this->regex('#SGL\s*(\d+)#', $sgl, 1);
        else {
            $this->logg('Unknown SGL format: '.$sgl, 1);
            $sgl = '';
        }
        // Surface
        $surface = $this->get_node_value('(//td[ @class = "tourney-details" ])[2]');
        if (!$surface) {
            $this->logg('No furface found', 1);
            $surface = '';
        }
        // Dates
        $dates = $this->get_node_value('//span[ @class = "tourney-dates" ]');
        if (!$dates) {
            $this->logg('No dates found', 1);
            $dates = '';
        }
        $start_date = $this->regex('#^(\d{4}\.\d{2}.\d{2})#', $dates, 1);
        $start_date = str_replace('.', '-', $start_date);
        // Get player ranks on start date
        $this->parse_ranks($start_date);
        // Update DOM
        $this->loadDOM($html);
        // Results
        $dataset = [];
        $player_urls = [];
        foreach ($this->xpath->query('//table[ @class = "day-table" ]') as $table) {
            // Get all rounds
            $rounds = [];
            foreach ($this->xpath->query('./thead', $table) as $round_node) {
                $rounds[] = $this->get_node_value('./tr/th', $round_node);
            }
            // Get round results
            $j = 0;
            foreach ($this->xpath->query('./tbody', $table) as $tbody) {
                foreach ($this->xpath->query('./tr', $tbody) as $row) {
                    $data                = [];
                    $data['url']         = $url;
                    $data['tournament']  = $tournament;
                    $data['location']    = $location;
                    $data['dates']       = $dates;
                    $data['sgl']         = $sgl;
                    $data['surface']     = $surface;
                    $data['round']       = $rounds[$j];
                    $data['winner']      = $this->get_node_value('(./td[ @class = "day-table-name" ])[1]', $row);
                    $data['loser']       = $this->get_node_value('(./td[ @class = "day-table-name" ])[2]', $row);
                    $data['winner_url']  = $this->baseurl.$this->get_attribute('(./td[ @class = "day-table-name" ])[1]/a', 'href', $row);
                    if (!isset($data['winner_url'])) {
                        $data['winner_url'] = '';
                    }
                    $player_urls[] = $data['winner_url'];
                    $data['loser_url']   = $this->baseurl.$this->get_attribute('(./td[ @class = "day-table-name" ])[2]/a', 'href', $row);
                    if (!isset($data['loser_url'])) {
                        $data['loser_url'] = '';
                    }
                    $player_urls[] = $data['loser_url'];
                    if (isset($this->ranks[$data['winner']]))
                        $data['winner_rank'] = $this->ranks[$data['winner']];
                    else {
                        $this->logg('No rank found for player: '.$data['winner'], 1);
                        $data['winner_rank'] = '';
                    }
                    $data['winner_seed'] = $this->get_node_value('(./td[ @class = "day-table-seed" ])[1]/span', $row);
                    if (isset($this->ranks[$data['loser']]))
                        $data['loser_rank'] = $this->ranks[$data['loser']];
                    else {
                        $this->logg('No rank found for player: '.$data['loser'], 1);
                        $data['loser_rank'] = '';
                    }
                    $data['loser_seed']  = $this->get_node_value('(./td[ @class = "day-table-seed" ])[2]/span', $row);
                    $score = false;
                    $data['ret'] = '';
                    foreach ($this->xpath->query('./td[ @class = "day-table-score" ]/a', $row) as $node) {
                        $score = $this->dom->saveHTML($node);
                        $score = $this->regex('#<a.+?>([\s\S]+?)</a>#', $score, 1);
                        $data['ret'] = '';
                        if (strpos($score, '(RET)') !== false) {
                            $score = str_replace('(RET)', '', $score);
                            $data['ret'] .= 'RET ';
                        }
                        if (strpos($score, '(W/O)') !== false) {
                            $score = str_replace('(W/O)', '', $score);
                            $data['ret'] .= 'W/O ';
                        }
                        if (strpos($score, '(DEF)') !== false) {
                            $score = str_replace('(DEF)', '', $score);
                            $data['ret'] .= 'DEF ';
                        }
                        $data['ret'] = trim($data['ret']);
                        $score = str_replace('<sup>', '[', $score);
                        $score = str_replace('</sup>', ']', $score);
                        $score = trim($score);
                        break;
                    }
                    $i = 1;
                    if (!$score) {
                        $this->logg('No score found', 1);
                    }
                    else {
                        $score = trim($this->regex('#^([\d\s\[\]]+)#', $score, 1));
                        $sets  = explode(' ', $score);
                        if (count($sets) > 5) {
                            $this->logg('Unknown score format: '.$score);
                        }
                        foreach ($sets as $set) {
                            $tiebreaker = $this->regex('#\[(\d+)\]#', $set, 1);
                            $set = preg_replace('#\[\d+\]#', '', $set);
                            $winner_set = substr($set, 0, (strlen($set) + 1) / 2);
                            $loser_set = substr($set, (strlen($set) + 1) / 2, strlen($set));
                            if ($winner_set > 6 or $loser_set > 6) {    // If any score cell > 6 check scores difference
                                $diff = abs($winner_set - $loser_set);
                                if ($diff > 2) {    // too big difference try another score split
                                    $winner_set = substr($set, 0, (strlen($set) + 1) / 2 - 1);
                                    $loser_set = substr($set, (strlen($set) + 1) / 2 - 1, strlen($set));
                                }
                                $diff = abs($winner_set - $loser_set);
                                if ($diff > 2) {
                                    $this->logg("Unknown score format: $score", 2);
                                    $winner_set = 'ERR';
                                    $loser_set = 'ERR';
                                }
                            }
                            $data['winner_set'.$i] = $winner_set;
                            $data['loser_set'.$i] = $loser_set;
                            if ($tiebreaker) {
                                // Add tiebreaker to loser of the set
                                if ($data['winner_set'.$i] < $data['loser_set'.$i])
                                    $data['winner_set'.$i] .= '('.$tiebreaker.')';
                                else
                                    $data['loser_set'.$i] .= '('.$tiebreaker.')';
                            }
                            $i++;
                        }
                    }
                    while ($i <= 5) {
                        $data['winner_set'.$i] = '';
                        $data['loser_set'.$i]  = '';
                        $i++;
                    }

                    $data['unique_key'] = md5($data['tournament'].$data['dates'].$data['round'].$data['winner'].$data['loser']);
                    $dataset[]          = $data;
                }
                $j++;
            }
            break;
        }
        $this->insert_results($dataset);
        // Parse players
        foreach ($player_urls as $url) {
            $this->parse_player($url);
            if ($this->debug) break;
        }
    }

    private function insert_results($dataset)
    {
        $this->logg('Insert to results table');
        $query = "INSERT INTO results (unique_key, tournament, url, location, dates, sgl, surface, round, winner, winner_url, winner_rank, winner_seed, winner_set1, winner_set2, winner_set3, winner_set4, winner_set5, loser, loser_url, loser_rank, loser_seed, loser_set1, loser_set2, loser_set3, loser_set4, loser_set5, ret) VALUES (:unique_key, :tournament, :url, :location, :dates, :sgl, :surface, :round, :winner, :winner_url, :winner_rank, :winner_seed, :winner_set1, :winner_set2, :winner_set3, :winner_set4, :winner_set5, :loser, :loser_url, :loser_rank, :loser_seed, :loser_set1, :loser_set2, :loser_set3, :loser_set4, :loser_set5, :ret) ON DUPLICATE KEY UPDATE tournament=VALUES(tournament), url=VALUES(url), location=VALUES(location), dates=VALUES(dates), sgl=VALUES(sgl), surface=VALUES(surface), round=VALUES(round), winner=VALUES(winner), winner_url=VALUES(winner_url), winner_rank=VALUES(winner_rank), winner_seed=VALUES(winner_seed), winner_set1=VALUES(winner_set1), winner_set2=VALUES(winner_set2), winner_set3=VALUES(winner_set3), winner_set4=VALUES(winner_set4), winner_set5=VALUES(winner_set5), loser=VALUES(loser), loser_url=VALUES(loser_url), loser_rank=VALUES(loser_rank), loser_seed=VALUES(loser_seed), loser_set1=VALUES(loser_set1), loser_set2=VALUES(loser_set2), loser_set3=VALUES(loser_set3), loser_set4=VALUES(loser_set4), loser_set5=VALUES(loser_set5), ret=VALUES(ret)";
        $req = $this->db->prepare($query);
        foreach ($dataset as $data)
        {
            $dump = '';
            foreach ($data as $key => $value)
                $dump .= $key.': ['.$value.'] ';
            $this->logg($dump);
            if (!$req->execute($data)) {
                $this->logg('DB error', 2);
                $this->logg(json_encode($req->errorInfo()));
                //exit();
            }
        }
        return true;
    }

    private function parse_player($url) {
        if (in_array($url, $this->players)) {
            $this->logg("Player $url already in DB");
            return false;
        }
        $html = $this->get_page($url);
        $this->logg('Parse player details from '.$url);
        $this->loadDOM($html);
        $data = [];
        $data['url'] = $this->curl_url;
        // Player name
        $data['player'] = $this->get_node_value('//div[ @class = "player-profile-hero-name" ]');
        if (!$data['player'])
        {
            $this->logg('No player name found. Skip player', 1);
            //$this->logg('HTML: '. $this->curl_content);
            return false;
        }
        $data['player'] = $this->filter_text($data['player']);
        // Country
        $data['country'] = $this->get_node_value('//div[ @class = "player-flag-code" ]');
        if (!$data['country'])
        {
            $this->logg('No country found. Skip player', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        // DOB
        $data['dob'] = $this->get_node_value('//span[ @class = "table-birthday" ]');
        if (!$data['dob'])
        {
            $this->logg('No DOB found. Skip player', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['dob'] = $this->filter_text($data['dob']);
        $data['dob'] = $this->regex('#([\d\.]+)#', $data['dob'], 1);
        // Birthplace
        $data['birthplace'] = $this->get_node_value('//div[ contains(., "Birthplace") ]/following-sibling::div/text()');
        if (!$data['birthplace'])
        {
            $this->logg('No birthplace found. Skip player', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['birthplace'] = $this->filter_text($data['birthplace']);
        // Turned Pro
        $data['turned_pro'] = $this->get_node_value('//div[ contains(., "Turned Pro") ]/following-sibling::div/text()');
        if (!$data['turned_pro'])
        {
            $this->logg('No turned pro found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['turned_pro'] = $this->filter_text($data['turned_pro']);
        // Residence
        $data['residence'] = $this->get_node_value('//div[ contains(., "Residence") ]/following-sibling::div/text()');
        if (!$data['residence'])
        {
            $this->logg('No residence found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['residence'] = $this->filter_text($data['residence']);
        // Weight
        $data['weight'] = $this->get_node_value('//span[ @class = "table-weight-lbs" ]/text()');
        if (!$data['weight'])
        {
            $this->logg('No weight found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['weight'] = $this->filter_text($data['weight']);
        // Height
        $data['height'] = $this->get_node_value('//span[ @class = "table-height-ft" ]/text()');
        if (!$data['height'])
        {
            $this->logg('No height found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['height'] = $this->filter_text($data['height']);
        // Plays
        $data['plays'] = $this->get_node_value('//div[ contains(., "Plays") ]/following-sibling::div/text()');
        if (!$data['plays'])
        {
            $this->logg('No plays found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $data['plays'] = $this->filter_text($data['plays']);
        // Coach
        $data['coach'] = $this->get_node_value('//div[ @class = "wrap" ]/div[ contains(., "Coach") ]/following-sibling::div/text()');
        if (!$data['coach'])
        {
            $this->logg('No coach found', 1);
            //$this->logg('HTML: '. $this->curl_content);
        }
        $this->insert_player($data);
    }

    private function insert_player($data)
    {
        $this->logg('Insert to players table');
        $query = "INSERT INTO players (url, player, country, dob, birthplace, turned_pro, residence, weight, height, plays, coach) VALUES (:url, :player, :country, :dob, :birthplace, :turned_pro, :residence, :weight, :height, :plays, :coach) ON DUPLICATE KEY UPDATE player=VALUES(player), country=VALUES(country), dob=VALUES(dob), birthplace=VALUES(birthplace), turned_pro=VALUES(turned_pro), residence=VALUES(residence), weight=VALUES(weight), height=VALUES(height), plays=VALUES(plays), coach=VALUES(coach)";
        $req = $this->db->prepare($query);
        $dump = '';
        foreach ($data as $key => $value)
            $dump .= $key.': '.$value.' ';
        $this->logg($dump);
        if (!$req->execute($data)) {
            $this->logg('DB error', 2);
            $this->logg(json_encode($req->errorInfo()));
            //exit();
        }
        return true;
    }

    private function select_tournaments()
    {
        $this->logg('Select from results table');
        $query = "SELECT url FROM results";
        $req   = $this->db->query($query);
        if (!$req) {
            $this->logg('DB error');
            $this->logg(json_encode($this->db->errorInfo()));
            //exit();
        }
        $this->tournaments = [];
        foreach ($req as $row) {
            $this->tournaments[] = $row['url'];
        }
        $this->logg(count($this->tournaments)." tournaments found");
        return true;
    }

    private function select_players()
    {
        $this->logg('Select from players table');
        $query = "SELECT url FROM players";
        $req   = $this->db->query($query);
        if (!$req) {
            $this->logg('DB error');
            $this->logg(json_encode($this->db->errorInfo()));
            //exit();
        }
        $this->players = [];
        foreach ($req as $row) {
            $this->players[] = $row['url'];
        }
        $this->logg(count($this->players)." players found");
        return true;
    }

}
