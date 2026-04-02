<?php
/**
 * RedisGUI - Single File Redis Management Interface
 * Inspired by Adminer, packed with Redis Insight features
 * Version 1.0.0
 */

session_start();
define('VERSION', '1.0.0');

// ─── Utility Functions ──────────────────────────────────────────────────────

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function j($v) { return json_encode($v); }
function rget($k, $d = null) { return $_REQUEST[$k] ?? $d; }
function pget($k, $d = null) { return $_POST[$k] ?? $d; }
function gget($k, $d = null) { return $_GET[$k] ?? $d; }

// ─── Redis Connection ────────────────────────────────────────────────────────

class RedisConnection {
    public $r = null;
    public $error = null;
    public $info = [];

    public function connect($host, $port, $password, $db, $timeout = 3) {
        if (!extension_loaded('redis') && !extension_loaded('predis')) {
            // Try socket-based pure PHP connection
            return $this->connectRaw($host, $port, $password, $db, $timeout);
        }
        if (extension_loaded('redis')) {
            return $this->connectPhpRedis($host, $port, $password, $db, $timeout);
        }
        return $this->connectRaw($host, $port, $password, $db, $timeout);
    }

    private function connectPhpRedis($host, $port, $password, $db, $timeout) {
        $r = new Redis();
        try {
            if (!@$r->connect($host, (int)$port, $timeout)) {
                $this->error = "Could not connect to $host:$port";
                return false;
            }
            if ($password && !$r->auth($password)) {
                $this->error = "Authentication failed";
                return false;
            }
            $r->select((int)$db);
            $this->r = $r;
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    private function connectRaw($host, $port, $password, $db, $timeout) {
        $fp = @fsockopen($host, (int)$port, $errno, $errstr, $timeout);
        if (!$fp) {
            $this->error = "Connection failed: $errstr ($errno)";
            return false;
        }
        $this->r = new RawRedis($fp);
        if ($password) {
            $res = $this->r->cmd('AUTH', $password);
            if ($res !== 'OK') { $this->error = "Auth failed"; fclose($fp); return false; }
        }
        $this->r->cmd('SELECT', (string)(int)$db);
        return true;
    }
}

class RawRedis {
    private $fp;
    public function __construct($fp) { $this->fp = $fp; }
    public function __destruct() { if ($this->fp) fclose($this->fp); }

    public function cmd(...$args) {
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $a) { $a = (string)$a; $cmd .= '$' . strlen($a) . "\r\n$a\r\n"; }
        fwrite($this->fp, $cmd);
        return $this->read();
    }

    private function read() {
        $line = rtrim(fgets($this->fp, 4096), "\r\n");
        if ($line === false) return null;
        $type = $line[0];
        $data = substr($line, 1);
        switch ($type) {
            case '+': return $data;
            case '-': return new RedisError($data);
            case ':': return (int)$data;
            case '$':
                if ($data == -1) return null;
                $bulk = ''; $len = (int)$data;
                while (strlen($bulk) < $len) $bulk .= fread($this->fp, $len - strlen($bulk));
                fgets($this->fp, 3); // \r\n
                return $bulk;
            case '*':
                if ($data == -1) return null;
                $arr = [];
                for ($i = 0; $i < (int)$data; $i++) $arr[] = $this->read();
                return $arr;
        }
        return null;
    }

    public function __call($name, $args) {
        return $this->cmd(strtoupper($name), ...$args);
    }
}

class RedisError {
    public $msg;
    public function __construct($m) { $this->msg = $m; }
    public function __toString() { return $this->msg; }
}

// ─── Redis Facade ────────────────────────────────────────────────────────────

class RedisFacade {
    private $r;
    private $isNative;

    public function __construct($r) {
        $this->r = $r;
        $this->isNative = ($r instanceof Redis);
    }

    public function call($cmd, ...$args) {
        if ($this->isNative) {
            $m = strtolower($cmd);
            if (method_exists($this->r, $m)) return $this->r->$m(...$args);
            return $this->r->rawCommand($cmd, ...$args);
        }
        return $this->r->cmd($cmd, ...$args);
    }

    public function isError($v) { return $v instanceof RedisError; }

    public function info($section = null) {
        $raw = $section ? $this->call('INFO', $section) : $this->call('INFO');
        if (!is_string($raw)) return [];
        $info = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (!$line || $line[0] === '#') continue;
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $info[trim($k)] = trim($v);
        }
        return $info;
    }

    public function keys($pattern) {
        $res = $this->call('KEYS', $pattern);
        return is_array($res) ? $res : [];
    }

    public function scanAll($pattern = '*', $count = 500) {
        $all = [];
        if ($this->isNative) {
            // phpredis requires $it to start as NULL; passing 0 causes it to
            // return false immediately (treating 0 as "iteration complete").
            $it = null;
            $this->r->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            while (($keys = $this->r->scan($it, $pattern, $count)) !== false) {
                if (is_array($keys)) $all = array_merge($all, $keys);
            }
            return $all;
        }
        // Raw TCP SCAN loop
        $cursor = 0;
        do {
            $res = $this->r->cmd('SCAN', (string)$cursor, 'MATCH', $pattern, 'COUNT', (string)$count);
            if (!is_array($res) || count($res) < 2) break;
            $cursor = (int)$res[0];
            if (is_array($res[1])) $all = array_merge($all, $res[1]);
        } while ($cursor !== 0);
        return $all;
    }

    public function type($key) {
        if ($this->isNative) {
            // phpredis returns integer constants, NOT strings like the raw protocol does
            static $map = null;
            if ($map === null) $map = [
                Redis::REDIS_STRING => 'string',
                Redis::REDIS_LIST   => 'list',
                Redis::REDIS_SET    => 'set',
                Redis::REDIS_ZSET   => 'zset',
                Redis::REDIS_HASH   => 'hash',
            ];
            $t = $this->r->type($key);
            return $map[$t] ?? 'none';
        }
        $t = $this->r->cmd('TYPE', $key);
        return is_string($t) ? $t : 'none';
    }

    public function ttl($key) { return $this->call('TTL', $key); }
    public function pttl($key) { return $this->call('PTTL', $key); }
    public function persist($key) { return $this->call('PERSIST', $key); }
    public function expire($key, $sec) { return $this->call('EXPIRE', $key, $sec); }
    public function del($key) { return $this->call('DEL', $key); }
    public function rename($old, $new) { return $this->call('RENAME', $old, $new); }
    public function exists($key) { return $this->call('EXISTS', $key); }
    public function dbsize() { return $this->call('DBSIZE'); }
    public function flushdb() { return $this->call('FLUSHDB'); }
    public function select($db) { return $this->call('SELECT', $db); }
    public function memory($sub, ...$args) { return $this->call('MEMORY', $sub, ...$args); }
    public function object($sub, $key) { return $this->call('OBJECT', $sub, $key); }

    // String
    public function get($key) { return $this->call('GET', $key); }
    public function set($key, $val) { return $this->call('SET', $key, $val); }
    public function setex($key, $ttl, $val) { return $this->call('SETEX', $key, $ttl, $val); }
    public function strlen($key) { return $this->call('STRLEN', $key); }

    // Hash
    public function hgetall($key) {
        if ($this->isNative) {
            // phpredis returns ['field' => 'value', ...] directly — not a flat array
            $res = $this->r->hgetall($key);
            return is_array($res) ? $res : [];
        }
        $res = $this->r->cmd('HGETALL', $key);
        if (!is_array($res)) return [];
        $out = [];
        for ($i = 0; $i < count($res); $i += 2) $out[$res[$i]] = $res[$i+1] ?? '';
        return $out;
    }
    public function hset($key, $field, $val) { return $this->call('HSET', $key, $field, $val); }
    public function hdel($key, $field) { return $this->call('HDEL', $key, $field); }
    public function hlen($key) { return $this->call('HLEN', $key); }
    public function hscan($key, $cursor, $pattern = '*', $count = 100) {
        if ($this->isNative) {
            // phpredis hscan: $it by reference, returns ['field'=>'val'] assoc or false
            $it = null;
            $this->r->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $out = [];
            while (($fields = $this->r->hscan($key, $it, $pattern, $count)) !== false) {
                if (is_array($fields)) $out = array_merge($out, $fields);
            }
            return [0, $out];
        }
        $res = $this->r->cmd('HSCAN', $key, (string)$cursor, 'MATCH', $pattern, 'COUNT', (string)$count);
        if (!is_array($res)) return [0, []];
        $fields = is_array($res[1]) ? $res[1] : [];
        $out = [];
        for ($i = 0; $i < count($fields); $i += 2) $out[$fields[$i]] = $fields[$i+1] ?? '';
        return [(int)$res[0], $out];
    }

    // List
    public function lrange($key, $s, $e) {
        $res = $this->call('LRANGE', $key, $s, $e);
        return is_array($res) ? $res : [];
    }
    public function llen($key) { return $this->call('LLEN', $key); }
    public function lset($key, $idx, $val) { return $this->call('LSET', $key, $idx, $val); }
    public function lrem($key, $count, $val) { return $this->call('LREM', $key, $count, $val); }
    public function rpush($key, $val) { return $this->call('RPUSH', $key, $val); }
    public function lpush($key, $val) { return $this->call('LPUSH', $key, $val); }

    // Set
    public function smembers($key) {
        $res = $this->call('SMEMBERS', $key);
        return is_array($res) ? $res : [];
    }
    public function scard($key) { return $this->call('SCARD', $key); }
    public function sadd($key, $member) { return $this->call('SADD', $key, $member); }
    public function srem($key, $member) { return $this->call('SREM', $key, $member); }
    public function sscan($key, $cursor, $pattern = '*', $count = 100) {
        if ($this->isNative) {
            $it = null;
            $this->r->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $out = [];
            while (($members = $this->r->sscan($key, $it, $pattern, $count)) !== false) {
                if (is_array($members)) $out = array_merge($out, array_values($members));
            }
            return [0, $out];
        }
        $res = $this->r->cmd('SSCAN', $key, (string)$cursor, 'MATCH', $pattern, 'COUNT', (string)$count);
        if (!is_array($res)) return [0, []];
        return [(int)$res[0], is_array($res[1]) ? $res[1] : []];
    }

    // ZSet
    public function zrangewithscores($key, $s, $e) {
        if ($this->isNative) {
            // phpredis zrange(..., true) returns ['member' => score] assoc, not a flat array
            $res = $this->r->zrange($key, $s, $e, true);
            if (!is_array($res)) return [];
            $out = [];
            foreach ($res as $member => $score) $out[] = ['member' => $member, 'score' => $score];
            return $out;
        }
        $res = $this->r->cmd('ZRANGE', $key, (string)$s, (string)$e, 'WITHSCORES');
        if (!is_array($res)) return [];
        $out = [];
        for ($i = 0; $i < count($res); $i += 2) $out[] = ['member' => $res[$i], 'score' => $res[$i+1] ?? 0];
        return $out;
    }
    public function zcard($key) { return $this->call('ZCARD', $key); }
    public function zadd($key, $score, $member) { return $this->call('ZADD', $key, $score, $member); }
    public function zrem($key, $member) { return $this->call('ZREM', $key, $member); }
    public function zscore($key, $member) { return $this->call('ZSCORE', $key, $member); }

    // CLI
    public function rawCmd($args) {
        if (empty($args)) return null;
        return $this->call(...$args);
    }

    public function configGet($pattern) {
        if ($this->isNative) {
            // phpredis config('GET', ...) returns ['key' => 'value'] assoc directly
            $res = $this->r->config('GET', $pattern);
            return is_array($res) ? $res : [];
        }
        $res = $this->r->cmd('CONFIG', 'GET', $pattern);
        if (!is_array($res)) return [];
        $out = [];
        for ($i = 0; $i < count($res); $i += 2) $out[$res[$i]] = $res[$i+1] ?? '';
        return $out;
    }

    public function slowlog($sub, $count = 10) {
        return $this->call('SLOWLOG', $sub, $count);
    }

    public function clientList() {
        $res = $this->call('CLIENT', 'LIST');
        // Normalize: phpredis can return a string or an array depending on version
        if (is_array($res)) {
            $lines = [];
            foreach ($res as $client) {
                if (is_array($client)) {
                    $pairs = [];
                    foreach ($client as $k => $v) $pairs[] = "$k=$v";
                    $lines[] = implode(' ', $pairs);
                } else {
                    $lines[] = (string)$client;
                }
            }
            return implode("\n", $lines);
        }
        return is_string($res) ? $res : '';
    }
}

// ─── Session / Auth ──────────────────────────────────────────────────────────

function isLoggedIn() { return !empty($_SESSION['redis_connected']); }
function getConn() { return $_SESSION['redis_conn'] ?? []; }

function loginRedis($host, $port, $pass, $db) {
    $rc = new RedisConnection();
    if ($rc->connect($host, $port, $pass, $db)) {
        $_SESSION['redis_connected'] = true;
        $_SESSION['redis_conn'] = compact('host', 'port', 'pass', 'db');
        $_SESSION['redis_driver'] = extension_loaded('redis') ? 'phpredis' : 'raw';
        return [true, null];
    }
    return [false, $rc->error];
}

function makeRedis() {
    $c = getConn();
    if (!$c) return null;
    $rc = new RedisConnection();
    if ($rc->connect($c['host'], $c['port'], $c['pass'], $c['db'])) {
        return new RedisFacade($rc->r);
    }
    return null;
}

// ─── AJAX / API Handler ──────────────────────────────────────────────────────

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = gget('action', pget('action', ''));

    if ($action === 'login') {
        [$ok, $err] = loginRedis(pget('host','127.0.0.1'), pget('port',6379), pget('pass',''), pget('db',0));
        echo j(['ok' => $ok, 'error' => $err]); exit;
    }

    if ($action === 'logout') {
        session_destroy(); echo j(['ok' => true]); exit;
    }

    if (!isLoggedIn()) { echo j(['ok' => false, 'error' => 'Not connected']); exit; }

    $redis = makeRedis();
    if (!$redis) { echo j(['ok' => false, 'error' => 'Connection lost']); exit; }

    switch ($action) {
        case 'select_db':
            $db = (int)pget('db', 0);
            $_SESSION['redis_conn']['db'] = $db;
            session_write_close();
            echo j(['ok' => true]); break;

        case 'server_info':
            $info = $redis->info();
            $dbsize = $redis->dbsize();
            // Get all DB sizes
            $dbs = [];
            for ($i = 0; $i < 16; $i++) {
                $k = "db$i";
                if (isset($info[$k])) {
                    preg_match('/keys=(\d+)/', $info[$k], $m);
                    $dbs[$i] = (int)($m[1] ?? 0);
                }
            }
            echo j(['ok' => true, 'info' => $info, 'dbsize' => $dbsize, 'dbs' => $dbs]); break;

        case 'keys':
            $pattern = pget('pattern', '*');
            $type_filter = pget('type', '');
            $page = max(0, (int)pget('page', 0));
            $per_page = 100;
            $all_keys = $redis->scanAll($pattern ?: '*');
            sort($all_keys);
            if ($type_filter) {
                $filtered = [];
                foreach ($all_keys as $k) {
                    if ($redis->type($k) === $type_filter) $filtered[] = $k;
                }
                $all_keys = $filtered;
            }
            $total = count($all_keys);
            $paged = array_slice($all_keys, $page * $per_page, $per_page);
            $with_meta = [];
            foreach ($paged as $k) {
                $t = $redis->type($k);
                $ttl = $redis->ttl($k);
                $size = 0;
                try { $size = $redis->memory('USAGE', $k) ?: 0; } catch(Exception $e) {}
                $with_meta[] = ['key' => $k, 'type' => $t, 'ttl' => $ttl, 'size' => $size];
            }
            echo j(['ok' => true, 'keys' => $with_meta, 'total' => $total, 'page' => $page, 'per_page' => $per_page]); break;

        case 'get_key':
            $key = pget('key');
            if ($key === null) { echo j(['ok' => false, 'error' => 'No key']); break; }
            $type = $redis->type($key);
            $ttl = $redis->ttl($key);
            $pttl = $redis->pttl($key);
            $size = 0;
            try { $size = $redis->memory('USAGE', $key) ?: 0; } catch(Exception $e) {}
            $encoding = '';
            try { $encoding = $redis->object('ENCODING', $key); } catch(Exception $e) {}
            $value = null;
            $count = 0;
            switch ($type) {
                case 'string':
                    $value = $redis->get($key);
                    $count = $redis->strlen($key);
                    break;
                case 'hash':
                    $page = (int)pget('page', 0);
                    [$cur, $value] = $redis->hscan($key, 0, pget('field_pattern','*'), 500);
                    $count = $redis->hlen($key);
                    break;
                case 'list':
                    $page = (int)pget('page', 0);
                    $value = $redis->lrange($key, $page*100, $page*100+99);
                    $count = $redis->llen($key);
                    break;
                case 'set':
                    [$cur, $value] = $redis->sscan($key, 0, '*', 500);
                    $count = $redis->scard($key);
                    break;
                case 'zset':
                    $page = (int)pget('page', 0);
                    $value = $redis->zrangewithscores($key, $page*100, $page*100+99);
                    $count = $redis->zcard($key);
                    break;
            }
            echo j(['ok' => true, 'key' => $key, 'type' => $type, 'ttl' => $ttl, 'pttl' => $pttl,
                    'size' => $size, 'encoding' => $encoding, 'value' => $value, 'count' => $count]); break;

        case 'set_value':
            $key = pget('key'); $type = pget('type'); $val = pget('value');
            $ttl = (int)pget('ttl', -1);
            $ok = false; $err = '';
            switch ($type) {
                case 'string':
                    $ok = $redis->set($key, $val) === 'OK' || $redis->set($key, $val) === true;
                    break;
                case 'hash':
                    $field = pget('field');
                    $ok = $redis->hset($key, $field, $val) !== false;
                    break;
                case 'list':
                    $pos = pget('pos', 'right');
                    $ok = ($pos === 'left') ? $redis->lpush($key, $val) : $redis->rpush($key, $val);
                    $ok = $ok !== false;
                    break;
                case 'set':
                    $ok = $redis->sadd($key, $val) !== false;
                    break;
                case 'zset':
                    $score = (float)pget('score', 0);
                    $ok = $redis->zadd($key, $score, $val) !== false;
                    break;
                default: $err = 'Unknown type'; break;
            }
            if ($ok && $ttl > 0) $redis->expire($key, $ttl);
            echo j(['ok' => $ok, 'error' => $err]); break;

        case 'update_string':
            $key = pget('key'); $val = pget('value');
            $ttl = (int)pget('ttl', -1);
            if ($ttl > 0) $ok = $redis->setex($key, $ttl, $val) === 'OK' || $redis->setex($key, $ttl, $val) === true;
            else $ok = $redis->set($key, $val) === 'OK' || $redis->set($key, $val) === true;
            echo j(['ok' => $ok]); break;

        case 'update_list_item':
            $key = pget('key'); $idx = (int)pget('index'); $val = pget('value');
            $ok = $redis->lset($key, $idx, $val);
            echo j(['ok' => $ok === 'OK' || $ok === true]); break;

        case 'delete_list_item':
            $key = pget('key'); $val = pget('value');
            $ok = $redis->lrem($key, 1, $val);
            echo j(['ok' => $ok !== false]); break;

        case 'delete_hash_field':
            $key = pget('key'); $field = pget('field');
            $ok = $redis->hdel($key, $field);
            echo j(['ok' => $ok > 0]); break;

        case 'delete_set_member':
            $key = pget('key'); $member = pget('member');
            $ok = $redis->srem($key, $member);
            echo j(['ok' => $ok > 0]); break;

        case 'delete_zset_member':
            $key = pget('key'); $member = pget('member');
            $ok = $redis->zrem($key, $member);
            echo j(['ok' => $ok > 0]); break;

        case 'delete_key':
            $key = pget('key');
            $ok = $redis->del($key);
            echo j(['ok' => $ok > 0]); break;

        case 'delete_keys':
            $keys = json_decode(pget('keys', '[]'), true);
            $count = 0;
            foreach ($keys as $k) $count += (int)$redis->del($k);
            echo j(['ok' => true, 'deleted' => $count]); break;

        case 'rename_key':
            $old = pget('old'); $new = pget('new');
            $res = $redis->rename($old, $new);
            $ok = $res === 'OK' || $res === true;
            echo j(['ok' => $ok, 'error' => $ok ? null : (string)$res]); break;

        case 'set_ttl':
            $key = pget('key'); $ttl = (int)pget('ttl');
            if ($ttl === -1) $ok = $redis->persist($key);
            else $ok = $redis->expire($key, $ttl);
            echo j(['ok' => (bool)$ok]); break;

        case 'flush_db':
            $ok = $redis->flushdb();
            echo j(['ok' => $ok === 'OK' || $ok === true]); break;

        case 'cli':
            $cmd = trim(pget('cmd', ''));
            if (!$cmd) { echo j(['ok' => false, 'error' => 'Empty command']); break; }
            // Parse command safely
            $args = [];
            preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', $cmd, $matches);
            foreach ($matches[0] as $arg) {
                $args[] = trim($arg, '"\'');
            }
            $banned = ['CONFIG SET', 'SHUTDOWN', 'DEBUG', 'SLAVEOF', 'REPLICAOF'];
            $upper = strtoupper($args[0] ?? '');
            foreach ($banned as $b) { if (strpos($b, $upper) === 0 && count($args) > 1) { /* allow config get */ } }
            try {
                $res = $redis->rawCmd($args);
                if ($redis->isError($res)) { echo j(['ok' => false, 'result' => (string)$res]); break; }
                echo j(['ok' => true, 'result' => $res]);
            } catch (Exception $e) {
                echo j(['ok' => false, 'result' => $e->getMessage()]);
            }
            break;

        case 'config_get':
            $res = $redis->configGet('*');
            echo j(['ok' => true, 'config' => $res]); break;

        case 'client_list':
            $res = $redis->clientList();
            echo j(['ok' => true, 'clients' => $res]); break;

        case 'slowlog':
            $res = $redis->slowlog('GET', 25);
            echo j(['ok' => true, 'slowlog' => $res]); break;

        default:
            echo j(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ─── Handle Login POST ───────────────────────────────────────────────────────

$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    [$ok, $err] = loginRedis($_POST['host'] ?? '127.0.0.1', $_POST['port'] ?? 6379, $_POST['pass'] ?? '', $_POST['db'] ?? 0);
    if (!$ok) $loginError = $err;
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$conn = getConn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RedisGUI <?= VERSION ?><?= isLoggedIn() ? ' — '.$conn['host'].':'.$conn['port'].'/'.($conn['db'] ?? 0) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #0a0c0f;
  --bg2: #0f1215;
  --bg3: #14181d;
  --bg4: #1a1f26;
  --bg5: #1f252e;
  --border: #242a33;
  --border2: #2d3540;
  --text: #c8d0db;
  --text2: #7a8799;
  --text3: #4a5568;
  --accent: #00e5a0;
  --accent2: #00b87a;
  --accent-dim: rgba(0,229,160,.08);
  --accent-dim2: rgba(0,229,160,.15);
  --red: #ff4d6a;
  --red-dim: rgba(255,77,106,.1);
  --yellow: #ffc947;
  --yellow-dim: rgba(255,201,71,.1);
  --blue: #4d9fff;
  --blue-dim: rgba(77,159,255,.1);
  --purple: #a78bfa;
  --purple-dim: rgba(167,139,250,.1);
  --orange: #ff8c42;
  --orange-dim: rgba(255,140,66,.1);
  --cyan: #22d3ee;
  --cyan-dim: rgba(34,211,238,.1);
  --font-mono: 'JetBrains Mono', monospace;
  --font-sans: 'Syne', sans-serif;
  --radius: 6px;
  --radius2: 10px;
  --shadow: 0 4px 24px rgba(0,0,0,.4);
  --shadow2: 0 8px 40px rgba(0,0,0,.6);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg2); }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text3); }

/* ── Login Page ── */
.login-page {
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh;
  background: var(--bg);
  background-image:
    radial-gradient(ellipse 80% 50% at 50% -20%, rgba(0,229,160,.07) 0%, transparent 70%),
    repeating-linear-gradient(0deg, transparent, transparent 39px, var(--border) 40px),
    repeating-linear-gradient(90deg, transparent, transparent 39px, var(--border) 40px);
}

.login-box {
  width: 420px;
  background: var(--bg2);
  border: 1px solid var(--border2);
  border-radius: var(--radius2);
  padding: 40px;
  box-shadow: var(--shadow2), 0 0 60px rgba(0,229,160,.04);
}

.login-logo {
  text-align: center; margin-bottom: 36px;
}
.login-logo .logo-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 56px; height: 56px;
  background: var(--accent-dim);
  border: 1px solid rgba(0,229,160,.2);
  border-radius: var(--radius2);
  margin-bottom: 14px;
  font-size: 26px;
}
.login-logo h1 {
  font-family: var(--font-sans);
  font-size: 22px; font-weight: 800;
  color: var(--accent); letter-spacing: -.5px;
}
.login-logo p { color: var(--text2); font-size: 12px; margin-top: 4px; }

.form-group { margin-bottom: 16px; }
.form-group label { display: block; color: var(--text2); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 6px; }
.form-row { display: grid; grid-template-columns: 1fr auto; gap: 10px; }
.form-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

input[type=text], input[type=password], input[type=number], select, textarea {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--border2);
  color: var(--text);
  font-family: var(--font-mono);
  font-size: 13px;
  padding: 9px 12px;
  border-radius: var(--radius);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
input:focus, select:focus, textarea:focus {
  border-color: var(--accent2);
  box-shadow: 0 0 0 2px rgba(0,229,160,.12);
}
select option { background: var(--bg3); }

.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 18px;
  border: none; border-radius: var(--radius);
  font-family: var(--font-mono); font-size: 13px; font-weight: 500;
  cursor: pointer; transition: all .15s; text-decoration: none;
}
.btn-primary {
  background: var(--accent); color: #000; width: 100%; justify-content: center;
  font-weight: 700;
}
.btn-primary:hover { background: #00ff b3; filter: brightness(1.1); }
.btn-secondary { background: var(--bg4); color: var(--text); border: 1px solid var(--border2); }
.btn-secondary:hover { background: var(--bg5); border-color: var(--border2); }
.btn-danger { background: var(--red-dim); color: var(--red); border: 1px solid rgba(255,77,106,.2); }
.btn-danger:hover { background: rgba(255,77,106,.2); }
.btn-ghost { background: transparent; color: var(--text2); }
.btn-ghost:hover { color: var(--text); background: var(--bg4); }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.btn-xs { padding: 3px 8px; font-size: 11px; }
.btn-icon { padding: 6px; width: 30px; height: 30px; justify-content: center; }

.error-msg { background: var(--red-dim); border: 1px solid rgba(255,77,106,.25); color: var(--red); padding: 10px 14px; border-radius: var(--radius); font-size: 12px; margin-bottom: 16px; }
.success-msg { background: var(--accent-dim); border: 1px solid rgba(0,229,160,.25); color: var(--accent); padding: 10px 14px; border-radius: var(--radius); font-size: 12px; }

/* ── Main Layout ── */
.app { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

/* ── Top Bar ── */
.topbar {
  display: flex; align-items: center; gap: 12px;
  padding: 0 16px; height: 48px;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0; z-index: 100;
}
.topbar-logo {
  display: flex; align-items: center; gap: 8px;
  font-family: var(--font-sans); font-weight: 800; font-size: 15px;
  color: var(--accent); letter-spacing: -.3px; white-space: nowrap;
}
.topbar-logo .logo-dot { width: 8px; height: 8px; background: var(--accent); border-radius: 50%; box-shadow: 0 0 6px var(--accent); animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1;box-shadow:0 0 6px var(--accent)} 50%{opacity:.5;box-shadow:0 0 12px var(--accent)} }

.topbar-sep { width: 1px; height: 24px; background: var(--border); }
.topbar-info { color: var(--text2); font-size: 12px; }
.topbar-info span { color: var(--accent); }

.db-select-wrap { display: flex; align-items: center; gap: 8px; }
.db-select-wrap label { color: var(--text2); font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
.db-select-wrap select { width: auto; padding: 4px 8px; font-size: 12px; }

.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }

/* ── Main Content ── */
.main { display: flex; flex: 1; overflow: hidden; }

/* ── Sidebar ── */
.sidebar {
  width: 240px; flex-shrink: 0;
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  overflow: hidden;
}
.sidebar-nav { padding: 8px; flex: 1; overflow-y: auto; }
.nav-section { margin-bottom: 4px; }
.nav-section-title {
  font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
  color: var(--text3); padding: 8px 8px 4px;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: var(--radius);
  color: var(--text2); cursor: pointer;
  transition: all .12s; font-size: 13px; border: none;
  background: none; width: 100%; text-align: left;
}
.nav-item:hover { background: var(--bg4); color: var(--text); }
.nav-item.active { background: var(--accent-dim); color: var(--accent); border: 1px solid rgba(0,229,160,.12); }
.nav-item .nav-icon { font-size: 14px; width: 18px; text-align: center; }
.nav-item .nav-badge { margin-left: auto; background: var(--bg5); color: var(--text3); font-size: 10px; padding: 1px 6px; border-radius: 10px; }

/* ── Content Area ── */
.content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

/* ── Keys Panel ── */
.keys-layout { display: flex; flex: 1; overflow: hidden; }

.keys-panel {
  width: 360px; flex-shrink: 0;
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  background: var(--bg);
}

.keys-toolbar {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  display: flex; flex-direction: column; gap: 8px;
}
.search-row { display: flex; gap: 6px; align-items: center; }
.search-row input { flex: 1; padding: 7px 10px; font-size: 12px; }
.filter-row { display: flex; gap: 6px; flex-wrap: wrap; }
.type-pill {
  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 500;
  cursor: pointer; border: 1px solid var(--border2); background: transparent;
  color: var(--text3); transition: all .12s;
  font-family: var(--font-mono);
}
.type-pill:hover { color: var(--text); border-color: var(--border2); background: var(--bg4); }
.type-pill.active { border-color: currentColor; }
.type-pill[data-type="string"].active { color: var(--accent); background: var(--accent-dim); border-color: rgba(0,229,160,.3); }
.type-pill[data-type="hash"].active { color: var(--blue); background: var(--blue-dim); border-color: rgba(77,159,255,.3); }
.type-pill[data-type="list"].active { color: var(--yellow); background: var(--yellow-dim); border-color: rgba(255,201,71,.3); }
.type-pill[data-type="set"].active { color: var(--purple); background: var(--purple-dim); border-color: rgba(167,139,250,.3); }
.type-pill[data-type="zset"].active { color: var(--orange); background: var(--orange-dim); border-color: rgba(255,140,66,.3); }
.type-pill[data-type="stream"].active { color: var(--cyan); background: var(--cyan-dim); border-color: rgba(34,211,238,.3); }

.keys-list { flex: 1; overflow-y: auto; }
.key-item {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px; cursor: pointer;
  border-bottom: 1px solid rgba(255,255,255,.02);
  transition: background .1s;
}
.key-item:hover { background: var(--bg3); }
.key-item.selected { background: var(--accent-dim); border-left: 2px solid var(--accent); }
.key-item.selected .key-name { color: var(--accent); }
.key-name { flex: 1; font-size: 12px; word-break: break-all; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.type-badge {
  font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 3px;
  text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; flex-shrink: 0;
}
.type-string { background: var(--accent-dim); color: var(--accent); }
.type-hash { background: var(--blue-dim); color: var(--blue); }
.type-list { background: var(--yellow-dim); color: var(--yellow); }
.type-set { background: var(--purple-dim); color: var(--purple); }
.type-zset { background: var(--orange-dim); color: var(--orange); }
.type-stream { background: var(--cyan-dim); color: var(--cyan); }
.type-none { background: var(--bg4); color: var(--text3); }

.key-ttl { font-size: 10px; color: var(--text3); white-space: nowrap; flex-shrink: 0; }
.key-ttl.expiring { color: var(--yellow); }

/* ── Prefix Tree ── */
.key-group { border-bottom: 1px solid rgba(255,255,255,.02); }
.key-group-header {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 12px; cursor: pointer;
  transition: background .1s; user-select: none;
}
.key-group-header:hover { background: var(--bg3); }
.key-group-arrow { font-size: 9px; color: var(--text3); width: 12px; flex-shrink: 0; transition: transform .15s; }
.key-group-icon { font-size: 12px; flex-shrink: 0; }
.key-group-name { flex: 1; font-size: 12px; color: var(--text2); overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.key-group-name strong { color: var(--text); font-weight: 600; }
.key-group-count { font-size: 10px; background: var(--bg5); color: var(--text3); padding: 1px 7px; border-radius: 10px; flex-shrink: 0; }
.key-group-items .key-item { padding-left: 32px; }
.key-group-items .key-item.selected { border-left: 2px solid var(--accent); padding-left: 30px; }

.keys-footer {
  padding: 8px 12px; border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text2);
}
.pagination { display: flex; gap: 4px; margin-left: auto; }
.pag-btn { padding: 3px 8px; border-radius: 4px; cursor: pointer; background: var(--bg4); border: 1px solid var(--border2); color: var(--text2); font-family: var(--font-mono); font-size: 11px; }
.pag-btn:hover { background: var(--bg5); color: var(--text); }
.pag-btn.active { background: var(--accent-dim); color: var(--accent); border-color: rgba(0,229,160,.2); }
.pag-btn:disabled { opacity: .4; cursor: default; }

/* ── Detail Panel ── */
.detail-panel {
  flex: 1; display: flex; flex-direction: column;
  overflow: hidden; background: var(--bg);
}
.detail-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  color: var(--text3); gap: 12px;
}
.detail-empty .empty-icon { font-size: 48px; opacity: .3; }
.detail-empty p { font-size: 13px; }

.detail-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: flex-start; gap: 12px;
  background: var(--bg2); flex-shrink: 0;
}
.detail-key-name {
  font-family: var(--font-mono); font-size: 14px; font-weight: 600;
  color: var(--text); word-break: break-all; flex: 1;
}
.detail-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.meta-chip {
  font-size: 11px; padding: 2px 8px; border-radius: 4px;
  background: var(--bg4); color: var(--text2); border: 1px solid var(--border2);
}
.meta-chip span { color: var(--text); }
.detail-actions { display: flex; gap: 6px; flex-shrink: 0; }

.detail-body { flex: 1; overflow-y: auto; padding: 16px; }

/* ── Value Displays ── */
.value-string-wrap { display: flex; flex-direction: column; gap: 10px; }
.string-value-box {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 14px;
  font-family: var(--font-mono); font-size: 13px; line-height: 1.7;
  white-space: pre-wrap; word-break: break-all; color: var(--text);
  min-height: 80px; max-height: 300px; overflow-y: auto;
}

.value-table-wrap { overflow: hidden; border-radius: var(--radius); border: 1px solid var(--border); }
.value-table { width: 100%; border-collapse: collapse; }
.value-table th {
  background: var(--bg3); color: var(--text2); font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .5px;
  padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border);
  position: sticky; top: 0;
}
.value-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,.03); vertical-align: top; }
.value-table tr:last-child td { border-bottom: none; }
.value-table tr:hover td { background: var(--bg3); }
.value-table .cell-key { color: var(--text2); font-size: 12px; white-space: nowrap; width: 1%; }
.value-table .cell-val { color: var(--text); font-size: 12px; word-break: break-all; }
.value-table .cell-score { color: var(--orange); font-size: 12px; white-space: nowrap; width: 1%; }
.value-table .cell-idx { color: var(--text3); font-size: 11px; white-space: nowrap; width: 1%; }
.value-table .cell-act { white-space: nowrap; width: 1%; }

/* ── Section titles ── */
.section-hdr {
  display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
}
.section-hdr h3 { font-family: var(--font-sans); font-size: 14px; font-weight: 700; color: var(--text); }
.section-hdr .section-count { background: var(--bg4); color: var(--text2); font-size: 11px; padding: 2px 8px; border-radius: 20px; }

/* ── Dashboard ── */
.dashboard { padding: 20px; overflow-y: auto; flex: 1; }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.stat-card {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius2); padding: 16px;
}
.stat-card .stat-label { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px; }
.stat-card .stat-value { font-size: 22px; font-weight: 700; color: var(--text); font-family: var(--font-sans); }
.stat-card .stat-sub { font-size: 11px; color: var(--text3); margin-top: 4px; }
.stat-card.accent { border-color: rgba(0,229,160,.2); background: var(--accent-dim); }
.stat-card.accent .stat-value { color: var(--accent); }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.info-section { background: var(--bg2); border: 1px solid var(--border2); border-radius: var(--radius2); padding: 16px; }
.info-section h4 { font-family: var(--font-sans); font-size: 13px; font-weight: 700; color: var(--text2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; }
.info-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid rgba(255,255,255,.03); font-size: 12px; }
.info-row:last-child { border-bottom: none; }
.info-row .ik { color: var(--text2); }
.info-row .iv { color: var(--text); font-weight: 500; word-break: break-all; text-align: right; max-width: 60%; }

.db-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; margin-bottom: 20px; }
.db-card {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 12px; text-align: center; cursor: pointer;
  transition: all .15s;
}
.db-card:hover { border-color: var(--accent2); background: var(--accent-dim); }
.db-card.active { border-color: var(--accent); background: var(--accent-dim); }
.db-card .db-num { font-family: var(--font-sans); font-size: 18px; font-weight: 800; color: var(--text); }
.db-card.active .db-num { color: var(--accent); }
.db-card .db-keys { font-size: 11px; color: var(--text2); }

/* ── CLI ── */
.cli-panel { display: flex; flex-direction: column; height: 100%; }
.cli-output {
  flex: 1; overflow-y: auto;
  padding: 16px;
  background: var(--bg);
  font-family: var(--font-mono); font-size: 13px;
  color: var(--text);
}
.cli-line { margin-bottom: 8px; }
.cli-line .cli-prompt { color: var(--accent); }
.cli-line .cli-cmd { color: var(--text); }
.cli-line .cli-result { color: var(--text2); white-space: pre-wrap; word-break: break-all; }
.cli-line .cli-result.err { color: var(--red); }
.cli-line .cli-result.ok { color: var(--accent); }
.cli-input-row {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px; border-top: 1px solid var(--border);
  background: var(--bg2);
}
.cli-input-row .cli-prompt-label { color: var(--accent); white-space: nowrap; font-size: 13px; }
.cli-input-row input { flex: 1; background: transparent; border: none; border-bottom: 1px solid var(--border2); border-radius: 0; color: var(--text); font-size: 13px; padding: 4px 0; }
.cli-input-row input:focus { border-color: var(--accent2); box-shadow: none; }

/* ── Modal ── */
.modal-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,.7); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
}
.modal {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius2); width: 520px; max-width: 95vw; max-height: 85vh;
  display: flex; flex-direction: column; box-shadow: var(--shadow2);
}
.modal.modal-lg { width: 700px; }
.modal-header {
  padding: 16px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px;
}
.modal-header h3 { font-family: var(--font-sans); font-size: 15px; font-weight: 700; color: var(--text); }
.modal-header .modal-close { margin-left: auto; background: none; border: none; color: var(--text2); cursor: pointer; font-size: 18px; padding: 2px 6px; border-radius: 4px; }
.modal-header .modal-close:hover { background: var(--bg4); color: var(--text); }
.modal-body { padding: 20px; overflow-y: auto; }
.modal-footer { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }

/* ── Toast ── */
#toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.toast {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: var(--radius2);
  box-shadow: var(--shadow2); font-size: 13px;
  animation: toastIn .2s ease;
  min-width: 240px; max-width: 360px;
}
@keyframes toastIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
.toast.success { background: var(--bg3); border: 1px solid rgba(0,229,160,.3); color: var(--accent); }
.toast.error { background: var(--bg3); border: 1px solid rgba(255,77,106,.3); color: var(--red); }
.toast.info { background: var(--bg3); border: 1px solid rgba(77,159,255,.3); color: var(--blue); }

/* ── TTL Editor ── */
.ttl-editor { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ttl-presets { display: flex; gap: 6px; flex-wrap: wrap; }
.ttl-preset { padding: 3px 10px; border-radius: 20px; font-size: 11px; cursor: pointer; background: var(--bg4); border: 1px solid var(--border2); color: var(--text2); font-family: var(--font-mono); transition: all .12s; }
.ttl-preset:hover { background: var(--bg5); color: var(--text); }

/* ── Config / Clients ── */
.config-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.config-table tr:hover td { background: var(--bg3); }
.config-table td { padding: 6px 12px; border-bottom: 1px solid rgba(255,255,255,.03); }
.config-table td:first-child { color: var(--text2); width: 40%; }
.config-table td:last-child { color: var(--text); word-break: break-all; }

/* ── Loading ── */
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--border2); border-top-color: var(--accent); border-radius: 50%; animation: spin .6s linear infinite; }
@keyframes spin { to{transform:rotate(360deg)} }
.loading-overlay { display: flex; align-items: center; justify-content: center; padding: 40px; color: var(--text3); gap: 10px; }

/* ── Add Key Form ── */
.add-key-type-tabs { display: flex; gap: 4px; margin-bottom: 16px; flex-wrap: wrap; }
.type-tab { padding: 6px 14px; border-radius: var(--radius); cursor: pointer; font-size: 12px; font-weight: 600; border: 1px solid var(--border2); background: var(--bg3); color: var(--text2); transition: all .12s; }
.type-tab:hover { background: var(--bg4); }
.type-tab.active { border-color: var(--accent2); background: var(--accent-dim); color: var(--accent); }

/* ── Memory bar ── */
.mem-bar-wrap { background: var(--bg4); border-radius: 4px; height: 6px; overflow: hidden; margin-top: 4px; }
.mem-bar { height: 100%; background: var(--accent); border-radius: 4px; transition: width .5s; }
.mem-bar.warn { background: var(--yellow); }
.mem-bar.danger { background: var(--red); }

/* ── Responsive ── */
@media (max-width: 900px) {
  .sidebar { width: 48px; }
  .nav-item span:not(.nav-icon) { display: none; }
  .nav-section-title { display: none; }
  .info-grid { grid-template-columns: 1fr; }
  .keys-panel { width: 280px; }
}

/* ── Textarea ── */
textarea { resize: vertical; min-height: 80px; }

/* ── JSON pretty ── */
.json-view { white-space: pre; word-break: break-all; line-height: 1.7; }
.json-key { color: var(--blue); }
.json-str { color: var(--accent); }
.json-num { color: var(--orange); }
.json-bool { color: var(--purple); }
.json-null { color: var(--red); }

/* ── Highlight ── */
mark { background: rgba(0,229,160,.2); color: var(--accent); border-radius: 2px; padding: 0 1px; }

/* ── New key ── */
.btn-new-key { width: 100%; justify-content: center; background: var(--accent-dim); border: 1px dashed rgba(0,229,160,.3); color: var(--accent); padding: 8px; border-radius: var(--radius); font-size: 12px; }
.btn-new-key:hover { background: var(--accent-dim2); }

/* ── Checkbox ── */
input[type=checkbox] { accent-color: var(--accent); width: 14px; height: 14px; cursor: pointer; }

/* ── Slowlog ── */
.slowlog-entry { background: var(--bg2); border: 1px solid var(--border2); border-radius: var(--radius); padding: 10px 14px; margin-bottom: 8px; }
.slowlog-cmd { color: var(--text); font-size: 12px; word-break: break-all; }
.slowlog-meta { display: flex; gap: 12px; margin-top: 6px; font-size: 11px; color: var(--text3); }
.slowlog-meta span { color: var(--yellow); }
</style>
</head>
<body>

<?php if (!isLoggedIn()): ?>
<!-- ────────────────── LOGIN PAGE ────────────────── -->
<div class="login-page">
  <div class="login-box">
    <div class="login-logo">
      <div class="logo-icon">🔴</div>
      <h1>RedisGUI</h1>
      <p>Single-file Redis Management Interface v<?= VERSION ?></p>
    </div>
    <?php if ($loginError): ?>
      <div class="error-msg">⚠ <?= e($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="do_login" value="1">
      <div class="form-group">
        <label>Host</label>
        <input type="text" name="host" value="<?= e($_POST['host'] ?? '127.0.0.1') ?>" placeholder="127.0.0.1" autofocus>
      </div>
      <div class="form-row">
        <div class="form-group" style="margin:0">
          <label>Port</label>
          <input type="number" name="port" value="<?= e($_POST['port'] ?? '6379') ?>" placeholder="6379">
        </div>
        <div class="form-group" style="margin:0">
          <label>Database</label>
          <input type="number" name="db" value="<?= e($_POST['db'] ?? '0') ?>" min="0" max="15" placeholder="0">
        </div>
      </div>
      <div class="form-group">
        <label>Password <span style="color:var(--text3)">(optional)</span></label>
        <input type="password" name="pass" value="" placeholder="Leave empty if no auth">
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:8px">
        <span>⚡</span> Connect to Redis
      </button>
    </form>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);font-size:11px;color:var(--text3);text-align:center">
      Requires PHP Redis extension or raw socket fallback<br>
      Supports Redis 3.x – 7.x
    </div>
  </div>
</div>

<?php else: ?>
<!-- ────────────────── MAIN APP ────────────────── -->
<div class="app" id="app">

  <!-- Top Bar -->
  <div class="topbar">
    <div class="topbar-logo">
      <div class="logo-dot"></div>
      RedisGUI
    </div>
    <div class="topbar-sep"></div>
    <div class="topbar-info">
      <span><?= e($conn['host']) ?></span>:<?= e($conn['port']) ?>
    </div>
    <div class="topbar-sep"></div>
    <div class="db-select-wrap">
      <label>DB</label>
      <select id="dbSelector" onchange="switchDb(this.value)">
        <?php for($i=0;$i<16;$i++): ?>
        <option value="<?=$i?>" <?=$i==(int)($conn['db']??0)?'selected':''?>>db<?=$i?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="topbar-right">
      <span id="topbar-dbsize" style="color:var(--text2);font-size:12px"></span>
      <button class="btn btn-ghost btn-sm" onclick="openModal('addKeyModal')">＋ New Key</button>
      <a href="?logout=1" class="btn btn-ghost btn-sm" style="color:var(--text2)">⏻ Disconnect</a>
    </div>
  </div>

  <div class="main">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="sidebar-nav">
        <div class="nav-section">
          <div class="nav-section-title">Browser</div>
          <button class="nav-item active" id="nav-keys" onclick="switchView('keys')">
            <span class="nav-icon">🗄</span>
            <span>Key Browser</span>
          </button>
        </div>
        <div class="nav-section">
          <div class="nav-section-title">Monitoring</div>
          <button class="nav-item" id="nav-dashboard" onclick="switchView('dashboard')">
            <span class="nav-icon">📊</span>
            <span>Dashboard</span>
          </button>
          <button class="nav-item" id="nav-cli" onclick="switchView('cli')">
            <span class="nav-icon">⌨</span>
            <span>CLI Console</span>
          </button>
          <button class="nav-item" id="nav-clients" onclick="switchView('clients')">
            <span class="nav-icon">👥</span>
            <span>Clients</span>
          </button>
          <button class="nav-item" id="nav-slowlog" onclick="switchView('slowlog')">
            <span class="nav-icon">🐢</span>
            <span>Slow Log</span>
          </button>
          <button class="nav-item" id="nav-config" onclick="switchView('config')">
            <span class="nav-icon">⚙</span>
            <span>Config</span>
          </button>
        </div>
        <div class="nav-section" style="margin-top:auto">
          <div class="nav-section-title">Danger Zone</div>
          <button class="nav-item" style="color:var(--red)" onclick="flushDb()">
            <span class="nav-icon">🗑</span>
            <span>Flush DB</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Content -->
    <div class="content">

      <!-- ── KEY BROWSER VIEW ── -->
      <div id="view-keys" class="keys-layout">
        <!-- Keys List -->
        <div class="keys-panel">
          <div class="keys-toolbar">
            <div class="search-row">
              <input type="text" id="keySearch" placeholder="Search keys (glob: user:*)" value="*" onkeydown="if(event.key==='Enter')loadKeys()">
              <button class="btn btn-secondary btn-sm" onclick="loadKeys()">Search</button>
            </div>
            <div class="filter-row">
              <button class="type-pill active" data-type="" onclick="setTypeFilter(this,'')">All</button>
              <button class="type-pill" data-type="string" onclick="setTypeFilter(this,'string')">string</button>
              <button class="type-pill" data-type="hash" onclick="setTypeFilter(this,'hash')">hash</button>
              <button class="type-pill" data-type="list" onclick="setTypeFilter(this,'list')">list</button>
              <button class="type-pill" data-type="set" onclick="setTypeFilter(this,'set')">set</button>
              <button class="type-pill" data-type="zset" onclick="setTypeFilter(this,'zset')">zset</button>
            </div>
            <button class="btn btn-new-key" onclick="openModal('addKeyModal')">＋ Add New Key</button>
          </div>
          <div class="keys-list" id="keysList">
            <div class="loading-overlay"><div class="spinner"></div> Loading keys…</div>
          </div>
          <div class="keys-footer">
            <span id="keysCount" style="color:var(--text3)"></span>
            <div class="pagination" id="pagination"></div>
          </div>
        </div>

        <!-- Detail Panel -->
        <div class="detail-panel" id="detailPanel">
          <div class="detail-empty">
            <div class="empty-icon">🔑</div>
            <p>Select a key to view its value</p>
          </div>
        </div>
      </div>

      <!-- ── DASHBOARD VIEW ── -->
      <div id="view-dashboard" class="dashboard" style="display:none">
        <div class="section-hdr" style="margin-bottom:16px">
          <h3>Server Dashboard</h3>
          <button class="btn btn-ghost btn-sm" onclick="loadDashboard()">↻ Refresh</button>
        </div>
        <div id="dashContent"><div class="loading-overlay"><div class="spinner"></div></div></div>
      </div>

      <!-- ── CLI VIEW ── -->
      <div id="view-cli" style="display:none;flex:1;display:none;flex-direction:column">
        <div class="cli-panel">
          <div class="cli-output" id="cliOutput">
            <div class="cli-line"><span class="cli-result" style="color:var(--text3)">RedisGUI CLI — type HELP for commands, CLEAR to clear</span></div>
          </div>
          <div class="cli-input-row">
            <span class="cli-prompt-label">&gt;</span>
            <input type="text" id="cliInput" placeholder="TYPE COMMAND (e.g. SET foo bar)" autocomplete="off" autocorrect="off" spellcheck="false">
            <button class="btn btn-secondary btn-sm" onclick="cliRun()">Run</button>
          </div>
        </div>
      </div>

      <!-- ── CLIENTS VIEW ── -->
      <div id="view-clients" style="display:none;padding:20px;overflow-y:auto">
        <div class="section-hdr"><h3>Connected Clients</h3><button class="btn btn-ghost btn-sm" onclick="loadClients()">↻ Refresh</button></div>
        <div id="clientsContent"><div class="loading-overlay"><div class="spinner"></div></div></div>
      </div>

      <!-- ── SLOW LOG VIEW ── -->
      <div id="view-slowlog" style="display:none;padding:20px;overflow-y:auto">
        <div class="section-hdr"><h3>Slow Log</h3><button class="btn btn-ghost btn-sm" onclick="loadSlowlog()">↻ Refresh</button></div>
        <div id="slowlogContent"><div class="loading-overlay"><div class="spinner"></div></div></div>
      </div>

      <!-- ── CONFIG VIEW ── -->
      <div id="view-config" style="display:none;padding:20px;overflow-y:auto">
        <div class="section-hdr"><h3>Redis Configuration</h3><button class="btn btn-ghost btn-sm" onclick="loadConfig()">↻ Refresh</button></div>
        <div id="configContent"><div class="loading-overlay"><div class="spinner"></div></div></div>
      </div>

    </div>
  </div>
</div>

<!-- ── MODALS ── -->

<!-- Add Key Modal -->
<div class="modal-overlay" id="addKeyModal" style="display:none" onclick="if(event.target===this)closeModal('addKeyModal')">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3>➕ Add New Key</h3>
      <button class="modal-close" onclick="closeModal('addKeyModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Key Name</label>
        <input type="text" id="newKeyName" placeholder="my:key:name">
      </div>
      <div class="form-group">
        <label>Type</label>
        <div class="add-key-type-tabs">
          <button class="type-tab active" onclick="setNewKeyType(this,'string')">string</button>
          <button class="type-tab" onclick="setNewKeyType(this,'hash')">hash</button>
          <button class="type-tab" onclick="setNewKeyType(this,'list')">list</button>
          <button class="type-tab" onclick="setNewKeyType(this,'set')">set</button>
          <button class="type-tab" onclick="setNewKeyType(this,'zset')">zset</button>
        </div>
      </div>
      <!-- String -->
      <div id="newTypeString">
        <div class="form-group">
          <label>Value</label>
          <textarea id="newStringVal" rows="4" placeholder="Enter value…"></textarea>
        </div>
      </div>
      <!-- Hash -->
      <div id="newTypeHash" style="display:none">
        <div class="form-group">
          <label>Field</label>
          <input type="text" id="newHashField" placeholder="field name">
        </div>
        <div class="form-group">
          <label>Value</label>
          <textarea id="newHashVal" rows="3" placeholder="field value"></textarea>
        </div>
      </div>
      <!-- List -->
      <div id="newTypeList" style="display:none">
        <div class="form-group">
          <label>Value</label>
          <textarea id="newListVal" rows="3" placeholder="value to push"></textarea>
        </div>
        <div class="form-group">
          <label>Direction</label>
          <select id="newListDir"><option value="right">RPUSH (append to tail)</option><option value="left">LPUSH (prepend to head)</option></select>
        </div>
      </div>
      <!-- Set -->
      <div id="newTypeSet" style="display:none">
        <div class="form-group">
          <label>Member</label>
          <input type="text" id="newSetMember" placeholder="member value">
        </div>
      </div>
      <!-- ZSet -->
      <div id="newTypeZset" style="display:none">
        <div class="form-group">
          <label>Score</label>
          <input type="number" id="newZsetScore" value="0" step="any">
        </div>
        <div class="form-group">
          <label>Member</label>
          <input type="text" id="newZsetMember" placeholder="member value">
        </div>
      </div>
      <!-- TTL -->
      <div class="form-group">
        <label>TTL (seconds, -1 = no expiry)</label>
        <input type="number" id="newKeyTtl" value="-1" min="-1">
        <div class="ttl-presets" style="margin-top:6px">
          <span class="ttl-preset" onclick="document.getElementById('newKeyTtl').value=-1">No expiry</span>
          <span class="ttl-preset" onclick="document.getElementById('newKeyTtl').value=60">1 min</span>
          <span class="ttl-preset" onclick="document.getElementById('newKeyTtl').value=3600">1 hour</span>
          <span class="ttl-preset" onclick="document.getElementById('newKeyTtl').value=86400">1 day</span>
          <span class="ttl-preset" onclick="document.getElementById('newKeyTtl').value=604800">1 week</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('addKeyModal')">Cancel</button>
      <button class="btn btn-primary" onclick="addNewKey()">Create Key</button>
    </div>
  </div>
</div>

<!-- Edit TTL Modal -->
<div class="modal-overlay" id="ttlModal" style="display:none" onclick="if(event.target===this)closeModal('ttlModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>⏱ Edit TTL</h3>
      <button class="modal-close" onclick="closeModal('ttlModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Key</label>
        <div id="ttlKeyName" style="color:var(--text);font-size:12px;padding:8px;background:var(--bg3);border-radius:var(--radius)"></div>
      </div>
      <div class="form-group">
        <label>Current TTL: <span id="ttlCurrent" style="color:var(--accent)"></span></label>
        <input type="number" id="ttlValue" placeholder="Seconds (-1 = persist)">
        <div class="ttl-presets" style="margin-top:6px">
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=-1">Persist</span>
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=60">1 min</span>
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=300">5 min</span>
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=3600">1 hour</span>
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=86400">1 day</span>
          <span class="ttl-preset" onclick="document.getElementById('ttlValue').value=604800">1 week</span>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('ttlModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveTtl()">Save TTL</button>
    </div>
  </div>
</div>

<!-- Rename Key Modal -->
<div class="modal-overlay" id="renameModal" style="display:none" onclick="if(event.target===this)closeModal('renameModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>✏ Rename Key</h3>
      <button class="modal-close" onclick="closeModal('renameModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Old Name</label>
        <div id="renameOld" style="color:var(--text);font-size:12px;padding:8px;background:var(--bg3);border-radius:var(--radius)"></div>
      </div>
      <div class="form-group">
        <label>New Name</label>
        <input type="text" id="renameNew" placeholder="new:key:name">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('renameModal')">Cancel</button>
      <button class="btn btn-primary" onclick="doRename()">Rename</button>
    </div>
  </div>
</div>

<!-- Edit Value Modal (string) -->
<div class="modal-overlay" id="editStringModal" style="display:none" onclick="if(event.target===this)closeModal('editStringModal')">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3>✏ Edit String Value</h3>
      <button class="modal-close" onclick="closeModal('editStringModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Key: <span id="editStringKey" style="color:var(--text)"></span></label>
      </div>
      <div class="form-group">
        <label>Value</label>
        <textarea id="editStringVal" rows="8" style="font-family:var(--font-mono)"></textarea>
      </div>
      <div class="form-group">
        <label>TTL (seconds, -1 = no change)</label>
        <input type="number" id="editStringTtl" value="-1">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('editStringModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveStringEdit()">Save</button>
    </div>
  </div>
</div>

<!-- Add Hash Field Modal -->
<div class="modal-overlay" id="addHashFieldModal" style="display:none" onclick="if(event.target===this)closeModal('addHashFieldModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add Hash Field</h3>
      <button class="modal-close" onclick="closeModal('addHashFieldModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="addHashKey">
      <div class="form-group"><label>Field</label><input type="text" id="addHashField" placeholder="field name"></div>
      <div class="form-group"><label>Value</label><textarea id="addHashValue" rows="4"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('addHashFieldModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveHashField()">Add Field</button>
    </div>
  </div>
</div>

<!-- Add List Item Modal -->
<div class="modal-overlay" id="addListItemModal" style="display:none" onclick="if(event.target===this)closeModal('addListItemModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add List Item</h3>
      <button class="modal-close" onclick="closeModal('addListItemModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="addListKey">
      <div class="form-group"><label>Value</label><textarea id="addListValue" rows="4"></textarea></div>
      <div class="form-group"><label>Position</label>
        <select id="addListPos"><option value="right">RPUSH (tail)</option><option value="left">LPUSH (head)</option></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('addListItemModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveListItem()">Push</button>
    </div>
  </div>
</div>

<!-- Add Set Member Modal -->
<div class="modal-overlay" id="addSetMemberModal" style="display:none" onclick="if(event.target===this)closeModal('addSetMemberModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add Set Member</h3>
      <button class="modal-close" onclick="closeModal('addSetMemberModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="addSetKey">
      <div class="form-group"><label>Member</label><input type="text" id="addSetMember" placeholder="member value"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('addSetMemberModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveSetMember()">Add</button>
    </div>
  </div>
</div>

<!-- Add ZSet Member Modal -->
<div class="modal-overlay" id="addZsetMemberModal" style="display:none" onclick="if(event.target===this)closeModal('addZsetMemberModal')">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add Sorted Set Member</h3>
      <button class="modal-close" onclick="closeModal('addZsetMemberModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="addZsetKey">
      <div class="form-group"><label>Score</label><input type="number" id="addZsetScore" value="0" step="any"></div>
      <div class="form-group"><label>Member</label><input type="text" id="addZsetMember" placeholder="member value"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('addZsetMemberModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveZsetMember()">Add</button>
    </div>
  </div>
</div>

<!-- Edit Hash Field Modal -->
<div class="modal-overlay" id="editHashFieldModal" style="display:none" onclick="if(event.target===this)closeModal('editHashFieldModal')">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3>✏ Edit Hash Field</h3>
      <button class="modal-close" onclick="closeModal('editHashFieldModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editHashKey">
      <input type="hidden" id="editHashField">
      <div class="form-group"><label>Field: <span id="editHashFieldLabel" style="color:var(--text)"></span></label></div>
      <div class="form-group"><label>Value</label><textarea id="editHashVal" rows="6"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('editHashFieldModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveHashEdit()">Save</button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="toast-container"></div>

<script>
// ─── State ───────────────────────────────────────────────────────────────────
const S = {
  view: 'keys',
  keys: [], total: 0, page: 0, perPage: 100,
  typeFilter: '', pattern: '*',
  selectedKey: null, keyData: null,
  cliHistory: [], cliHistoryIdx: -1,
  currentDb: <?= (int)($conn['db'] ?? 0) ?>,
  expandedGroups: new Set(),   // prefix groups toggled open by the user
};

// ─── API ─────────────────────────────────────────────────────────────────────
async function api(action, data = {}, signal) {
  const fd = new FormData();
  fd.append('action', action);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const res = await fetch('?ajax=1', { method: 'POST', body: fd, signal });
  return res.json();
}

// ─── Toast ───────────────────────────────────────────────────────────────────
function toast(msg, type = 'success', duration = 3000) {
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
  el.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// ─── Modal ───────────────────────────────────────────────────────────────────
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// ─── View Switching ──────────────────────────────────────────────────────────
function switchView(v) {
  const views = ['keys','dashboard','cli','clients','slowlog','config'];
  views.forEach(x => {
    const el = document.getElementById('view-'+x);
    if (el) el.style.display = x === v ? (x === 'keys' ? 'flex' : 'block') : 'none';
    const nav = document.getElementById('nav-'+x);
    if (nav) nav.classList.toggle('active', x === v);
  });
  // Fix CLI flex
  if (v === 'cli') {
    document.getElementById('view-cli').style.cssText = 'display:flex;flex:1;flex-direction:column;height:100%';
    document.querySelector('#view-cli .cli-panel').style.height = '100%';
    document.getElementById('cliInput').focus();
  }
  S.view = v;
  if (v === 'dashboard') loadDashboard();
  if (v === 'clients') loadClients();
  if (v === 'slowlog') loadSlowlog();
  if (v === 'config') loadConfig();
}

// ─── DB Select ───────────────────────────────────────────────────────────────
async function switchDb(db) {
  await api('select_db', { db });
  S.currentDb = parseInt(db);
  S.selectedKey = null;
  document.getElementById('detailPanel').innerHTML = `<div class="detail-empty"><div class="empty-icon">🔑</div><p>Select a key to view its value</p></div>`;
  loadKeys();
}

// ─── Keys Loading ─────────────────────────────────────────────────────────────
let _loadKeysAbort = null;
async function loadKeys(page) {
  _loadKeysAbort?.abort();
  _loadKeysAbort = new AbortController();
  if (page === undefined) page = 0;
  S.page = page;
  S.pattern = document.getElementById('keySearch').value || '*';
  const list = document.getElementById('keysList');
  list.innerHTML = `<div class="loading-overlay"><div class="spinner"></div> Scanning…</div>`;
  try {
    const res = await api('keys', { pattern: S.pattern, type: S.typeFilter, page }, _loadKeysAbort.signal);
    if (!res.ok) { toast(res.error, 'error'); return; }
    S.keys = res.keys; S.total = res.total;
    renderKeys(res.keys, res.total, page, res.per_page);
    updateTopbarDbSize();
  } catch (e) {
    if (e.name !== 'AbortError') toast('Failed to load keys', 'error');
  }
}

// ─── Prefix Tree ─────────────────────────────────────────────────────────────

function detectSeparator(keys) {
  // Count occurrences of each candidate separator
  const seps = [':', '/', '.', '-', '|'];
  const counts = {};
  for (const sep of seps) counts[sep] = 0;
  for (const k of keys) {
    for (const sep of seps) { if (k.key.includes(sep)) counts[sep]++; }
  }
  const best = seps.reduce((a, b) => counts[a] >= counts[b] ? a : b);
  // Only use grouping if at least 25% of keys share this separator
  return counts[best] >= Math.max(2, keys.length * 0.25) ? best : null;
}

function buildPrefixGroups(keys) {
  const sep = detectSeparator(keys);
  if (!sep) return { sep: null, groups: {}, ungrouped: keys };
  const groups = {};
  const ungrouped = [];
  for (const k of keys) {
    const idx = k.key.indexOf(sep);
    if (idx > 0) {
      const prefix = k.key.slice(0, idx + 1); // e.g. "user:"
      if (!groups[prefix]) groups[prefix] = [];
      groups[prefix].push(k);
    } else {
      ungrouped.push(k);
    }
  }
  // Dissolve single-key groups back to ungrouped (no point grouping 1 key)
  for (const [prefix, ks] of Object.entries(groups)) {
    if (ks.length < 2) { ungrouped.push(...ks); delete groups[prefix]; }
  }
  return { sep, groups, ungrouped };
}

function toggleGroup(prefix) {
  if (S.expandedGroups.has(prefix)) S.expandedGroups.delete(prefix);
  else S.expandedGroups.add(prefix);
  const header = document.querySelector(`.key-group-header[data-prefix="${CSS.escape(prefix)}"]`);
  if (!header) return;
  const items = header.nextElementSibling;
  const arrow = header.querySelector('.key-group-arrow');
  const expanded = S.expandedGroups.has(prefix);
  items.style.display = expanded ? '' : 'none';
  arrow.textContent = expanded ? '▼' : '▶';
}

function renderKeyItem(k, prefix = '') {
  const isSelected = S.selectedKey === k.key;
  // Show only the portion after the prefix when inside a group
  const displayName = prefix && k.key.startsWith(prefix) ? k.key.slice(prefix.length) : k.key;
  const srch = (document.getElementById('keySearch')?.value || '').replace(/[*?]/g, '');
  let nameHtml = srch
    ? e(displayName).replace(new RegExp('(' + escRx(srch) + ')', 'gi'), '<mark>$1</mark>')
    : e(displayName);
  const ttlStr = k.ttl < 0 ? '' : k.ttl < 60 ? k.ttl + 's' : k.ttl < 3600 ? Math.floor(k.ttl/60) + 'm' : k.ttl < 86400 ? Math.floor(k.ttl/3600) + 'h' : Math.floor(k.ttl/86400) + 'd';
  const ttlClass = k.ttl > 0 && k.ttl < 60 ? ' expiring' : '';
  return `<div class="key-item${isSelected ? ' selected' : ''}" data-key="${e(k.key)}" onclick="selectKey('${esc(k.key)}')">
    <span class="type-badge type-${e(k.type)}">${e(k.type)}</span>
    <span class="key-name" title="${e(k.key)}">${nameHtml}</span>
    ${ttlStr ? `<span class="key-ttl${ttlClass}">${ttlStr}</span>` : ''}
  </div>`;
}

function renderKeys(keys, total, page, perPage) {
  const list = document.getElementById('keysList');
  if (!keys.length) {
    list.innerHTML = `<div class="loading-overlay" style="flex-direction:column;gap:8px"><span style="font-size:32px;opacity:.3">🔍</span><span style="color:var(--text3)">No keys found</span></div>`;
    document.getElementById('keysCount').textContent = '0 keys';
    document.getElementById('pagination').innerHTML = '';
    return;
  }

  const { sep, groups, ungrouped } = buildPrefixGroups(keys);
  let html = '';

  // Ungrouped keys first
  for (const k of ungrouped) html += renderKeyItem(k);

  // Grouped keys
  const sortedGroups = Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
  for (const [prefix, groupKeys] of sortedGroups) {
    // Auto-expand small groups or groups containing the selected key, or if user toggled it
    const hasSelected = groupKeys.some(k => k.key === S.selectedKey);
    const autoExpand = groupKeys.length <= 4 || hasSelected;
    const isExpanded = S.expandedGroups.has(prefix) ? true : (!S.expandedGroups.has('__collapsed__' + prefix) && autoExpand);
    const arrow = isExpanded ? '▼' : '▶';

    // Show most common sub-type in the group
    const typeCounts = {};
    groupKeys.forEach(k => { typeCounts[k.type] = (typeCounts[k.type]||0)+1; });
    const dominantType = Object.entries(typeCounts).sort((a,b) => b[1]-a[1])[0]?.[0] || '';

    html += `<div class="key-group">
      <div class="key-group-header" data-prefix="${e(prefix)}" onclick="toggleGroup('${esc(prefix)}')">
        <span class="key-group-arrow">${arrow}</span>
        <span class="key-group-icon">📁</span>
        <span class="key-group-name"><strong>${e(prefix)}</strong></span>
        ${dominantType ? `<span class="type-badge type-${e(dominantType)}" style="font-size:8px">${e(dominantType)}</span>` : ''}
        <span class="key-group-count">${groupKeys.length}</span>
      </div>
      <div class="key-group-items" ${isExpanded ? '' : 'style="display:none"'}>
        ${groupKeys.map(k => renderKeyItem(k, prefix)).join('')}
      </div>
    </div>`;
  }

  list.innerHTML = html;
  document.getElementById('keysCount').textContent = `${total.toLocaleString()} key${total !== 1 ? 's' : ''}`;
  renderPagination(total, page, perPage);
}

function escRx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
function esc(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function e(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

function renderPagination(total, page, perPage) {
  const pages = Math.ceil(total / perPage);
  const pag = document.getElementById('pagination');
  if (pages <= 1) { pag.innerHTML = ''; return; }
  let html = `<button class="pag-btn" ${page===0?'disabled':''} onclick="loadKeys(${page-1})">‹</button>`;
  const start = Math.max(0, page-2), end = Math.min(pages-1, start+4);
  for (let i = start; i <= end; i++) html += `<button class="pag-btn${i===page?' active':''}" onclick="loadKeys(${i})">${i+1}</button>`;
  html += `<button class="pag-btn" ${page>=pages-1?'disabled':''} onclick="loadKeys(${page+1})">›</button>`;
  pag.innerHTML = html;
}

async function updateTopbarDbSize() {
  const res = await api('server_info');
  if (res.ok) document.getElementById('topbar-dbsize').textContent = `${res.dbsize.toLocaleString()} keys`;
}

function setTypeFilter(el, type) {
  document.querySelectorAll('.type-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  S.typeFilter = type;
  loadKeys();
}

// ─── Key Selection / Detail ──────────────────────────────────────────────────
async function selectKey(key) {
  S.selectedKey = key;
  // Highlight using the data-key attribute set on each .key-item
  document.querySelectorAll('.key-item').forEach(el => {
    el.classList.toggle('selected', el.dataset.key === key);
  });

  const panel = document.getElementById('detailPanel');
  panel.innerHTML = `<div class="loading-overlay"><div class="spinner"></div> Loading…</div>`;
  const res = await api('get_key', { key });
  if (!res.ok) { panel.innerHTML = `<div class="detail-empty"><span style="color:var(--red)">Error: ${e(res.error)}</span></div>`; return; }
  S.keyData = res;
  renderDetail(res);
}

function renderDetail(d) {
  const panel = document.getElementById('detailPanel');
  const ttlLabel = d.ttl < 0 ? '<span style="color:var(--accent)">No expiry</span>' : `<span style="color:var(--yellow)">${d.ttl}s</span>`;
  const sizeLabel = d.size > 0 ? formatBytes(d.size) : '—';
  let valueHtml = '';

  switch(d.type) {
    case 'string': valueHtml = renderString(d); break;
    case 'hash': valueHtml = renderHash(d); break;
    case 'list': valueHtml = renderList(d); break;
    case 'set': valueHtml = renderSet(d); break;
    case 'zset': valueHtml = renderZset(d); break;
    default: valueHtml = `<div style="color:var(--text3)">Unknown type: ${e(d.type)}</div>`;
  }

  panel.innerHTML = `
    <div class="detail-header">
      <div style="flex:1">
        <div class="detail-key-name">${e(d.key)}</div>
        <div class="detail-meta">
          <span class="meta-chip type-badge type-${e(d.type)}">${e(d.type)}</span>
          <span class="meta-chip">TTL: ${ttlLabel}</span>
          <span class="meta-chip">Size: <span>${sizeLabel}</span></span>
          ${d.encoding ? `<span class="meta-chip">Enc: <span>${e(d.encoding)}</span></span>` : ''}
          <span class="meta-chip">Count: <span>${d.count}</span></span>
        </div>
      </div>
      <div class="detail-actions">
        <button class="btn btn-secondary btn-sm" data-action="edit-ttl" title="Edit TTL">⏱ TTL</button>
        <button class="btn btn-secondary btn-sm" data-action="rename-key" title="Rename">✏ Rename</button>
        <button class="btn btn-danger btn-sm" data-action="delete-key" title="Delete">🗑 Delete</button>
      </div>
    </div>
    <div class="detail-body">${valueHtml}</div>`;
}

function renderString(d) {
  const val = d.value ?? '';
  let prettyJson = '';
  try { prettyJson = syntaxHighlight(JSON.stringify(JSON.parse(val), null, 2)); } catch(ex) {}
  return `<div class="value-string-wrap">
    <div class="section-hdr">
      <h3>String Value</h3>
      <span class="section-count">${typeof d.count === 'number' ? d.count + ' bytes' : ''}</span>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" data-action="edit-string">✏ Edit</button>
    </div>
    ${prettyJson
      ? `<div style="display:flex;gap:8px;margin-bottom:8px">
           <button class="btn btn-ghost btn-xs" data-action="toggle-json">JSON View</button>
         </div>
         <div class="string-value-box json-hidden">${e(val)}</div>
         <div class="string-value-box json-view" style="display:none">${prettyJson}</div>`
      : `<div class="string-value-box">${e(val)}</div>`}
  </div>`;
}

function toggleJsonView(btn) {
  const wrap = btn.closest('.value-string-wrap');
  const plain = wrap.querySelector('.json-hidden');
  const json = wrap.querySelector('.json-view.string-value-box');
  if (!json) return;
  const showing = json.style.display !== 'none';
  json.style.display = showing ? 'none' : '';
  plain.style.display = showing ? '' : 'none';
  btn.textContent = showing ? 'JSON View' : 'Raw View';
}

function renderHash(d) {
  const entries = Object.entries(d.value || {});
  return `<div>
    <div class="section-hdr">
      <h3>Hash Fields</h3>
      <span class="section-count">${d.count} fields</span>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" data-action="add-hash-field">＋ Add Field</button>
    </div>
    <div class="value-table-wrap"><table class="value-table">
      <thead><tr><th>Field</th><th>Value</th><th></th></tr></thead>
      <tbody>${entries.map(([f, v]) => `<tr>
        <td class="cell-key">${e(f)}</td>
        <td class="cell-val">${renderCellVal(v)}</td>
        <td class="cell-act">
          <button class="btn btn-ghost btn-xs" data-action="edit-hash-field" data-field="${e(f)}">✏</button>
          <button class="btn btn-danger btn-xs" data-action="del-hash-field" data-field="${e(f)}">✕</button>
        </td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}

function renderList(d) {
  const items = d.value || [];
  return `<div>
    <div class="section-hdr">
      <h3>List Items</h3>
      <span class="section-count">${d.count} items</span>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" data-action="add-list-item">＋ Push Item</button>
    </div>
    <div class="value-table-wrap"><table class="value-table">
      <thead><tr><th>#</th><th>Value</th><th></th></tr></thead>
      <tbody>${items.map((v, i) => `<tr>
        <td class="cell-idx">${i}</td>
        <td class="cell-val">${renderCellVal(v)}</td>
        <td class="cell-act">
          <button class="btn btn-danger btn-xs" data-action="del-list-item" data-index="${i}">✕</button>
        </td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}

function renderSet(d) {
  const members = d.value || [];
  return `<div>
    <div class="section-hdr">
      <h3>Set Members</h3>
      <span class="section-count">${d.count} members</span>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" data-action="add-set-member">＋ Add Member</button>
    </div>
    <div class="value-table-wrap"><table class="value-table">
      <thead><tr><th>Member</th><th></th></tr></thead>
      <tbody>${members.map((v, i) => `<tr>
        <td class="cell-val">${renderCellVal(v)}</td>
        <td class="cell-act">
          <button class="btn btn-danger btn-xs" data-action="del-set-member" data-index="${i}">✕</button>
        </td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}

function renderZset(d) {
  const items = d.value || [];
  return `<div>
    <div class="section-hdr">
      <h3>Sorted Set Members</h3>
      <span class="section-count">${d.count} members</span>
      <button class="btn btn-secondary btn-sm" style="margin-left:auto" data-action="add-zset-member">＋ Add Member</button>
    </div>
    <div class="value-table-wrap"><table class="value-table">
      <thead><tr><th>Score</th><th>Member</th><th></th></tr></thead>
      <tbody>${items.map((m, i) => `<tr>
        <td class="cell-score">${m.score}</td>
        <td class="cell-val">${renderCellVal(m.member)}</td>
        <td class="cell-act">
          <button class="btn btn-danger btn-xs" data-action="del-zset-member" data-index="${i}">✕</button>
        </td>
      </tr>`).join('')}</tbody>
    </table></div>
  </div>`;
}

function renderCellVal(v) {
  if (v === null || v === undefined) return '<span style="color:var(--text3);font-style:italic">null</span>';
  const s = String(v);
  if (s.length > 200) return `<span title="${e(s)}">${e(s.slice(0,200))}…</span>`;
  return e(s);
}

function syntaxHighlight(json) {
  return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, m => {
    let cls = 'json-num';
    if (/^"/.test(m)) cls = /:$/.test(m) ? 'json-key' : 'json-str';
    else if (/true|false/.test(m)) cls = 'json-bool';
    else if (/null/.test(m)) cls = 'json-null';
    return `<span class="${cls}">${m}</span>`;
  });
}

function formatBytes(b) {
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  return (b/1048576).toFixed(2) + ' MB';
}

// ─── Detail Panel — Event Delegation ─────────────────────────────────────────
// All buttons inside #detailPanel use data-action so that values with special
// characters (newlines, quotes, etc.) are read from S.keyData, not from inline
// onclick strings.

document.addEventListener('click', async ev => {
  const btn = ev.target.closest('[data-action]');
  if (!btn) return;
  const action = btn.dataset.action;
  const d = S.keyData;

  // Actions that don't need keyData
  if (action === 'toggle-json') { toggleJsonView(btn); return; }
  if (action === 'edit-string') { openStringEditCurrent(); return; }

  // All others need the current key context
  if (!d) return;

  switch (action) {
    // ── Detail header ──
    case 'edit-ttl':    openTtlModal(d.key, d.ttl); break;
    case 'rename-key':  openRenameModal(d.key); break;
    case 'delete-key':  deleteKey(d.key); break;

    // ── Hash ──
    case 'add-hash-field': openAddHashField(d.key); break;
    case 'edit-hash-field': {
      const field = btn.dataset.field;
      const val = (d.value || {})[field] ?? '';
      openEditHashField(d.key, field, val);
      break;
    }
    case 'del-hash-field': {
      const field = btn.dataset.field;
      if (!confirm(`Delete field "${field}"?`)) return;
      const r = await api('delete_hash_field', { key: d.key, field });
      if (r.ok) { toast('Field deleted'); selectKey(d.key); } else toast('Failed', 'error');
      break;
    }

    // ── List ──
    case 'add-list-item': openAddListItem(d.key); break;
    case 'del-list-item': {
      const idx = parseInt(btn.dataset.index);
      const val = (d.value || [])[idx];
      if (val === undefined) return;
      const r = await api('delete_list_item', { key: d.key, value: val });
      if (r.ok) { toast('Item removed'); selectKey(d.key); } else toast('Failed', 'error');
      break;
    }

    // ── Set ──
    case 'add-set-member': openAddSetMember(d.key); break;
    case 'del-set-member': {
      const member = (d.value || [])[parseInt(btn.dataset.index)];
      if (member === undefined) return;
      const r = await api('delete_set_member', { key: d.key, member });
      if (r.ok) { toast('Member removed'); selectKey(d.key); } else toast('Failed', 'error');
      break;
    }

    // ── ZSet ──
    case 'add-zset-member': openAddZsetMember(d.key); break;
    case 'del-zset-member': {
      const item = (d.value || [])[parseInt(btn.dataset.index)];
      if (!item) return;
      const r = await api('delete_zset_member', { key: d.key, member: item.member });
      if (r.ok) { toast('Member removed'); selectKey(d.key); } else toast('Failed', 'error');
      break;
    }
  }
});

// ─── Key Actions ─────────────────────────────────────────────────────────────
async function deleteKey(key) {
  if (!confirm(`Delete key: ${key}?`)) return;
  const res = await api('delete_key', { key });
  if (res.ok) {
    toast('Key deleted');
    S.selectedKey = null;
    S.keyData = null;
    document.getElementById('detailPanel').innerHTML = `<div class="detail-empty"><div class="empty-icon">🔑</div><p>Select a key to view its value</p></div>`;
    loadKeys(S.page);
  } else toast(res.error, 'error');
}

function openTtlModal(key, ttl) {
  document.getElementById('ttlKeyName').textContent = key;
  document.getElementById('ttlCurrent').textContent = ttl < 0 ? 'No expiry (persistent)' : ttl + 's';
  document.getElementById('ttlValue').value = ttl;
  document.getElementById('ttlModal').setAttribute('data-key', key);
  openModal('ttlModal');
}

async function saveTtl() {
  const key = document.getElementById('ttlModal').getAttribute('data-key');
  const ttl = parseInt(document.getElementById('ttlValue').value);
  const res = await api('set_ttl', { key, ttl });
  if (res.ok) { toast('TTL updated'); closeModal('ttlModal'); selectKey(key); loadKeys(S.page); }
  else toast('Failed to set TTL', 'error');
}

function openRenameModal(key) {
  document.getElementById('renameOld').textContent = key;
  document.getElementById('renameNew').value = key;
  document.getElementById('renameModal').setAttribute('data-key', key);
  openModal('renameModal');
}

async function doRename() {
  const old = document.getElementById('renameModal').getAttribute('data-key');
  const nw = document.getElementById('renameNew').value.trim();
  if (!nw || nw === old) { toast('Enter a different name', 'error'); return; }
  const res = await api('rename_key', { old, new: nw });
  if (res.ok) { toast('Key renamed'); closeModal('renameModal'); S.selectedKey = nw; loadKeys(S.page); setTimeout(() => selectKey(nw), 800); }
  else toast(res.error || 'Rename failed', 'error');
}

function openStringEditCurrent() {
  const d = S.keyData;
  if (!d || d.type !== 'string') return;
  document.getElementById('editStringKey').textContent = d.key;
  document.getElementById('editStringVal').value = d.value ?? '';
  document.getElementById('editStringTtl').value = d.ttl;
  document.getElementById('editStringModal').setAttribute('data-key', d.key);
  openModal('editStringModal');
}

// Keep openStringEdit as an alias for any future direct calls
function openStringEdit(key, val, ttl) {
  document.getElementById('editStringKey').textContent = key;
  document.getElementById('editStringVal').value = val;
  document.getElementById('editStringTtl').value = ttl;
  document.getElementById('editStringModal').setAttribute('data-key', key);
  openModal('editStringModal');
}

async function saveStringEdit() {
  const key = document.getElementById('editStringModal').getAttribute('data-key');
  const val = document.getElementById('editStringVal').value;
  const ttl = parseInt(document.getElementById('editStringTtl').value);
  const res = await api('update_string', { key, value: val, ttl });
  if (res.ok) { toast('Value saved'); closeModal('editStringModal'); selectKey(key); }
  else toast('Save failed', 'error');
}

// Hash modal openers (delegation calls these for edit/add)
function openAddHashField(key) {
  document.getElementById('addHashKey').value = key;
  document.getElementById('addHashField').value = '';
  document.getElementById('addHashValue').value = '';
  openModal('addHashFieldModal');
}
async function saveHashField() {
  const key = document.getElementById('addHashKey').value;
  const field = document.getElementById('addHashField').value;
  const value = document.getElementById('addHashValue').value;
  const res = await api('set_value', { key, type:'hash', field, value });
  if (res.ok) { toast('Field added'); closeModal('addHashFieldModal'); selectKey(key); }
  else toast(res.error||'Failed', 'error');
}
function openEditHashField(key, field, val) {
  document.getElementById('editHashKey').value = key;
  document.getElementById('editHashField').value = field;
  document.getElementById('editHashFieldLabel').textContent = field;
  document.getElementById('editHashVal').value = val;
  openModal('editHashFieldModal');
}
async function saveHashEdit() {
  const key = document.getElementById('editHashKey').value;
  const field = document.getElementById('editHashField').value;
  const value = document.getElementById('editHashVal').value;
  const res = await api('set_value', { key, type:'hash', field, value });
  if (res.ok) { toast('Field updated'); closeModal('editHashFieldModal'); selectKey(key); }
  else toast('Failed', 'error');
}
// (hash field deletion is now handled by the detail-panel event delegation above)

// List modal openers
function openAddListItem(key) {
  document.getElementById('addListKey').value = key;
  document.getElementById('addListValue').value = '';
  openModal('addListItemModal');
}
async function saveListItem() {
  const key = document.getElementById('addListKey').value;
  const value = document.getElementById('addListValue').value;
  const pos = document.getElementById('addListPos').value;
  const res = await api('set_value', { key, type:'list', value, pos });
  if (res.ok) { toast('Item pushed'); closeModal('addListItemModal'); selectKey(key); }
  else toast('Failed', 'error');
}
// Set modal openers
function openAddSetMember(key) {
  document.getElementById('addSetKey').value = key;
  document.getElementById('addSetMember').value = '';
  openModal('addSetMemberModal');
}
async function saveSetMember() {
  const key = document.getElementById('addSetKey').value;
  const value = document.getElementById('addSetMember').value;
  const res = await api('set_value', { key, type:'set', value });
  if (res.ok) { toast('Member added'); closeModal('addSetMemberModal'); selectKey(key); }
  else toast('Failed', 'error');
}
// ZSet modal openers
function openAddZsetMember(key) {
  document.getElementById('addZsetKey').value = key;
  document.getElementById('addZsetScore').value = 0;
  document.getElementById('addZsetMember').value = '';
  openModal('addZsetMemberModal');
}
async function saveZsetMember() {
  const key = document.getElementById('addZsetKey').value;
  const score = document.getElementById('addZsetScore').value;
  const value = document.getElementById('addZsetMember').value;
  const res = await api('set_value', { key, type:'zset', value, score });
  if (res.ok) { toast('Member added'); closeModal('addZsetMemberModal'); selectKey(key); }
  else toast('Failed', 'error');
}
// ─── Add New Key ─────────────────────────────────────────────────────────────
let newKeyType = 'string';
function setNewKeyType(el, type) {
  document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  ['string','hash','list','set','zset'].forEach(t => {
    document.getElementById('newType'+t.charAt(0).toUpperCase()+t.slice(1)).style.display = t === type ? '' : 'none';
  });
  newKeyType = type;
}

async function addNewKey() {
  const key = document.getElementById('newKeyName').value.trim();
  if (!key) { toast('Enter a key name', 'error'); return; }
  const ttl = parseInt(document.getElementById('newKeyTtl').value);
  let data = { key, type: newKeyType, ttl };
  switch(newKeyType) {
    case 'string': data.value = document.getElementById('newStringVal').value; break;
    case 'hash': data.field = document.getElementById('newHashField').value; data.value = document.getElementById('newHashVal').value; break;
    case 'list': data.value = document.getElementById('newListVal').value; data.pos = document.getElementById('newListDir').value; break;
    case 'set': data.value = document.getElementById('newSetMember').value; break;
    case 'zset': data.score = document.getElementById('newZsetScore').value; data.value = document.getElementById('newZsetMember').value; break;
  }
  const res = await api('set_value', data);
  if (res.ok) { toast('Key created'); closeModal('addKeyModal'); loadKeys(S.page); setTimeout(() => selectKey(key), 500); }
  else toast(res.error || 'Failed', 'error');
}

// ─── Flush DB ─────────────────────────────────────────────────────────────────
async function flushDb() {
  const db = document.getElementById('dbSelector').value;
  if (!confirm(`⚠ FLUSH DATABASE ${db}?\n\nThis will permanently delete ALL keys in db${db}. This cannot be undone!`)) return;
  const res = await api('flush_db');
  if (res.ok) { toast('Database flushed'); loadKeys(); }
  else toast('Flush failed', 'error');
}

// ─── Dashboard ───────────────────────────────────────────────────────────────
async function loadDashboard() {
  document.getElementById('dashContent').innerHTML = `<div class="loading-overlay"><div class="spinner"></div></div>`;
  const res = await api('server_info');
  if (!res.ok) { document.getElementById('dashContent').innerHTML = `<div style="color:var(--red)">Error loading info</div>`; return; }
  const i = res.info;
  const mem = parseInt(i.used_memory || 0);
  const maxMem = parseInt(i.maxmemory || 0);
  const memPct = maxMem > 0 ? Math.round(mem / maxMem * 100) : 0;
  const memBarClass = memPct > 90 ? 'danger' : memPct > 70 ? 'warn' : '';

  // DB cards
  let dbCards = '';
  const currentDb = S.currentDb;
  for (let d = 0; d < 16; d++) {
    const keys = res.dbs[d] ?? 0;
    if (keys > 0 || d === currentDb) {
      dbCards += `<div class="db-card${d===currentDb?' active':''}" onclick="document.getElementById('dbSelector').value=${d};switchDb(${d})">
        <div class="db-num">db${d}</div>
        <div class="db-keys">${keys.toLocaleString()} keys</div>
      </div>`;
    }
  }

  document.getElementById('dashContent').innerHTML = `
    <div class="dash-grid">
      <div class="stat-card accent">
        <div class="stat-label">Total Keys</div>
        <div class="stat-value">${parseInt(res.dbsize).toLocaleString()}</div>
        <div class="stat-sub">in db${currentDb}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Memory Used</div>
        <div class="stat-value">${formatBytes(mem)}</div>
        <div class="stat-sub">${i.used_memory_human || ''}</div>
        ${maxMem > 0 ? `<div class="mem-bar-wrap"><div class="mem-bar ${memBarClass}" style="width:${memPct}%"></div></div>` : ''}
      </div>
      <div class="stat-card">
        <div class="stat-label">Connected Clients</div>
        <div class="stat-value">${i.connected_clients || '—'}</div>
        <div class="stat-sub">blocked: ${i.blocked_clients || 0}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Uptime</div>
        <div class="stat-value">${formatUptime(parseInt(i.uptime_in_seconds||0))}</div>
        <div class="stat-sub">${i.uptime_in_days || 0} days</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Commands/sec</div>
        <div class="stat-value">${parseInt(i.instantaneous_ops_per_sec || 0).toLocaleString()}</div>
        <div class="stat-sub">total: ${parseInt(i.total_commands_processed||0).toLocaleString()}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Hit Ratio</div>
        <div class="stat-value">${calcHitRatio(i)}</div>
        <div class="stat-sub">hits: ${parseInt(i.keyspace_hits||0).toLocaleString()}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Network In</div>
        <div class="stat-value">${formatBytes(parseInt(i.total_net_input_bytes||0))}</div>
        <div class="stat-sub">${i.instantaneous_input_kbps||0} kbps</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Network Out</div>
        <div class="stat-value">${formatBytes(parseInt(i.total_net_output_bytes||0))}</div>
        <div class="stat-sub">${i.instantaneous_output_kbps||0} kbps</div>
      </div>
    </div>

    <div class="section-hdr"><h3>Databases</h3></div>
    <div class="db-grid" style="margin-bottom:24px">${dbCards || '<span style="color:var(--text3)">No databases with keys found</span>'}</div>

    <div class="info-grid">
      <div class="info-section">
        <h4>Server</h4>
        ${infoRow('Version', i.redis_version)}
        ${infoRow('Mode', i.redis_mode)}
        ${infoRow('OS', i.os)}
        ${infoRow('Arch', i.arch_bits+'-bit')}
        ${infoRow('Port', i.tcp_port)}
        ${infoRow('Executable', i.executable||'—')}
        ${infoRow('Config File', i.config_file||'—')}
        ${infoRow('Process ID', i.process_id)}
      </div>
      <div class="info-section">
        <h4>Memory</h4>
        ${infoRow('Used', i.used_memory_human)}
        ${infoRow('RSS', i.used_memory_rss_human)}
        ${infoRow('Peak', i.used_memory_peak_human)}
        ${infoRow('Overhead', i.used_memory_overhead)}
        ${infoRow('Fragmentation', i.mem_fragmentation_ratio)}
        ${infoRow('Allocator', i.mem_allocator)}
        ${infoRow('Max Memory', maxMem > 0 ? formatBytes(maxMem) : 'unlimited')}
        ${infoRow('Max Policy', i.maxmemory_policy)}
      </div>
      <div class="info-section">
        <h4>Replication</h4>
        ${infoRow('Role', i.role)}
        ${infoRow('Connected Slaves', i.connected_slaves)}
        ${infoRow('Master Replid', (i.master_replid||'').slice(0,16)+'…')}
        ${infoRow('Master Repl Offset', i.master_repl_offset)}
        ${infoRow('Repl Backlog Active', i.repl_backlog_active)}
        ${infoRow('Repl Backlog Size', formatBytes(parseInt(i.repl_backlog_size||0)))}
      </div>
      <div class="info-section">
        <h4>Stats</h4>
        ${infoRow('Total Connections', parseInt(i.total_connections_received||0).toLocaleString())}
        ${infoRow('Total Commands', parseInt(i.total_commands_processed||0).toLocaleString())}
        ${infoRow('Rejected Connections', i.rejected_connections)}
        ${infoRow('Expired Keys', parseInt(i.expired_keys||0).toLocaleString())}
        ${infoRow('Evicted Keys', parseInt(i.evicted_keys||0).toLocaleString())}
        ${infoRow('Keyspace Hits', parseInt(i.keyspace_hits||0).toLocaleString())}
        ${infoRow('Keyspace Misses', parseInt(i.keyspace_misses||0).toLocaleString())}
      </div>
    </div>`;
}

function infoRow(label, val) {
  return `<div class="info-row"><span class="ik">${label}</span><span class="iv">${val ?? '—'}</span></div>`;
}
function calcHitRatio(i) {
  const hits = parseInt(i.keyspace_hits||0), misses = parseInt(i.keyspace_misses||0);
  const total = hits + misses;
  return total > 0 ? (hits/total*100).toFixed(1)+'%' : '—';
}
function formatUptime(s) {
  if (s < 60) return s+'s';
  if (s < 3600) return Math.floor(s/60)+'m';
  if (s < 86400) return Math.floor(s/3600)+'h';
  return Math.floor(s/86400)+'d';
}

// ─── CLI ─────────────────────────────────────────────────────────────────────
function cliAppend(prompt, cmd, result, isError = false) {
  const out = document.getElementById('cliOutput');
  const div = document.createElement('div');
  div.className = 'cli-line';
  div.innerHTML = `<div><span class="cli-prompt">&gt; </span><span class="cli-cmd">${e(cmd)}</span></div>
    <div class="cli-result${isError?' err':''}">${formatCliResult(result)}</div>`;
  out.appendChild(div);
  out.scrollTop = out.scrollHeight;
}

function formatCliResult(r) {
  if (r === null) return '<span style="color:var(--text3)">(nil)</span>';
  if (Array.isArray(r)) {
    return r.map((v,i) => `<span style="color:var(--text3)">${i+1})</span> ${e(String(v??'(nil)'))}`).join('\n');
  }
  if (typeof r === 'object') return e(JSON.stringify(r, null, 2));
  return e(String(r));
}

async function cliRun() {
  const input = document.getElementById('cliInput');
  const cmd = input.value.trim();
  if (!cmd) return;
  if (cmd.toLowerCase() === 'clear') {
    document.getElementById('cliOutput').innerHTML = '';
    input.value = '';
    return;
  }
  S.cliHistory.unshift(cmd);
  S.cliHistoryIdx = -1;
  input.value = '';
  const res = await api('cli', { cmd });
  cliAppend('>', cmd, res.result, !res.ok);
}

document.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('cliInput');
  if (input) {
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') cliRun();
      if (e.key === 'ArrowUp') { S.cliHistoryIdx = Math.min(S.cliHistoryIdx+1, S.cliHistory.length-1); input.value = S.cliHistory[S.cliHistoryIdx]||''; }
      if (e.key === 'ArrowDown') { S.cliHistoryIdx = Math.max(S.cliHistoryIdx-1, -1); input.value = S.cliHistoryIdx < 0 ? '' : S.cliHistory[S.cliHistoryIdx]; }
    });
  }
});

// ─── Clients ─────────────────────────────────────────────────────────────────
async function loadClients() {
  const el = document.getElementById('clientsContent');
  el.innerHTML = `<div class="loading-overlay"><div class="spinner"></div></div>`;
  try {
    const res = await api('client_list');
    if (!res.ok) { el.innerHTML = `<div style="color:var(--red);padding:16px">Error: ${e(res.error||'Unknown')}</div>`; return; }

    // res.clients is always a string after the PHP fix, but guard anyway
    const raw = typeof res.clients === 'string' ? res.clients : '';
    if (!raw.trim()) {
      el.innerHTML = `<div style="color:var(--text3);padding:16px">No clients connected.</div>`; return;
    }

    const clients = raw.split('\n').filter(Boolean).map(line => {
      const obj = {};
      // CLIENT LIST lines look like: id=6 addr=127.0.0.1:54320 laddr=... fd=8 name= age=0 idle=0 flags=N db=0 ...
      line.split(' ').forEach(pair => {
        const eq = pair.indexOf('=');
        if (eq > 0) obj[pair.slice(0, eq)] = pair.slice(eq + 1);
      });
      return obj;
    });

    el.innerHTML = `
      <div style="color:var(--text2);font-size:11px;margin-bottom:10px">${clients.length} client${clients.length !== 1 ? 's' : ''} connected</div>
      <div class="value-table-wrap">
        <table class="value-table">
          <thead><tr><th>id</th><th>addr</th><th>name</th><th>cmd</th><th>age</th><th>idle</th><th>flags</th><th>db</th><th>mem</th></tr></thead>
          <tbody>${clients.map(c => `<tr>
            <td style="color:var(--text3)">${e(c.id||'—')}</td>
            <td style="color:var(--cyan)">${e(c.addr||'—')}</td>
            <td style="color:var(--text2)">${e(c.name||'—')}</td>
            <td style="color:var(--accent)">${e(c.cmd||'—')}</td>
            <td>${e(c.age||'0')}s</td>
            <td>${e(c.idle||'0')}s</td>
            <td style="color:var(--yellow)">${e(c.flags||'—')}</td>
            <td>${e(c.db||'0')}</td>
            <td>${formatBytes(parseInt(c.tot_mem || c.mem || c.rbs || '0'))}</td>
          </tr>`).join('')}</tbody>
        </table>
      </div>`;
  } catch (err) {
    el.innerHTML = `<div style="color:var(--red);padding:16px">Failed to load clients: ${e(String(err))}</div>`;
  }
}

// ─── Slow Log ────────────────────────────────────────────────────────────────
async function loadSlowlog() {
  const el = document.getElementById('slowlogContent');
  el.innerHTML = `<div class="loading-overlay"><div class="spinner"></div></div>`;
  try {
    const res = await api('slowlog');
    if (!res.ok) { el.innerHTML = `<div style="color:var(--red);padding:16px">Not available</div>`; return; }
    const entries = res.slowlog;
    if (!Array.isArray(entries) || !entries.length) {
      el.innerHTML = `<div style="color:var(--text3);padding:16px">No slow log entries. Threshold: set <code>slowlog-log-slower-than</code> to a value in microseconds.</div>`; return;
    }
    el.innerHTML = entries.map(entry => {
      if (!Array.isArray(entry)) return '';
      const [id, ts, micros, args] = entry;
      const cmd = Array.isArray(args) ? args.map(String).join(' ') : String(args || '');
      const ms = (parseInt(micros || 0) / 1000).toFixed(2);
      const time = ts ? new Date(ts * 1000).toLocaleString() : '—';
      return `<div class="slowlog-entry">
        <div class="slowlog-cmd">${e(cmd)}</div>
        <div class="slowlog-meta">
          <span>ID: <span>${id}</span></span>
          <span>Duration: <span>${ms}ms</span></span>
          <span>Time: <span>${time}</span></span>
        </div>
      </div>`;
    }).join('');
  } catch (err) {
    el.innerHTML = `<div style="color:var(--red);padding:16px">Failed to load slow log: ${e(String(err))}</div>`;
  }
}

// ─── Config ──────────────────────────────────────────────────────────────────
async function loadConfig() {
  const el = document.getElementById('configContent');
  el.innerHTML = `<div class="loading-overlay"><div class="spinner"></div></div>`;
  try {
    const res = await api('config_get');
    if (!res.ok) { el.innerHTML = `<div style="color:var(--red);padding:16px">Cannot load config (may require elevated privileges)</div>`; return; }
    const cfg = res.config;
    if (!cfg || !Object.keys(cfg).length) {
      el.innerHTML = `<div style="color:var(--text3);padding:16px">No configuration returned.</div>`; return;
    }
    const rows = Object.entries(cfg).map(([k, v]) => `<tr><td>${e(k)}</td><td>${e(v || '(empty)')}</td></tr>`).join('');
    el.innerHTML = `
      <div style="margin-top:12px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
        <table class="config-table"><tbody>${rows}</tbody></table>
      </div>`;
  } catch (err) {
    el.innerHTML = `<div style="color:var(--red);padding:16px">Failed to load config: ${e(String(err))}</div>`;
  }
}

// ─── Init ────────────────────────────────────────────────────────────────────
loadKeys();
</script>

<?php endif; ?>
</body>
</html>
