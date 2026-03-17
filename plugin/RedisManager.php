<?php

/**
 * Class RedisManager
 *
 * @category        Plugin (cPanel)
 * @author          Atik Rahman <ar[at]atikrahman.com>
 * @version         v2.0
 * @link            https://github.com/windsofchange/redis-cpanel-plugin
 * @license         http://www.apache.org/licenses/LICENSE-2.0
 *
 * v2.0 Changes:
 *  - Auto-detect valkey-server first, fallback to redis-server
 *  - Fixed hardcoded /bin/redis-server path
 *  - Config file permissions 0600 (was 0644)
 *  - Log directory permissions 0700 (was 0755)
 *  - Added maxmemory-policy allkeys-lru to generated config
 *  - Added save "" to disable RDB persistence (pure cache mode)
 *  - Added loglevel notice, protected-mode yes to generated config
 *  - Added Unix socket alongside TCP port for low-latency local connections
 *  - escapeshellarg() on all shell_exec/shell input paths
 *  - PID process-name validation before sending SIGTERM
 *  - Cron changed from * * * * * to @reboot (special=reboot) via UAPI
 *  - cronExists() check prevents duplicate cron entries
 *  - Password increased to 32 hex chars (16 random bytes)
 *  - New getStatus() returns structured array (replaces echo-based checkRedisStatus)
 *  - New getConnectionInfo() returns WordPress/Laravel/PHP code snippets
 *  - New resetRedis() for full instance teardown and cleanup
 *  - configDir created with 0750 permissions
 */

class RedisManager
{
    private $cpanel;
    private $configDir;
    private $configFile;
    private $logDir;
    private $logFile;
    private $redisServer;
    private $userRedisDir;
    private $pidFile;
    private $socketFile;

    /** Allowed maxmemory values in MB: 63 MB default, then 32 MB increments to 512 MB */
    public static $MEMORY_OPTIONS_MB = [63, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448, 480, 512];
    public static $MEMORY_DEFAULT_MB = 63;

    public  $homeDir;
    public  $username;
    public  $userdetails;

    public function __construct($cpanel)
    {
        $this->cpanel      = $cpanel;
        $userData          = $this->getAllUserData($cpanel);
        $this->userdetails = $userData;
        $this->username    = $userData['main_domain']['user'];
        $this->homeDir     = $userData['main_domain']['homedir'];
        $this->configDir   = "{$this->homeDir}/.cpanel/plugin/redis";
        $this->configFile  = "{$this->configDir}/redis.conf";
        $this->logDir      = "{$this->configDir}/log";
        $this->logFile     = "{$this->logDir}/{$this->username}.log";
        $this->userRedisDir = "{$this->configDir}/data";
        $this->pidFile     = "{$this->configDir}/redis.pid";
        $this->socketFile  = "{$this->homeDir}/.cagefs/tmp/redis.sock";
        $this->redisServer = $this->detectRedisBinary();
    }

    /**
     * Detect the best available Redis-compatible server binary.
     * Prefers valkey-server (BSD-licensed); falls back to redis-server.
     *
     * @return string Absolute path to binary
     * @throws Exception if no binary is found
     */
    private function detectRedisBinary()
    {
        foreach (['/usr/bin/valkey-server', '/usr/bin/redis-server'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        // Last-resort: ask shell (may resolve a symlink in PATH)
        $path = trim(shell_exec('command -v valkey-server 2>/dev/null || command -v redis-server 2>/dev/null'));
        if ($path && is_executable($path)) {
            return $path;
        }
        throw new Exception('No Redis-compatible server binary found. Install valkey or redis-server.');
    }

    private function getAllUserData($cpanel)
    {
        $userData = $cpanel->uapi('DomainInfo', 'domains_data', array('format' => 'hash'));

        if ($userData['cpanelresult']['result']['status'] === 1) {
            return $userData['cpanelresult']['result']['data'];
        } else {
            throw new Exception('FAILED TO RETRIEVE USER DATA: ' . json_encode($userData['cpanelresult']['result']['errors']));
        }
    }

    private function log($message)
    {
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0700, true);
        }
        file_put_contents($this->logFile, date('[Y-m-d H:i:s] ') . strtoupper($message) . PHP_EOL, FILE_APPEND);
    }

    private function findAvailablePort()
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            throw new Exception('Unable to create socket: ' . socket_strerror(socket_last_error()));
        }

        if (!socket_bind($sock, '127.0.0.1', 0)) {
            throw new Exception('Unable to bind socket: ' . socket_strerror(socket_last_error($sock)));
        }

        if (!socket_listen($sock, 1)) {
            throw new Exception('Unable to listen on socket: ' . socket_strerror(socket_last_error($sock)));
        }

        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        if ($port) {
            return $port;
        } else {
            throw new Exception('Unable to find an available port');
        }
    }

    private function createRedisConfig()
    {
        if (!file_exists($this->configFile)) {
            $this->log("CREATING NEW REDIS CONFIG FOR {$this->username}");
            $password = bin2hex(random_bytes(16)); // 32 hex chars

            if (!file_exists($this->configDir)) {
                mkdir($this->configDir, 0750, true);
            }
            chown($this->configDir, $this->username);

            if (!file_exists($this->userRedisDir)) {
                mkdir($this->userRedisDir, 0750, true);
            }
            chown($this->userRedisDir, $this->username);

            // Ensure socket directory exists (needed inside CageFS)
            $socketDir = dirname($this->socketFile);
            if (!file_exists($socketDir)) {
                mkdir($socketDir, 0700, true);
            }

            $port = $this->findAvailablePort();

            $config = [
                "# Redis/Valkey config for cPanel user: {$this->username}",
                "# Generated by redis-cpanel-plugin v2.0",
                "",
                "bind 127.0.0.1",
                "port {$port}",
                "unixsocket {$this->socketFile}",
                "unixsocketperm 660",
                "",
                "requirepass {$password}",
                "",
                "dir {$this->userRedisDir}",
                "pidfile {$this->pidFile}",
                "",
                "maxmemory " . self::$MEMORY_DEFAULT_MB . "mb",
                "maxmemory-policy allkeys-lru",
                "",
                "databases 16",
                "",
                "# Disable RDB persistence (pure cache mode)",
                "save \"\"",
                "",
                "loglevel notice",
                "logfile {$this->logFile}",
                "",
                "protected-mode yes",
                "daemonize yes",
            ];

            file_put_contents($this->configFile, implode(PHP_EOL, $config) . PHP_EOL);
            chmod($this->configFile, 0600);
            chown($this->configFile, $this->username);
        }
    }

    /**
     * Read a single directive value from the config file.
     *
     * @param string $directive e.g. 'port', 'requirepass'
     * @return string trimmed value or empty string
     */
    private function readConfig($directive)
    {
        if (!file_exists($this->configFile)) {
            return '';
        }
        $directive = escapeshellarg('^' . $directive);
        return trim(shell_exec("grep {$directive} " . escapeshellarg($this->configFile) . " | awk '{print $2}'") ?? '');
    }

    /**
     * Return structured status information about this user's Redis instance.
     *
     * @return array keys: running (bool), port, password, socket, maxmemory, databases, pid, binary
     */
    public function getStatus()
    {
        $status = [
            'running'   => false,
            'port'      => '',
            'password'  => '',
            'socket'    => $this->socketFile,
            'maxmemory' => '',
            'databases' => '',
            'pid'       => '',
            'binary'    => basename($this->redisServer),
            'configured' => file_exists($this->configFile),
        ];

        if (!$status['configured']) {
            return $status;
        }

        $status['port']      = $this->readConfig('port');
        $status['password']  = $this->readConfig('requirepass');
        $status['maxmemory'] = $this->readConfig('maxmemory');
        $status['databases'] = $this->readConfig('databases');

        if (file_exists($this->pidFile)) {
            $pid = trim(file_get_contents($this->pidFile));
            if (ctype_digit($pid) && file_exists("/proc/{$pid}")) {
                // Validate the process is actually redis/valkey, not a recycled PID
                $procName = trim(file_get_contents("/proc/{$pid}/comm") ?? '');
                if (strpos($procName, 'redis') !== false || strpos($procName, 'valkey') !== false) {
                    $status['running'] = true;
                    $status['pid']     = $pid;
                }
            }
        }

        return $status;
    }

    /**
     * Return connection code snippets for popular frameworks.
     *
     * @return array keys: php, wordpress, laravel
     */
    public function getConnectionInfo()
    {
        $status = $this->getStatus();
        if (!$status['configured']) {
            return [];
        }

        $port     = htmlspecialchars($status['port'], ENT_QUOTES, 'UTF-8');
        $password = htmlspecialchars($status['password'], ENT_QUOTES, 'UTF-8');
        $socket   = htmlspecialchars($status['socket'], ENT_QUOTES, 'UTF-8');

        return [
            'php' => <<<PHP
\$redis = new Redis();
\$redis->connect('127.0.0.1', {$port});
\$redis->auth('{$password}');
PHP
            ,
            'php_socket' => <<<PHP
\$redis = new Redis();
\$redis->connect('{$socket}');
\$redis->auth('{$password}');
PHP
            ,
            'wordpress' => <<<WP
// In wp-config.php (requires Redis Object Cache plugin)
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', {$port});
define('WP_REDIS_PASSWORD', '{$password}');
define('WP_REDIS_SCHEME', 'tcp');
WP
            ,
            'laravel' => <<<ENV
# In .env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD={$password}
REDIS_PORT={$port}
ENV
            ,
        ];
    }

    /**
     * Update maxmemory to $mb megabytes.
     * - Validates against the allowed options list.
     * - Rewrites the config file so the new value persists across restarts.
     * - Hot-applies via CONFIG SET if the instance is currently running.
     *
     * @param int $mb Desired memory in MB (must be in self::$MEMORY_OPTIONS_MB)
     * @throws Exception on invalid value or CLI failure
     */
    public function updateMaxMemory($mb)
    {
        $mb = (int)$mb;
        if (!in_array($mb, self::$MEMORY_OPTIONS_MB, true)) {
            throw new Exception("Invalid memory value: {$mb} MB. Allowed: " . implode(', ', self::$MEMORY_OPTIONS_MB));
        }

        if (!file_exists($this->configFile)) {
            throw new Exception("Redis config not found — start the instance first.");
        }

        // Rewrite maxmemory line in config file
        $conf = file_get_contents($this->configFile);
        $conf = preg_replace('/^maxmemory\s+\S+/m', "maxmemory {$mb}mb", $conf);
        file_put_contents($this->configFile, $conf);
        chmod($this->configFile, 0600);

        $this->log("UPDATED MAXMEMORY TO {$mb}mb FOR {$this->username}");

        // Hot-apply to running instance via redis-cli / valkey-cli
        $status = $this->getStatus();
        if ($status['running'] && $status['port']) {
            $cli      = str_replace('-server', '-cli', $this->redisServer);
            $port     = escapeshellarg($status['port']);
            $password = escapeshellarg($status['password']);
            $bytes    = $mb * 1024 * 1024;

            $out = shell_exec(
                escapeshellarg($cli)
                . " -p {$port} -a {$password} CONFIG SET maxmemory {$bytes} 2>&1"
            );
            $this->log("CONFIG SET maxmemory output: " . trim($out ?? ''));
        }
    }

    public function startRedis()
    {
        $this->createRedisConfig();

        $cmd    = escapeshellarg($this->redisServer) . ' ' . escapeshellarg($this->configFile) . ' 2>&1';
        $output = shell_exec($cmd);
        $this->log("REDIS START COMMAND OUTPUT: " . ($output ?? '(none)'));

        // Wait up to 10 s for the PID file to appear
        $timeout    = 10;
        $start_time = time();
        while (!file_exists($this->pidFile) && (time() - $start_time) < $timeout) {
            usleep(500000); // 0.5 s
        }

        if (file_exists($this->pidFile)) {
            $this->log("STARTED REDIS FOR {$this->username}");
            $this->addCronJob();
        } else {
            $this->log("FAILED TO START REDIS FOR {$this->username}: PID FILE NOT FOUND");
            throw new Exception("Redis failed to start. Check the log for details.");
        }
    }

    public function stopRedis()
    {
        if (!file_exists($this->pidFile)) {
            $this->log("FAILED TO STOP REDIS FOR {$this->username}: PID FILE NOT FOUND");
            return;
        }

        $pid = trim(file_get_contents($this->pidFile));

        if (!ctype_digit($pid)) {
            $this->log("FAILED TO STOP REDIS FOR {$this->username}: INVALID PID '{$pid}'");
            @unlink($this->pidFile);
            return;
        }

        // Validate process belongs to redis/valkey before sending signal
        if (file_exists("/proc/{$pid}")) {
            $procName = trim(file_get_contents("/proc/{$pid}/comm") ?? '');
            if (strpos($procName, 'redis') === false && strpos($procName, 'valkey') === false) {
                $this->log("REFUSING TO KILL PID {$pid}: process is '{$procName}', not redis/valkey");
                @unlink($this->pidFile);
                return;
            }
        }

        if (posix_kill((int)$pid, 15)) {
            // Wait briefly for graceful shutdown
            $timeout = 5;
            $start   = time();
            while (file_exists("/proc/{$pid}") && (time() - $start) < $timeout) {
                usleep(200000);
            }
            @unlink($this->pidFile);
            $this->log("STOPPED REDIS FOR {$this->username} (PID {$pid})");
            $this->removeCronJob();
        } else {
            $this->log("FAILED TO STOP REDIS FOR {$this->username}: UNABLE TO KILL PID {$pid}");
        }
    }

    /**
     * Full teardown: stop instance, remove config, data, logs, socket.
     * Used by the Reset action in the UI.
     */
    public function resetRedis()
    {
        // Stop first if running
        if (file_exists($this->pidFile)) {
            try {
                $this->stopRedis();
            } catch (Exception $e) {
                $this->log("RESET: stop failed: " . $e->getMessage());
            }
        }

        $this->removeCronJob();

        // Remove config, log, data directories
        foreach ([$this->configFile, $this->pidFile, $this->socketFile] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
        foreach ([$this->logDir, $this->userRedisDir, $this->configDir] as $dir) {
            if (is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }

        $this->log("RESET COMPLETED FOR {$this->username}");
    }

    /** Recursive directory removal */
    private function rrmdir($dir)
    {
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->rrmdir($item) : @unlink($item);
        }
        @rmdir($dir);
    }

    /**
     * Backward-compatible status check (kept for any legacy callers).
     * New code should use getStatus() instead.
     */
    public function checkRedisStatus()
    {
        $status = $this->getStatus();

        if (!$status['configured']) {
            echo "UNINITIATED";
            return;
        }

        if ($status['running']) {
            echo "RUNNING {$status['port']} {$status['password']} {$status['maxmemory']} {$status['databases']}";
        } else {
            echo "INACTIVE";
        }
    }

    private function cronCommand()
    {
        return "/usr/bin/flock -n " . escapeshellarg("{$this->configDir}/redis.lock")
             . " " . escapeshellarg($this->redisServer)
             . " " . escapeshellarg($this->configFile)
             . " >> /dev/null 2>&1";
    }

    /** Check whether the reboot cron entry already exists. */
    private function cronExists()
    {
        $cronList = $this->cpanel->uapi('Cron', 'list_lines');
        if (empty($cronList['cpanelresult']['result']['data'])) {
            return false;
        }
        foreach ($cronList['cpanelresult']['result']['data'] as $line) {
            if (isset($line['command']) && strpos($line['command'], 'redis.lock') !== false) {
                return true;
            }
        }
        return false;
    }

    private function addCronJob()
    {
        if ($this->cronExists()) {
            $this->log("CRON JOB ALREADY EXISTS, SKIPPING");
            return;
        }

        $this->cpanel->uapi(
            'Cron',
            'add_line',
            [
                'command' => $this->cronCommand(),
                'special' => 'reboot',
            ]
        );
        $this->log("ADDED @REBOOT CRON JOB FOR REDIS");
    }

    private function removeCronJob()
    {
        // UAPI Cron does not have remove_line by index; use api2 which has commandnumber
        $cronList = $this->cpanel->api2('Cron', 'fetchcron');

        if (empty($cronList['cpanelresult']['data'])) {
            return;
        }

        foreach ($cronList['cpanelresult']['data'] as $cronLine) {
            if (isset($cronLine['command']) && strpos($cronLine['command'], 'redis.lock') !== false) {
                $this->cpanel->api2(
                    'Cron',
                    'remove_line',
                    ['commandnumber' => $cronLine['commandnumber']]
                );
            }
        }
        $this->log("REMOVED CRON JOB FOR REDIS");
    }
}
