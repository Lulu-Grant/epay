<?php
namespace lib;

/** Short-lived display aggregates only. Loaders and payment decisions always use SQL. */
final class ListReadCache
{
    private const FORMAT = 1;
    private const SHARDS = 256;
    private static ?self $site = null;
    private static array $metrics = [];
    private static array $reported = [];
    private static bool $observerRegistered = false;
    private array $config;
    private ?string $directory = null;
    private string $release;

    public function __construct(array $config, array $identity, ?string $release = null)
    {
        foreach (['counts_ttl', 'summaries_ttl', 'dictionaries_ttl', 'metadata_ttl', 'max_entries', 'max_bytes', 'metrics_sample_permille'] as $key) {
            if (isset($config[$key]) && !is_int($config[$key])) { $config = []; self::fault('config'); break; }
        }
        foreach (['counts_enabled', 'summaries_enabled', 'dictionaries_enabled', 'metadata_enabled', 'admin_enabled', 'merchants_enabled'] as $key) {
            if (isset($config[$key]) && !is_bool($config[$key])) { $config = []; self::fault('config'); break; }
        }
        if (isset($config['schema_epoch']) && (!is_string($config['schema_epoch']) || strlen($config['schema_epoch']) > 128)) {
            $config = []; self::fault('config');
        }
        if (isset($config['merchant_allowlist']) && (!is_array($config['merchant_allowlist'])
            || array_filter($config['merchant_allowlist'], fn($uid)=>!is_int($uid) || $uid < 1))) {
            $config = [];
            self::fault('config');
        }
        $this->config = $config;
        // Never derive a cache identity from a password, token, or request host header.
        $site = hash('sha256', json_encode(array_map('strval', [
            $identity['host'] ?? '', $identity['port'] ?? '',
            $identity['dbname'] ?? '', $identity['dbqz'] ?? '',
        ]), JSON_THROW_ON_ERROR));
        $this->release = $release ?? hash('sha256', (string)realpath(dirname(__DIR__, 2)));
        if (!$this->anyEnabled()) return;
        try {
            $base = $config['directory'] ?? '';
            if (!is_string($base) || !is_dir($base) || is_link($base)) throw new \RuntimeException('directory');
            $base = realpath($base);
            $web = realpath(dirname(__DIR__, 2));
            $normalizedBase = strtolower(str_replace('\\', '/', $base)).'/';
            $normalizedWeb = strtolower(str_replace('\\', '/', $web)).'/';
            if (str_starts_with($normalizedBase, $normalizedWeb)) throw new \RuntimeException('web_directory');
            $this->directory = $base.DIRECTORY_SEPARATOR.$site;
            $this->ensureDirectory($this->directory);
            foreach (['data', 'locks', 'tags'] as $part) $this->ensureDirectory($this->directory.'/'.$part);
        } catch (\Throwable $e) {
            $this->directory = null;
            self::fault('directory');
        }
    }

    public static function forSite(): self
    {
        if (self::$site === null) {
            global $dbconfig;
            $config = [];
            $path = getenv('EPAY_LIST_CACHE_CONFIG') ?: '/etc/epay/list-read-cache.json';
            if (is_file($path)) {
                try {
                    $raw = @file_get_contents($path, false, null, 0, 16385);
                    if ($raw === false || strlen($raw) > 16384) throw new \RuntimeException('config');
                    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($config)) throw new \RuntimeException('config');
                } catch (\Throwable $e) {
                    $config = [];
                    self::fault('config');
                }
            }
            self::$site = new self($config, is_array($dbconfig) ? $dbconfig : []);
            if (!self::$observerRegistered && self::$site->anyEnabled()) {
                self::$observerRegistered = true;
                // One sampled aggregate per request/CLI batch; never log filters, UID, SQL or keys.
                register_shutdown_function(static function (): void {
                    $rate = max(0, min(1000, (int)(self::$site?->config['metrics_sample_permille'] ?? 10)));
                    if (mt_rand(1, 1000) <= $rate && self::$metrics !== []) {
                        error_log('list_read_cache_metrics '.json_encode(['sample_permille'=>$rate, 'counters'=>self::$metrics]));
                    }
                });
            }
        }
        return self::$site;
    }

    /** CLI batches and tests may reload the server-side control file. */
    public static function reset(): void { self::$site = null; }
    public static function metrics(): array { return self::$metrics; }

    private function anyEnabled(): bool
    {
        foreach (['counts', 'summaries', 'dictionaries', 'metadata'] as $kind) {
            if (($this->config[$kind.'_enabled'] ?? false) === true) return true;
        }
        return false;
    }

    private function enabled(string $kind, array $scope): bool
    {
        if ($this->directory === null || ($this->config[$kind.'_enabled'] ?? false) !== true) return false;
        $audience = $scope['audience'] ?? '';
        if ($audience === 'admin') return ($this->config['admin_enabled'] ?? false) === true;
        if ($audience === 'merchant') {
            $uid = (int)($scope['uid'] ?? 0);
            return $uid > 0 && (($this->config['merchants_enabled'] ?? false) === true
                || in_array($uid, $this->config['merchant_allowlist'] ?? [], true));
        }
        return $audience === 'schema';
    }

    private function ttl(string $kind): int
    {
        $defaults = ['counts'=>10, 'summaries'=>15, 'dictionaries'=>300, 'metadata'=>3600];
        $maxima = ['counts'=>30, 'summaries'=>30, 'dictionaries'=>300, 'metadata'=>3600];
        return max(1, min($maxima[$kind] ?? 30, (int)($this->config[$kind.'_ttl'] ?? $defaults[$kind] ?? 10)));
    }

    /** Returns value + public timing metadata; exceptions from loader are never swallowed. */
    public function remember(string $kind, string $namespace, array $scope, array $filters,
        array $tags, callable $loader, bool $fresh = false): array
    {
        $ttl = $this->enabled($kind, $scope) ? $this->ttl($kind) : 0;
        $lock = null;
        $key = null;
        $generation = null;
        if ($ttl > 0 && !$fresh) {
            try {
                $deadline = hrtime(true) + 50_000_000;
                $generation = $this->generations($tags, $deadline);
                $key = hash('sha256', json_encode(self::canonical([
                    self::FORMAT, $this->release, (string)($this->config['schema_epoch'] ?? '1'),
                    $namespace, $scope, $filters, $generation,
                ]), JSON_THROW_ON_ERROR));
                $entry = $this->readEntry($key, $ttl, $kind);
                if ($entry !== null) return $this->result($entry['value'], $entry['started'], $ttl, true, $kind);
                $lock = $this->lock(substr($key, 0, 2));
                while ($lock === null && hrtime(true) < $deadline) {
                    usleep(2000);
                    $entry = $this->readEntry($key, $ttl, $kind);
                    if ($entry !== null) return $this->result($entry['value'], $entry['started'], $ttl, true, $kind);
                    $lock = $this->lock(substr($key, 0, 2));
                }
                if ($lock !== null) {
                    $entry = $this->readEntry($key, $ttl, $kind);
                    if ($entry !== null) {
                        self::unlock($lock);
                        return $this->result($entry['value'], $entry['started'], $ttl, true, $kind);
                    }
                } else self::metric($kind, 'lock_fallback');
            } catch (\Throwable $e) {
                self::unlock($lock);
                $lock = null;
                self::fault('read');
            }
        }
        $started = microtime(true);
        try {
            self::metric($kind, 'load');
            $value = $loader();
            self::metric($kind, 'load_microseconds', (int)round((microtime(true) - $started) * 1000000));
            if ($value === false || $value === null) throw new \RuntimeException('列表统计查询失败，请重试');
            if ($lock !== null && $key !== null && $started + $ttl > microtime(true)) {
                try {
                    if ($generation === $this->generations($tags)) {
                        $this->storeEntry($key, ['format'=>self::FORMAT, 'release'=>$this->release,
                            'started'=>$started, 'expires'=>$started + $ttl, 'value'=>$value]);
                    } else self::metric($kind, 'invalidated_during_load');
                } catch (\Throwable $e) { self::fault('write'); }
            }
            return $this->result($value, $started, $ttl, false, $kind);
        } finally { self::unlock($lock); }
    }

    private function result(mixed $value, float $started, int $ttl, bool $cached, string $kind): array
    {
        if ($cached) self::metric($kind, 'hit');
        return ['value'=>$value, 'meta'=>['as_of'=>date('c', (int)$started),
            'max_age_seconds'=>$ttl, 'cached'=>$cached]];
    }

    private static function canonical(array $data): array
    {
        if (!array_is_list($data)) ksort($data, SORT_STRING);
        foreach ($data as &$value) if (is_array($value)) $value = self::canonical($value);
        return $data;
    }

    private function generations(array $tags, int $deadline = 0): array
    {
        $result = [];
        sort($tags, SORT_STRING);
        foreach (array_unique($tags) as $tag) {
            $hash = hash('sha256', $tag);
            $path = $this->directory.'/tags/'.$hash;
            $token = $this->token($path);
            if ($token === null) {
                $lock = $this->lock('t-'.substr($hash, 0, 2));
                while ($lock === null && hrtime(true) < $deadline) {
                    usleep(1000);
                    $lock = $this->lock('t-'.substr($hash, 0, 2));
                }
                if ($lock === null) throw new \RuntimeException('tag_busy');
                try {
                    $token = $this->token($path);
                    if ($token === null) {
                        $token = bin2hex(random_bytes(16));
                        $this->atomicWrite($path, $token);
                    }
                } finally { self::unlock($lock); }
            }
            $result[$hash] = $token;
        }
        return $result;
    }

    private function token(string $path): ?string
    {
        if (is_link($path)) throw new \RuntimeException('tag_link');
        $value = @file_get_contents($path, false, null, 0, 33);
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) ? $value : null;
    }

    /** Best effort and non-blocking, including when called from payment paths. */
    public function invalidate(array $tags): void
    {
        if ($this->directory === null) return;
        foreach (array_unique($tags) as $tag) {
            $lock = null;
            try {
                $hash = hash('sha256', $tag);
                $lock = $this->lock('t-'.substr($hash, 0, 2));
                if ($lock === null) throw new \RuntimeException('tag_busy');
                $this->atomicWrite($this->directory.'/tags/'.$hash, bin2hex(random_bytes(16)));
                self::metric('invalidation', 'success');
            } catch (\Throwable $e) {
                self::fault('invalidation');
                // Stop retrying a broken cache throughout the rest of this request/batch.
                // Reads also fall back to SQL; the next request retries configuration normally.
                $this->directory = null;
                return;
            }
            finally { self::unlock($lock); }
        }
    }

    private function readEntry(string $key, int $ttl, string $kind): ?array
    {
        $entry = $this->readJson($this->entryPath($key), 65536);
        $now = microtime(true);
        if (!is_array($entry) || ($entry['format'] ?? null) !== self::FORMAT
            || ($entry['release'] ?? '') !== $this->release || !isset($entry['started'], $entry['expires'])
            || !is_numeric($entry['started']) || !is_numeric($entry['expires'])
            || $entry['started'] > $now || min($entry['expires'], $entry['started'] + $ttl) <= $now
            || !array_key_exists('value', $entry) || $entry['value'] === null || $entry['value'] === false) return null;
        if ($kind === 'counts' ? (!is_int($entry['value']) || $entry['value'] < 0) : !is_array($entry['value'])) return null;
        return $entry;
    }

    private function entryPath(string $key): string
    {
        return $this->directory.'/data/'.substr($key, 0, 2).'/'.$key.'.json';
    }

    /** Caller holds this shard lock. Reserve quota before data write (crash-safe overcount). */
    private function storeEntry(string $key, array $entry): void
    {
        $encoded = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > 65536) { self::metric('capacity', 'entry_limit'); return; }
        $dir = dirname($this->entryPath($key));
        $this->ensureDirectory($dir);
        $indexPath = $dir.'/index.json';
        $index = $this->readIndex($indexPath);
        // The small accounting index is bounded per shard; never scan data directories on requests.
        foreach ($index as $oldKey=>$oldEntry) {
            if ($oldEntry['expires'] > microtime(true)) continue;
            $oldPath = $this->entryPath($oldKey);
            if (is_link($oldPath)) throw new \RuntimeException('file_link');
            if (file_exists($oldPath) && !@unlink($oldPath)) throw new \RuntimeException('eviction');
            unset($index[$oldKey]);
        }
        $maxEntries = max(1, (int)floor(min(10000, max(256, (int)($this->config['max_entries'] ?? 10000))) / self::SHARDS));
        $maxBytes = (int)floor(min(67108864, max(262144, (int)($this->config['max_bytes'] ?? 67108864))) / self::SHARDS);
        $reserved = max(strlen($encoded), (int)($index[$key]['bytes'] ?? 0));
        $bytes = array_sum(array_column($index, 'bytes')) - (int)($index[$key]['bytes'] ?? 0) + $reserved;
        if ((!isset($index[$key]) && count($index) >= $maxEntries) || $bytes > $maxBytes) {
            self::metric('capacity', 'shard_limit');
            return;
        }
        $index[$key] = ['bytes'=>$reserved, 'expires'=>$entry['expires']];
        $this->atomicWrite($indexPath, json_encode($index, JSON_THROW_ON_ERROR));
        $this->atomicWrite($this->entryPath($key), $encoded);
    }

    private function readIndex(string $path): array
    {
        if (!file_exists($path) && !is_link($path)) return [];
        $index = $this->readJson($path, 65536);
        if (!is_array($index)) throw new \RuntimeException('index_corrupt');
        foreach ($index as $key=>$entry) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !is_array($entry)
                || !isset($entry['bytes'], $entry['expires']) || !is_int($entry['bytes'])
                || $entry['bytes'] < 0 || !is_numeric($entry['expires'])) throw new \RuntimeException('index_corrupt');
        }
        return $index;
    }

    /** Bounded shard rotation; never removes tag files or lock files. */
    public function clean(int $limit = 1000): array
    {
        $result = ['checked'=>0, 'removed'=>0, 'failed'=>0];
        if ($this->directory === null) return $result;
        $limit = max(1, min(1000, $limit));
        $control = $this->lock('cleanup');
        if ($control === null) return $result;
        try {
            $statePath = $this->directory.'/cleanup.json';
            $state = $this->readJson($statePath, 1024);
            $start = (int)($state['shard'] ?? 0) % self::SHARDS;
            $next = $start;
            for ($i = 0; $i < self::SHARDS && $result['checked'] < $limit; $i++) {
                $shard = sprintf('%02x', ($start + $i) % self::SHARDS);
                $next = ($start + $i + 1) % self::SHARDS;
                $lock = $this->lock($shard);
                if ($lock === null) continue;
                try {
                    $dir = $this->directory.'/data/'.$shard;
                    if (!is_dir($dir) || is_link($dir)) continue;
                    $index = $this->readIndex($dir.'/index.json');
                    $remaining = $index;
                    $retained = [];
                    foreach ($index as $key=>$entry) {
                        if ($result['checked'] >= $limit) { $next = hexdec($shard); break; }
                        $result['checked']++;
                        unset($remaining[$key]);
                        $path = $this->entryPath($key);
                        $retained[$key] = $entry;
                        if (is_link($path) || is_link($path.'.tmp')) { $result['failed']++; continue; }
                        if (is_file($path.'.tmp') && !@unlink($path.'.tmp')) $result['failed']++;
                        $data = $this->readJson($path, 65536);
                        if ($entry['expires'] <= microtime(true) || !is_array($data) || ($data['release'] ?? '') !== $this->release) {
                            if (file_exists($path) && !@unlink($path)) { $result['failed']++; continue; }
                            unset($retained[$key]);
                            $result['removed']++;
                        }
                    }
                    // Rotate inspected live keys to the end so even limit=1 eventually visits every key.
                    $this->atomicWrite($dir.'/index.json', json_encode($remaining + $retained, JSON_THROW_ON_ERROR));
                } catch (\Throwable $e) { $result['failed']++; self::fault('cleanup'); }
                finally { self::unlock($lock); }
            }
            $this->atomicWrite($statePath, json_encode(['shard'=>$next], JSON_THROW_ON_ERROR));
        } finally { self::unlock($control); }
        return $result;
    }

    private function readJson(string $path, int $max): ?array
    {
        if (is_link($path) || is_link(dirname($path))) throw new \RuntimeException('file_link');
        $raw = @file_get_contents($path, false, null, 0, $max + 1);
        if (!is_string($raw) || strlen($raw) > $max) return null;
        $value = json_decode($raw, true, 32);
        return is_array($value) ? $value : null;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700))) throw new \RuntimeException('mkdir');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0) throw new \RuntimeException('directory_mode');
    }

    private function atomicWrite(string $path, string $content): void
    {
        if (is_link($path) || is_link(dirname($path))) throw new \RuntimeException('file_link');
        // Every caller holds the target's shard/tag/control lock. Reusing one private
        // temp name bounds leftovers after a killed process; never follow a link.
        $temporary = $path.'.tmp';
        if (is_link($temporary)) throw new \RuntimeException('temp_link');
        $handle = @fopen($temporary, 'c+b');
        if ($handle === false) throw new \RuntimeException('open');
        try {
            @chmod($temporary, 0600);
            if (!ftruncate($handle, 0)) throw new \RuntimeException('truncate');
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) throw new \RuntimeException('write');
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $path)) throw new \RuntimeException('replace');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function lock(string $name)
    {
        $path = $this->directory.'/locks/'.$name.'.lock';
        if (is_link($path)) throw new \RuntimeException('lock_link');
        $handle = @fopen($path, 'c+b');
        // Windows mandatory locks can reject opening an already locked file.
        if ($handle === false) { self::metric('lock', 'open_unavailable'); return null; }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); return null; }
        return $handle;
    }

    private static function unlock($handle): void
    {
        if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    }

    private static function metric(string $kind, string $event, int $amount = 1): void
    {
        $key = $kind.'.'.$event;
        self::$metrics[$key] = (self::$metrics[$key] ?? 0) + $amount;
    }

    private static function fault(string $reason): void
    {
        self::metric('fault', $reason);
        if (!isset(self::$reported[$reason])) {
            self::$reported[$reason] = true;
            error_log('list_read_cache '.$reason);
        }
    }
}
