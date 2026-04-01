# RedisGUI

> A single-file PHP Redis management interface — inspired by Adminer, packed with Redis Insight–level features.

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-3.x%20–%207.x-DC382D?style=flat-square&logo=redis&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-00e5a0?style=flat-square)
![Size](https://img.shields.io/badge/Size-Single%20File-blue?style=flat-square)

---

## What is RedisGUI?

RedisGUI is a **single PHP file** that gives you a full-featured Redis management dashboard accessible from any browser. No framework, no Node.js, no npm install — just drop `redis.php` into any web server that runs PHP and open it.

It works identically to how [Adminer](https://www.adminer.org/) works for MySQL: one file, no dependencies, complete control.

---

## Features

### Key Browser
- **SCAN-based iteration** — safe on production databases, never blocks Redis with a KEYS * dump
- **Glob pattern search** — filter keys using Redis glob syntax (`user:*`, `session:?????`, `cache:*:v2`)
- **Type filtering** — instantly filter the browser to show only `string`, `hash`, `list`, `set`, or `zset` keys
- **Paginated results** — 100 keys per page with fast navigation
- **Key metadata** — each key shows its type badge, TTL countdown, and memory footprint
- **Inline search highlighting** — matching portions of key names are highlighted as you type

### Full CRUD for All Data Types

| Type | Read | Create | Update | Delete |
|------|------|--------|--------|--------|
| String | ✅ Raw + JSON pretty-print | ✅ | ✅ | ✅ |
| Hash | ✅ Field table | ✅ Add fields | ✅ Edit per-field | ✅ Per-field |
| List | ✅ Indexed table | ✅ LPUSH / RPUSH | ✅ Per-index | ✅ Per-item |
| Set | ✅ Member table | ✅ SADD | — | ✅ SREM |
| Sorted Set | ✅ Score + member table | ✅ ZADD | ✅ Score update | ✅ ZREM |

### Key Management
- **Rename** any key in-place
- **Edit TTL** with a picker and one-click presets (1 minute, 5 minutes, 1 hour, 1 day, 1 week, persist)
- **Delete** individual keys with confirmation
- **Flush Database** — wipe the selected database with a double-confirmation prompt
- **Create new keys** of any type from a modal with all required fields

### Server Dashboard
- **Memory usage** — used, RSS, peak, fragmentation ratio, allocator, and optional max-memory progress bar
- **Keyspace stats** — total keys, hit ratio (hits / misses), expired keys, evicted keys
- **Throughput** — instantaneous ops/sec, total commands processed, total connections received
- **Network I/O** — bytes in/out with kbps live readout
- **Uptime** — human-readable server uptime
- **Connected clients** count with blocked client count
- **Per-database overview** — clickable cards showing key counts for every active database (db0–db15)
- **INFO sections** — Server, Memory, Replication, and Stats all displayed in a structured grid

### Database Switcher
- Switch between db0–db15 from the top bar dropdown at any time
- Dashboard shows key counts across all databases simultaneously
- Switching databases reloads the key browser automatically

### CLI Console
- Run any Redis command directly from the browser
- Full **command history** — navigate previous commands with ↑ / ↓ arrow keys
- Results are formatted per type: arrays are numbered, nil is labeled, errors are highlighted in red
- `CLEAR` command wipes the terminal output
- Safe: destructive server-level commands are guarded

### Connected Clients
- Live `CLIENT LIST` output parsed into a readable table
- Shows: client ID, address, last command, age, idle time, flags, database, and memory usage
- One-click refresh

### Slow Log Viewer
- Reads `SLOWLOG GET 25` and presents entries as readable cards
- Shows the full command, duration in milliseconds, and human-readable timestamp
- Useful for identifying performance bottlenecks without needing redis-cli

### Configuration Viewer
- Loads and displays all `CONFIG GET *` parameters in a searchable table
- Read-only view — safe to expose without risk of accidental misconfiguration

### JSON Pretty-Print
- String values that contain valid JSON are automatically detected
- Toggle between **Raw view** and **JSON view** with syntax highlighting
- Color-coded by token type: keys (blue), strings (green), numbers (orange), booleans (purple), null (red)

---

## Requirements

| Requirement | Details |
|-------------|---------|
| PHP | 7.4 or higher |
| Redis Extension | Optional — `phpredis` (PECL). Falls back to raw TCP if not installed |
| Web Server | Apache, Nginx, Caddy, or PHP built-in server |
| Redis Server | 3.x, 4.x, 5.x, 6.x, or 7.x |
| Browser | Any modern browser (Chrome, Firefox, Safari, Edge) |

> **No Composer. No npm. No build step. No other files.**

---

## Installation

### Option 1 — Drop into Apache / Nginx Web Root

```bash
# Download the file
wget https://your-source/redis.php -O /var/www/html/redis.php

# Or copy it manually
cp redis.php /var/www/html/redis.php

# Open in browser
http://your-server/redis.php
```

### Option 2 — PHP Built-in Server (local development)

```bash
# Place redis.php in a directory
mkdir redis-gui && cp redis.php redis-gui/
cd redis-gui

# Start PHP's built-in server
php -S localhost:8080

# Open in browser
http://localhost:8080/redis.php
```

### Option 3 — Docker (alongside a Redis container)

```bash
# Start Redis
docker run -d --name redis -p 6379:6379 redis:7-alpine

# Serve redis.php via PHP container on the same network
docker run -d \
  --name redis-gui \
  --link redis:redis \
  -p 8080:80 \
  -v $(pwd)/redis.php:/var/www/html/redis.php \
  php:8.2-apache

# Open in browser
http://localhost:8080/redis.php
```

Then connect using:
- **Host:** `redis`
- **Port:** `6379`
- **Password:** *(leave empty if no auth)*
- **Database:** `0`

### Option 4 — Alongside an Existing PHP Application

Just drop `redis.php` into any directory your web server already serves:

```
/var/www/html/
├── index.php
├── admin/
│   └── redis.php    ← place it here, protect with .htaccess or HTTP auth
└── ...
```

---

## Security

> ⚠️ **RedisGUI provides direct read/write access to your Redis server. Never expose it on a public URL without protection.**

### Recommended: HTTP Basic Auth (Apache)

Create a `.htaccess` file in the same directory as `redis.php`:

```apacheconf
AuthType Basic
AuthName "RedisGUI"
AuthUserFile /etc/apache2/.htpasswd
Require valid-user
```

Generate credentials:

```bash
htpasswd -c /etc/apache2/.htpasswd yourusername
```

### Recommended: HTTP Basic Auth (Nginx)

```nginx
location /redis.php {
    auth_basic "RedisGUI";
    auth_basic_user_file /etc/nginx/.htpasswd;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### Recommended: Restrict by IP

```apacheconf
# Apache — allow only your IP
<Files "redis.php">
    Require ip 192.168.1.100
</Files>
```

```nginx
# Nginx — allow only your IP
location /redis.php {
    allow 192.168.1.100;
    deny all;
}
```

### Other Best Practices

- **Use a Redis password** — set `requirepass yourpassword` in your `redis.conf`
- **Bind Redis to localhost** — set `bind 127.0.0.1` so Redis is not exposed on external interfaces
- **Use TLS** if connecting to a remote Redis over an untrusted network
- **Delete the file** when you are done with your maintenance session
- **Rename the file** from `redis.php` to something non-obvious (e.g., `r8dx92.php`) as a minor obscurity measure

---

## Connecting

When you open `redis.php` in the browser, you will see a login form:

| Field | Default | Description |
|-------|---------|-------------|
| Host | `127.0.0.1` | Hostname or IP of your Redis server |
| Port | `6379` | Redis port |
| Database | `0` | Redis logical database index (0–15) |
| Password | *(empty)* | Leave blank if Redis has no `requirepass` configured |

The session persists in PHP's session storage. Click **Disconnect** in the top bar to log out.

---

## Usage Guide

### Browsing Keys

1. After connecting, the **Key Browser** opens automatically
2. The left panel lists all keys in the selected database
3. Type a **glob pattern** in the search box and press Enter or click Search
   - `*` — all keys
   - `user:*` — all keys starting with `user:`
   - `session:????????` — session keys with exactly 8 characters after the colon
4. Click a **type pill** (string, hash, list, set, zset) to filter by data type
5. Click any key to open its **detail panel** on the right

### Viewing and Editing Values

**String keys**
- The raw value is shown in the detail panel
- If the value is valid JSON, a **JSON View** toggle appears — click it for syntax-highlighted tree view
- Click **Edit** to open the value editor with optional TTL update

**Hash keys**
- All fields are shown in a table with their values
- Click ✏ on any row to edit that field's value
- Click ✕ to delete a field
- Click **+ Add Field** to insert a new field

**List keys**
- Items are shown with their zero-based index
- Click ✕ next to an item to remove it (LREM)
- Click **+ Push Item** to add a new value to the head or tail

**Set keys**
- All members are listed in a table
- Click ✕ to remove a member (SREM)
- Click **+ Add Member** to SADD a new value

**Sorted Set keys**
- Members are shown alongside their scores, sorted ascending
- Click ✕ to ZREM a member
- Click **+ Add Member** to ZADD with a score

### Managing TTL

1. Click the **⏱ TTL** button in the key detail header
2. The current TTL is shown (in seconds, or "No expiry" for persistent keys)
3. Enter a new TTL in seconds, or use the quick presets
4. Set to `-1` to make the key persistent (PERSIST)
5. Click **Save TTL**

### Renaming a Key

1. Click the **✏ Rename** button in the key detail header
2. Enter the new key name
3. Click **Rename** — the browser reloads and selects the renamed key

### Adding a New Key

1. Click **+ New Key** in the top bar, or **+ Add New Key** above the key list
2. Enter a key name
3. Select the data type (string, hash, list, set, zset)
4. Fill in the type-specific fields
5. Optionally set a TTL
6. Click **Create Key**

### Using the CLI

1. Click **CLI Console** in the left sidebar
2. Type any Redis command in the input bar and press Enter
3. Results are displayed above — arrays are numbered, nil is shown explicitly, errors appear in red
4. Use **↑ / ↓** arrow keys to navigate command history
5. Type `CLEAR` to wipe the output

Example commands to try:

```
SET greeting "Hello, Redis!"
GET greeting
HSET user:1 name "Alice" email "alice@example.com"
HGETALL user:1
ZADD leaderboard 1500 "Alice" 1200 "Bob"
ZRANGE leaderboard 0 -1 WITHSCORES
INFO server
DBSIZE
CONFIG GET maxmemory
SLOWLOG GET 10
CLIENT LIST
```

### Switching Databases

- Use the **DB dropdown** in the top bar to switch between db0–db15
- The key browser reloads for the selected database
- The Dashboard shows key counts for all databases simultaneously — click any DB card to switch

### Flushing a Database

1. Click **Flush DB** at the bottom of the left sidebar
2. Read the confirmation prompt carefully — this deletes **all keys** in the current database
3. Confirm to proceed

> This runs `FLUSHDB` on the currently selected database only, not `FLUSHALL`.

---

## Driver Detection

RedisGUI automatically detects the best available connection method:

```
phpredis extension installed?
  └─ YES → Uses native Redis class (fastest, full feature support)
  └─ NO  → Falls back to pure PHP raw TCP socket (zero dependencies)
```

To install the phpredis extension:

```bash
# Ubuntu / Debian
sudo apt install php-redis

# RHEL / CentOS / Rocky
sudo dnf install php-pecl-redis

# Via PECL
pecl install redis
echo "extension=redis.so" >> /etc/php/8.2/apache2/php.ini

# Via Docker
docker run php:8.2-apache sh -c "pecl install redis && docker-php-ext-enable redis"
```

Both drivers produce identical results. The raw TCP fallback supports all commands shown in this guide.

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Enter` (in search box) | Search keys |
| `Enter` (in CLI) | Execute command |
| `↑` (in CLI) | Previous command in history |
| `↓` (in CLI) | Next command in history |

---

## Compatibility

| Redis Version | Support |
|---------------|---------|
| Redis 3.x | ✅ Full |
| Redis 4.x | ✅ Full |
| Redis 5.x | ✅ Full |
| Redis 6.x | ✅ Full (ACL-compatible) |
| Redis 7.x | ✅ Full |
| Valkey 7.x | ✅ Compatible |
| KeyDB | ✅ Compatible |

| PHP Version | Support |
|-------------|---------|
| PHP 7.4 | ✅ |
| PHP 8.0 | ✅ |
| PHP 8.1 | ✅ |
| PHP 8.2 | ✅ |
| PHP 8.3 | ✅ |

---

## Troubleshooting

**"Connection failed" on localhost**

```bash
# Check if Redis is running
redis-cli ping
# Expected: PONG

# Check which address Redis is bound to
grep "^bind" /etc/redis/redis.conf
```

**"Authentication failed"**

```bash
# Check if Redis requires a password
redis-cli config get requirepass
```

**Blank page or PHP errors**

```bash
# Check PHP error log
tail -f /var/log/apache2/error.log

# Verify PHP version
php -v

# Check session write permissions
ls -la /tmp | grep php
```

**MEMORY USAGE command not available (Redis < 4.0)**

The memory size column will show `—` for servers older than Redis 4.0. All other features remain fully functional.

**Slow key browser on large databases**

RedisGUI uses `SCAN` with a count hint of 500 per iteration. On databases with millions of keys, the initial scan may take a few seconds. Use specific glob patterns (`user:*` instead of `*`) to narrow results and speed up browsing.

---

## License

MIT — free to use, modify, and distribute. Attribution appreciated but not required.

---

## Contributing

RedisGUI is a single self-contained file by design. If you extend it:

- Keep everything in `redis.php` — no external files, no build step
- Test against both the `phpredis` extension and the raw TCP fallback
- Test against Redis 3.x and Redis 7.x
- Keep the UI readable on screens 1280px wide and above

---

*RedisGUI is not affiliated with Redis Ltd. Redis is a trademark of Redis Ltd.*
