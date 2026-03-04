<?php
final class RunState {
    
    private string $dir;

    public function __construct() {

        $this->dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'geocoder_batch_runs';

        if (!is_dir($this->dir)) {
            if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new RuntimeException("Cannot create temp dir: {$this->dir}");
            }
        }

        chmod($this->dir, 0700); // max permissions for security

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }

        if (!is_writable($this->dir)) {
            throw new RuntimeException("RunState directory is not writable");
        }
    }

    private function path(string $runId): string {

        if (!preg_match('/^[a-f0-9]{32}$/', $runId)) {
            throw new RuntimeException("Invalid runId");
        }
        return $this->dir . DIRECTORY_SEPARATOR . "{$runId}.json";
    }

    public function create(array $data): string {
        $runId = bin2hex(random_bytes(16)); // 32 hex chars

        $path = $this->path($runId);

        $data = array_merge([
            "runId" => $runId,
            "status" => "running",
            "createdAt" => gmdate('c'),
            "updatedAt" => gmdate('c'),
        ], $data);

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        chmod($path, 0600);

        return $runId;
    }

    public function read(string $runId): array {
        $path = $this->path($runId);
        if (!file_exists($path)) throw new RuntimeException("Run not found");
        $raw = file_get_contents($path);
        return json_decode($raw, true) ?: [];
    }

    public function update(string $runId, callable $mutator): array {
        $path = $this->path($runId);
        $fh = fopen($path, 'c+');
        if (!$fh) throw new RuntimeException("Cannot open run file");
        try {
            if (!flock($fh, LOCK_EX)) throw new RuntimeException("Cannot lock run file");

            $raw = stream_get_contents($fh);
            $state = $raw ? (json_decode($raw, true) ?: []) : [];
            $state = $mutator($state) ?: $state;
            $state["updatedAt"] = gmdate('c');

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($state, JSON_PRETTY_PRINT));
            fflush($fh);

            return $state;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}