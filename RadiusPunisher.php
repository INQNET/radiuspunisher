#!/usr/bin/php
<?php

if (current(get_included_files()) == __FILE__) {
    RadiusPunisher::factory()->punish();
}

class RadiusPunisher {
    /**
     * Command line options
     *
     * @var array
     */
    static $options = array(
        'h' => array(
            'desc' => 'radius server host/ip',
            'long' => 'host',
            'opt' => true,
            'def' => 'localhost',
            'arg' => 'required',
        ),
        's' => array(
            'desc' => 'radius server secret',
            'long' => 'secret',
            'opt' => true,
            'arg' => 'required',
        ),
        't' => array(
            'desc' => 'request time out (default: 1)',
            'long' => 'timeout',
            'opt' => true,
            'def' => 1,
            'arg' => 'required',
        ),
        'm' => array(
            'desc' => 'count of tries sending the request',
            'long' => 'maxtries',
            'opt' => true,
            'def' => 1,
            'arg' => 'required',
        ),
        'c' => array(
            'desc' => 'concurrency (count of child processes',
            'long' => 'concurrency',
            'opt' => true,
            'arg' => 'required',
            'def' => 10,
        ),
        'r' => array(
            'desc' => 'count of requests to send per child process (0 means infinite)',
            'long' => 'requests',
            'opt' => true,
            'def' => 1000,
            'arg' => 'required',
        ),
        'i' => array(
            'desc' => 'startover intervall in seconds (-1 means immediatly)',
            'long' => 'intervall',
            'opt' => true,
            'arg' => 'required',
        ),
        'a' => array(
            'desc' => 'access to accounting requests percentage (0-100)',
            'long' => 'aratio',
            'opt' => true,
            'def' => 50,
            'arg' => 'required',
        ),
        'v' => array(
            'desc' => 'verbosity',
            'long' => 'verbose',
            'opt' => true,
            'arg' => 'flag',
        ),
        'R' => array(
            'desc' => 'realm',
            'long' => 'realm',
            'opt' => true,
            'arg' => 'required',
            'def' => 'realm',
        )
    );

    /**
     * Extracted default options
     *
     * @var array
     */
    private static $defaultOptions = array();

    /**
     * Parsed command line options
     *
     * @var array
     */
    private static $parsedOptions = array();

    /**
     * How many requests a child handles
     *
     * @var int
     */
    public $requestsPerChild = 1000;

    /**
     * Number of child processes
     *
     * @var int
     */
    public $concurrency = 10;

    /**
     * Verbosity of output messages (0 == none)
     *
     * @var int
     */
    public $verbosity = 0;

    /**
     * Startover intervall in seconds
     *
     * @var int
     */
    public $startoverIntervall;

    /**
     * Access/Accounting request ratio
     *
     * @var integer
     */
    public $accessToAccountingRatio = 50;

    /**
     * Radius servers
     *
     * @var array
     */
    protected $servers = array();

    /**
     * Child processes
     *
     * @var array
     */
    protected $childs = array();

    /**
     * Parse argv
     *
     * @return array
     */
    protected static function parseOptions() {
        if (empty(self::$parsedOptions)) {
            $short = '';
            $long = array();
            foreach (self::$options as $key => $o) {
                $short .= $key;
                $long_opt = $o['long'];

                switch ($o['arg']) {
                    case 'flag':
                        break;
                    case 'optional':
                    default:
                        if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
                            $short .= ':';
                            $long_opt .= ':';
                        }
                    case 'required':
                        $short .= ':';
                        $long_opt .= ':';
                        break;
                }

                $long[] = $long_opt;

                if (isset($o['def'])) {
                    self::$defaultOptions[$key] = $o['def'];
                }
            }

            if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
                $opt = getopt($short, $long);
            } else {
                $opt = getopt($short);
            }

            foreach (self::$options as $key => $o) {
                if (empty($o['opt']) && !isset($opt[$key]) && !isset($opt[$o['long']])) {
                    fprintf(STDERR, "ERROR: value required for option '%s'\n", $o['desc']);
                    self::usage();
                    exit -1;
                }
            }

            self::$parsedOptions = $opt;
        }

        return self::$parsedOptions;
    }

    /**
     * GETOPT wrapper
     *
     * @return mixed
     */
    static function getOption($short) {
        $opt = self::parseOptions();
        if (isset($opt[$short], $opt[self::$options[$short]['long']])) {
            return array_merge($opt[$short], $opt[self::$options[$short]['long']]);
        } elseif (isset($opt[$short])) {
            return $opt[$short];
        } elseif (isset($opt[self::$options[$short]['long']])) {
            return $opt[self::$options[$short]['long']];
        } elseif (isset(self::$defaultOptions[$short])) {
            return self::$defaultOptions[$short];
        }
        return null;
    }

    /**
     * Usage helper
     *
     */
    static function usage() {
        printf("Usage:\n\t%s OPTIONS\n", $_SERVER['argv'][0]);
        printf("Options:\n");
        foreach (self::$options as $key => $o) {
            printf("\t");

            $opt='';
            empty($o['opt']) or $opt .= sprintf("[ ");
            $opt .= sprintf("-%s | --%s", $key, $o['long']);

            if ($o['arg'] != 'flag') {
                $o['arg']=='required' or $opt .= sprintf("[");
                $opt .= sprintf("=%s", $o['def']);
                $o['arg']=='required' or $opt .= sprintf("]");
            }

            empty($o['opt']) or $opt .= sprintf(" ]");

            printf("%-32s %s\n", $opt, $o['desc']);
        }

        printf("\n");
    }

    /**
     * Debug output
     *
     */
    protected function debug() {
        $args = func_get_args();

        if ($this->verbosity >= $args[0]) {
            if ($this->verbosity >= 2) {
                $args[1] = sprintf("%10.6f #%d ", microtime(true), $args[0]) . $args[1];
            }
            $args[0] = STDERR;
            return call_user_func_array('fprintf', $args);
        }
        return true;
    }

    /**
     * Debug child requests stats
     *
     * @param array $stats
     */
    protected function debugStats($level, array $stats) {
        if ($this->verbosity >= $level) {
            $message = sprintf("requests completed=%d", array_sum($stats));
            foreach ($stats as $code => $count) {
                if ($code) {
                    if ($count) {
                        $message .= sprintf(", rc%d=%d", $code, $count);
                    }
                } else {
                    $message .= sprintf(", failures=%d", $count);
                }
            }
            return $this->debug($level, "Child %d done with %s\n", posix_getpid(), $message);
        }
        return true;
    }

    /**
     * Create a RadiusPunisher instance
     *
     * @return RadiusPunisher
     */
    static function factory() {
        $rad = new self;

        if ($_SERVER['argc'] <= 1) {
            $rad->usage();
            exit(0);
        }

        if ($verbosity = (array)self::getOption('v')) {
            $rad->verbosity = count($verbosity);
            $rad->debug(1, "Configuring verbosity = %d\n", count($rad->verbosity));
        }
        if (strlen($requests = self::getOption('r'))) {
            $rad->debug(1, "Configuring requestsPerChild = %d\n", $requests);
            $rad->requestsPerChild = $requests;
        }
        if (strlen($concurrency = self::getOption('c'))) {
            $rad->debug(1, "Configuring concurrency = %d\n", $concurrency);
            $rad->concurrency = $concurrency;
        }
        if (strlen($intervall = self::getOption('i'))) {
            $rad->debug(1, "Configuring startoverIntervall = %d\n", $intervall);
            $rad->startoverIntervall = $intervall;
        }
        if (strlen($aratio = self::getOption('a'))) {
            $rad->debug(1, "Configuring accessToAccountingRatio = %d\n", $aratio);
            $rad->accessToAccountingRatio = $aratio;
        }

        $srv = self::getOption('h');
        $opt = array(
            's' => self::getOption('s'),
            't' => self::getOption('t'),
            'm' => self::getOption('m'),
        );

        foreach ((array) $srv as $i => $h) {
            $s = is_array($opt['s']) ? (isset($opt['s'][$i]) ? $opt['s'][$i] : $opt['s'][0]) : $opt['s'];
            $t = is_array($opt['t']) ? (isset($opt['t'][$i]) ? $opt['t'][$i] : $opt['t'][0]) : $opt['t'];
            $m = is_array($opt['m']) ? (isset($opt['m'][$i]) ? $opt['m'][$i] : $opt['m'][0]) : $opt['m'];
            $rad->debug(1, "Configuring endpoint = host: '%s', secret: '%s', timeout: %d, max_tries: %d\n", $h, $s, $t, $m);
            $rad->addServer($h, $s, $t, $m);
        }

        return $rad;
    }

    /**
     * Add a radius endpoint (server)
     *
     * @param string $host
     * @param string $secret
     * @param int $timeout
     * @param int $max_tries
     */
    function addServer($host, $secret = '', $timeout = 1, $max_tries = 1) {
        $this->servers[] = (object) compact(
            'host',
            'secret',
            'timeout',
            'max_tries'
        );
    }

    /**
     * Create an "Access Request"
     *
     * @return resource
     */
    function createAccessRequest() {
        $this->debug(4, "Child %d creating access request\n", posix_getpid());
        if (!$rad = radius_auth_open()) {
            throw new Exception("could not create radius handle");
        }
        foreach ($this->servers as $s) {
            radius_add_server($rad, $s->host, 1812, $s->secret, $s->timeout, $s->max_tries);
        }
        if (!radius_create_request($rad, RADIUS_ACCESS_REQUEST)) {
            throw new Exception(radius_strerror($rad));
        }

        radius_put_string($rad, RADIUS_USER_NAME, sprintf("%d@%s", rand(1, 1000000), $this->getOption('R')));
        radius_put_string($rad, RADIUS_USER_PASSWORD, "abcdef");
        radius_put_addr($rad, RADIUS_NAS_IP_ADDRESS, '1.1.1.1');

        return $rad;
    }

    /**
     * Create an "Accounting Request"
     *
     * @return resource
     */
    function createAccountingRequest() {
        $this->debug(4, "Child %d creating accounting request\n", posix_getpid());
        if (!$rad = radius_acct_open()) {
            throw new Exception("could not create radius handle");
        }
        foreach ($this->servers as $s) {
            radius_add_server($rad, $s->host, 1813, $s->secret, $s->timeout, $s->max_tries);
        }
        if (!radius_create_request($rad, RADIUS_ACCOUNTING_REQUEST)) {
            throw new Exception(radius_strerror($rad));
        }

        radius_put_int($rad, RADIUS_ACCT_STATUS_TYPE, RADIUS_STOP);
        radius_put_string($rad, RADIUS_ACCT_SESSION_ID, microtime(true).'@'.$this->getOption('R'));
        radius_put_addr($rad, RADIUS_NAS_IP_ADDRESS, '1.1.1.1');
        radius_put_addr($rad, RADIUS_FRAMED_IP_ADDRESS, '127.0.0.1');
        radius_put_string($rad, RADIUS_USER_NAME, sprintf("%d@%s", rand(1, 1000000), $this->getOption('R')));
        radius_put_int($rad, RADIUS_ACCT_INPUT_OCTETS, 0);
        radius_put_int($rad, RADIUS_ACCT_OUTPUT_OCTETS, rand(0,1000000));
        radius_put_int($rad, RADIUS_ACCT_SESSION_TIME, rand(0,1000));
        radius_put_int($rad, RADIUS_IDLE_TIMEOUT, 0);
        radius_put_int($rad, RADIUS_ACCT_OUTPUT_PACKETS, 0);
        radius_put_int($rad, RADIUS_ACCT_LINK_COUNT, 0);

        return $rad;
    }

    function interrupt() {
        $this->debug(1, "Child %d interrupted\n", posix_getpid());
        $this->requestsPerChild = -1;
    }

    /**
     * Fork a child process for sending requests
     *
     * @param int $requests
     * @return bool true if this is the child process
     */
    function fork() {
        switch ($pid = pcntl_fork()) {
            case -1:
                throw new Exception("could not fork");
                break;

            case 0:
                pcntl_signal(SIGINT, array($this, 'interrupt'), true);
                return $this->send();
                break;

            default:
                $this->debug(2, "Child %d forked\n", $pid);
                $this->childs[$pid] = $pid;
                break;
        }
        return false;
    }

    function send() {
        do {
            $this->debug(2, "Child %d starts sending requests\n", posix_getpid());
            $ratio = $this->accessToAccountingRatio && $this->accessToAccountingRatio!=100 ? 100/(100-$this->accessToAccountingRatio) : 0;
            $state = $ratio;
            $stats = array(0, 0, 0, 0, 0, 0);


            for ($i = 1; $this->requestsPerChild == 0 or $i <= $this->requestsPerChild; ++$i) {
                declare(ticks=1);

                $this->debug(3, "Child %d creating request %d\n", posix_getpid(), $i);

                if ($ratio && $i < $state) {
                    $rad = $this->createAccessRequest();
                } else {
                    $rad = $this->createAccountingRequest();
                    $state += $ratio;
                }

                ++$stats[radius_send_request($rad)];
                radius_close($rad);

                $this->debug(3, "Child %d finished request %d\n", posix_getpid(), $i);
            }
            $this->debugStats(2, $stats);
        } while($this->startover());

        return true;
    }

    /**
     * Wait for any child to exit
     *
     */
    function wait() {
        pcntl_signal(SIGINT, SIG_IGN);
        switch ($pid = pcntl_wait($status)) {
            case -1:
                throw new Exception("wait() returned error");
                break;
            case 0:
                break;
            default:
                unset($this->childs[$pid]);

                switch (true) {
                    case pcntl_wifexited($status):
                        $this->debug(2, "Child %d exited with status %d\n", $pid, pcntl_wexitstatus($status));
                        break;
                    case pcntl_wifsignaled($status):
                        $this->debug(2, "Child %d got term signal %d\n", $pid, pcntl_wtermsig($status));
                        break;
                    case pcntl_wifstopped($status):
                        $this->debug(2, "Child %d stopped by signal %d\n", $pid, pcntl_wstopsig($status));
                        break;
                    default:
                        $this->debug(2, "Child %d has unknown status %d\n", $pid, $status);
                        break;
                }
                break;
        }
    }

    function startover() {
        switch ($this->startoverIntervall) {
            case 0:
                return false;
            case -1:
                $this->debug(2, "Starting over immediatly\n");
                return true;
            default:
                $this->debug(2, "Starting over in %d seconds\n", $this->startoverIntervall);
                sleep($this->startoverIntervall);
                return true;
        }
    }

    /**
     * Punish the radius server(s)
     *
     */
    function punish() {
        $this->debug(1, "Creating %d worker childs\n", $this->concurrency);

        for ($i = 0; $i < $this->concurrency; ++$i) {
            if ($this->fork()) {
                return;
            }
        }

        $this->debug(1, "Forking completed; gonna wait now...\n");

        while (count($this->childs)) {
            $this->wait();
        }

        $this->debug(1, "Done\n");
    }
}

?>
