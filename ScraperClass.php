<?php
require_once 'db.php';

set_time_limit(0);
error_reporting(E_ERROR);

/**
 * @brief Class ScraperClass
 * @details Main scraper class
 */
class ScraperClass
{
    var $scraper_log;               ///< Log file name
    var $cookies_file;              ///< Cookies file name
    var $db;                        ///< PDO
    var $dom;                       ///< DOM for parse HTML
    var $xpath;                     ///< XPath
    var $debug = 0;                 ///< Debug option, 1 - for debug mode (write results into debug_results table)
    var $curl_url;                  ///< Current page's URL
    var $curl_content;              ///< Current page's content
    var $curl_options = [];         ///< Active CURL options
    /// CURL allowed codes
    var $curl_allowed_codes = [200, 201, 202, 203, 204, 205, 206, 207, 208, 226, 300, 301, 302, 303, 304, 305, 306, 307, 308];
    var $curl_max_attempts = 5;     ///< Max attempts to get page with CURL
    var $curl_errors = 0;           ///< CURL errors counter
    var $curl_max_errors = 10;      ///< Max allowed CURL errors before scraper will stopped
    var $items_count = 0;           ///< Scrapped items count
    var $db_errors = 0;             ///< DB errors counter
    var $db_max_errors = 10;        ///< Max allowed DB errors before scraper will stopped
    var $db_max_items = 20;         ///< Max items count before db_max_errors will cleared
    var $categories;
    var $append = 0;
    var $link;

    /**
     * @brief ScraperClass constructor
     */
    public function __construct($arguments = null)
    {
        // DB connection parameters (from db.php)
        global $db_servername;
        global $db_username;
        global $db_password;
        global $db_database;

        // Parse command line arguments
        if (count($arguments) > 1)
        {
            foreach ($arguments as $arg)
            {
                // Category name (to scrape one category)
                if (strpos($arg, '--category=') !== false)
                {
                    $this->category = $this->regex('#--category=([\S]+)#', $arg, 1);
                    $this->logg('Category: '.$this->category);
                    $this->categories = explode(',', $this->category);
                }
                // Debug mode
                else if (strpos($arg, '--debug=') !== false)
                {
                    $this->debug = $this->regex('#--debug=([\d]+)#', $arg, 1);
                    $this->logg('Debug mode: '.$this->debug);
                }
                // Append mode
                else if (strpos($arg, '--append=') !== false)
                {
                    $this->append = $this->regex('#--append=([\d]+)#', $arg, 1);
                    $this->logg('Append mode: '.$this->append);
                }
                // Link to scrape only specified page
                else if (strpos($arg, '--link=') !== false)
                {
                    $this->link = $this->regex('#--link=([\S]+)#', $arg, 1);
                    $this->logg('Link to scrape: '.$this->link);
                }
            }
        }

        // Setup default CURL options
        $this->curl_options = [
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/68.0.3440.106 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 5
        ];

        // Connect to DB
        if (!$this->nodb) {
            $this->logg('Connect to DB');
            $this->db  = new PDO("mysql:host=".$db_servername.";dbname=".$db_database, $db_username, $db_password);
        }

        $this->db->query("SET NAMES 'utf8'");
        $this->db->query("SET CHARACTER SET 'utf8'");
        $this->db->query("SET SESSION collation_connection = 'utf8_general_ci'");

        // Init DOM
        $this->dom = new DOMDocument();
    }

    /**
     * @brief ScraperClass destructor
     * @details Stop the scrapper
     */
    public function __destruct()
    {
        $this->logg('Scraper stopped');
    }

    /**
     * @brief Write log into console and log file
     * @param string $text      [Log message]
     * @param int $error_level  [Error level: 0 - message, 1 - warning, 2 - error]
     */
    public function logg($text, $error_level = 0)
    {
        switch ($error_level)
        {
            case 0: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
            case 1: $log = '[' . @date('Y-m-d H:i:s') . '] WARNING: ' . $text . PHP_EOL; break;
            case 2: $log = '[' . @date('Y-m-d H:i:s') . '] ERROR: ' . $text . PHP_EOL; break;
            default: $log = '[' . @date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL; break;
        }
        echo $log;
        file_put_contents($this->scraper_log, $log , FILE_APPEND | LOCK_EX);
    }

    /**
     * @brief Run regexp on a string and return the result
     * @param string $regex     [Regex string]
     * @param string $input     [Input string for regexp]
     * @param int $output       [0 - return matches array, 1 - return matched value]
     */
    public function regex($regex, $input, $output = 0)
    {
        $match = preg_match( $regex, $input, $matches ) ? ( strpos( $regex, '?P<' ) !== false ? $matches : $matches[ $output ] ) : false;
        if (!$match)
            $preg_error = array_flip( get_defined_constants( true )['pcre'] )[ preg_last_error() ];
        return $match;
    }

    /**
     * @brief Add custom cURL options
     * @param array $options    [Array of cURL options to add]
     */
    public function set_curl_options($options)
    {
        $this->logg('Set custom CURL options');
        foreach ($options as $key => $value) {
            $this->curl_options[$key] = $value;
        }
    }

    /**
     * @brief Remove custom cURL options
     * @param array $options    [Array of cURL options to remove]
     */
    public function unset_curl_options($options)
    {
        $this->logg('Unset custom CURL options');
        foreach ($options as $key => $value) {
            unset($this->curl_options[$key]);
        }
    }

    /**
     * @brief Set cookies usage on into cURL
     * @param string $cookies_file  [Cookies file name]
     */
    public function set_cookies($cookies_file = '')
    {
        if ($cookies_file == '') $cookies_file = 'cookies.txt';
        else $cookies_file = str_replace('/', '_', $cookies_file).'_cookies.txt';
        $this->logg('Set cookies: '.$cookies_file);
        $this->cookies_file = './cookies/'.$cookies_file;
        $this->set_curl_options([
            CURLOPT_COOKIEFILE => $this->cookies_file,
            CURLOPT_COOKIEJAR => $this->cookies_file
        ]);
    }

    /**
     * @brief Set cookies usage off into cURL
     */
    public function unset_cookies()
    {
        $this->logg('Unset cookies');
        $this->unset_curl_options([CURLOPT_COOKIEFILE => '', CURLOPT_COOKIEJAR => '']);
    }

    /**
     * @brief Load HTML page
     * @param string $url   [Page URL for loading]
     */
    public function get_page($url)
    {
        if (strpos($url, 'http') === false)
            $url = $this->baseurl.$url;
        $html = -1;
        $i = 1;
        while ($html == -1)
        {
            $html = $this->curl($url);
            $i++;
            if ($i > $this->curl_max_attempts) break;   // Max attempts retried, stop loading and goto next page
        }
        if ($html == -1) 
        {
            $this->logg("Max attempts (".$this->curl_max_attempts.") retried: ".$url, 2);
            return false;
        }
        else
            return $html;
    }

    /**
     * @brief CURL increase errors count
     */
    private function inc_curl_errors()
    {
        $this->curl_errors++;
        if ($this->curl_errors > $this->curl_max_errors)
        {
            $this->logg('Too many CURL errors. Stop scrapper', 2);
            //exit();
        }
    }

    /**
     * @brief CURL decrease errors count
     */
    private function  dec_curl_errors()
    {
        $this->curl_errors--;
        if ($this->curl_errors < 0) $this->curl_errors = 0;
    }
        
    /**
     * @brief CURL GET request
     * @param string $url   [Page URL for loading]
     */
    public function curl($url)
    {
        $this->logg("Get page $url");
        $this->curl_url = $url;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $this->curl_options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
            if (!in_array($info['http_code'], $this->curl_allowed_codes)) {
                $this->inc_curl_errors();   // Increase CURL errors count
                return -1;                  // Not allowed http code
            }
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            $this->inc_curl_errors();   // Increase CURL errors count
            return -1;                  // CURL error
        }
        curl_close($ch);
        $this->dec_curl_errors();
        $this->curl_content = $html;
        return $html;
    }

    /**
     * @brief cURL POST request
     * @param string $url           [Page URL for loading]
     * @param array $postfields     [Array of post fields]
     */
    public function post($url, $postfields)
    {
        $postfields_string = '';
        foreach ($postfields as $key => $value)
            $postfields_string .= $key.'='.urlencode($value).'&';
        $postfields_string = substr($postfields_string, 0, -1);

        $options = $this->curl_options;
        $options = $options + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields_string];

        $this->logg("Send POST $url");
        $this->curl_url = $url;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $this->logg("Response code ".$info['http_code']);
        }
        else
        {
            $err = curl_error( $ch );
            $this->logg("CURL error ".curl_errno($ch)." ".$err, 2);
            return -1;
        }
        curl_close($ch);
        $this->curl_content = $html;
        return $html;
    }

    /**
     * @brief Get DOM from HTML
     */
    public function loadDOM($html)
    {
        $this->dom->loadHTML($html);                // Load html to DOM
        $this->xpath = new DOMXPath($this->dom);    // Init XPATH
    }

    /**
     * @brief Search for XPath node and get value
     * @param string $query           [XPath for search]
     * @param node|bool $element      [Search in element, false - search in whole DOM]
     * @return bool|string            [Node value, false - node not found]
     */
    public function get_node_value($query, $element = false)
    {
        $value = false;
        if (!$element) {    // Search in all document
            foreach ($this->xpath->query($query) as $node) {
                $value = trim($node->nodeValue);
                break;
            }
        }
        else {    // Search in specified element only
            foreach ($this->xpath->query($query, $element) as $node) {
                $value = trim($node->nodeValue);
                break;
            }
        }
        return $value;
    }

    /**
     * @brief Search for XPath node and get attribute
     * @param string $query           [XPath for search]
     * @param string $attr            [Attribute for search]
     * @param node|bool $element      [Search in element, false - search in whole DOM]
     * @return bool|string            [Attribute value, false - node not found]
     */
    public function get_attribute($query, $attr, $element = false)
    {
        $attribute = false;
        if (!$element) {    // Search in all document
            foreach ($this->xpath->query($query) as $node) {
                $attribute = $node->getAttribute($attr);
                break;
            }
        }
        else {  // Search in specified element only
            foreach ($this->xpath->query($query, $element) as $node) {
                $attribute = $node->getAttribute($attr);
                break;
            }
        }
        return $attribute;
    }

    /**
     * @brief Filter text field before write to db
     * @details Remove prohibited symbols
     * @param string $text  [Text for filtering]
     * @return string       [Filtered text]
     */
    public function filter_text($text)
    {
        $text = preg_replace('#[^\w^\d^\.^\'^\,]#', ' ', $text);
        $text = preg_replace('#\s+#', ' ', $text);
        $text = preg_replace('#[\r\n]#', ' ', $text);
        $text = trim($text);
        return $text;
    }

}
