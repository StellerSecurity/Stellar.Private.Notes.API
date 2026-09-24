<?php
namespace App\Support;

/** Numeric diagnostics only: never request bodies, headers, identities or SQL bindings. */
final class PerformanceMeter
{
    private bool $active = false;
    private string $id = '';
    private string $operation = 'other';
    private int $started = 0;
    private int $dbCount = 0;
    private float $dbMs = 0;
    private float $dbMaxMs = 0;
    private int $httpCount = 0;
    private float $httpMs = 0;
    private int $httpFailures = 0;
    private int $details = 0;

    public function __construct(private readonly \Closure $sink) {}

    public function begin(string $path): void
    {
        $this->active = true;
        $this->id = bin2hex(random_bytes(12));
        // A fixed allowlist prevents note IDs, query strings and arbitrary paths leaking.
        $allowed = ['download','upload','sync-plan','find','auth','negotiate'];
        $action = basename($path);
        $this->operation = in_array($action, $allowed, true) ? $action : ($path === 'up' ? 'health' : 'other');
        $this->started = hrtime(true);
        $this->dbCount = $this->httpCount = $this->httpFailures = $this->details = 0;
        $this->dbMs = $this->dbMaxMs = $this->httpMs = 0;
        $this->emit('start');
    }

    public function query(float $ms, string $sql): void
    {
        if (!$this->active) return;
        $ms = max(0, $ms);
        $this->dbCount++;
        $this->dbMs += $ms;
        $this->dbMaxMs = max($this->dbMaxMs, $ms);
        if ($ms >= 250 && $this->details++ < 5) {
            // Do not retain SQL, bindings, table contents, or reversible SQL encodings.
            $this->emit('slow_query', ['duration_ms' => round($ms, 2), 'query_hash' => hash('sha256', $sql)]);
        }
    }

    public function upstream(float $ms, int $status): void
    {
        if (!$this->active) return;
        $this->httpCount++;
        $this->httpMs += max(0, $ms);
        if ($status === 0 || $status >= 500) $this->httpFailures++;
        if (($ms >= 1000 || $status === 0 || $status >= 500) && $this->details++ < 10) {
            $this->emit('upstream', ['duration_ms' => round(max(0, $ms), 2), 'status' => $status]);
        }
    }

    public function finish(int $status): void
    {
        if (!$this->active) return;
        $this->emit('finish', [
            'status' => $status, 'duration_ms' => round((hrtime(true) - $this->started) / 1e6, 2),
            'db_count' => $this->dbCount, 'db_ms' => round($this->dbMs, 2), 'db_max_ms' => round($this->dbMaxMs, 2),
            'upstream_count' => $this->httpCount, 'upstream_ms' => round($this->httpMs, 2),
            'upstream_failures' => $this->httpFailures, 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ]);
        $this->active = false;
    }

    private function emit(string $event, array $metrics = []): void
    {
        try { ($this->sink)(['event' => $event, 'request_id' => $this->id, 'operation' => $this->operation] + $metrics); }
        catch (\Throwable) { /* Observability must never interrupt saving or downloading notes. */ }
    }
}
