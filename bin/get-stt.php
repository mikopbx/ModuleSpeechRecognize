<?php
// docker run -it -d --rm -p 8080:8080 tinkoffcreditsystems/t-one:0.1.0 --workers 6 --ws websockets --ws-ping-interval 60 --ws-ping-timeout 6
// Usage: php ws_client.php /path/to/audio.mp3 ws://HOST:8080/api/ws
require_once __DIR__ . '/../vendor/autoload.php';
use WebSocket\Client;

[$script, $mp3Path, $wsUrl] = $argv + [null, null, 'ws://localhost:8080/api/ws'];
if (!$mp3Path) {
    fwrite(STDERR, "!Usage: php ws_client.php <file.mp3> [ws_url]\n");
    exit(1);
}
$cmd = sprintf('lame --silent --decode %s - | sox -q -t wav - -t s16 -L -r 8000 -c 1 -', escapeshellarg($mp3Path));
$descriptors = [
    0 => ['pipe', 'r'], // stdin
    1 => ['pipe', 'w'], // stdout (raw PCM)
    2 => ['pipe', 'w'], // stderr (progress/errors)
];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!\is_resource($proc)) {
    fwrite(STDERR, "Failed to start decoder pipeline for $mp3Path\n");
    exit(2);
}
fclose($pipes[0]);                    // no stdin
stream_set_blocking($pipes[1], true); // PCM stdout (blocking)
stream_set_blocking($pipes[2], false);// stderr non-blocking

$client = new Client($wsUrl, ['timeout' => 120]);
// Wait for the very first "ready" before sending any chunk
function waitReady(Client $c): void {
    $lastPingAt = microtime(true);
    while (true) {
        if (microtime(true) - $lastPingAt > 10) { // каждые ~10s
            try { $c->ping(); } catch (\Throwable $e) {}
            $lastPingAt = microtime(true);
        }
        $msg = $c->receive(); // blocking with client timeout
        $data = @json_decode($msg, true);
        if (!is_array($data) || !isset($data['event'])) {
            continue;
        }
        if ($data['event'] === 'transcript') {
            echo "$msg".PHP_EOL;
            // $p = $data['phrase'] ?? [];
            // echo ($p['start_time'] ?? 0) . '-' . ($p['end_time'] ?? 0) . 's: ' . ($p['text'] ?? '') . PHP_EOL;
        }
        if ($data['event'] === 'ready') {
            return;
        }
    }
}
waitReady($client);

// Stream PCM in 300 ms chunks (2400 samples * 2 bytes = 4800 bytes)
$chunkBytes = 2400 * 2;
$sent = 0;
while (!feof($pipes[1])) {
    $buf = fread($pipes[1], $chunkBytes);
    if ($buf === '' || $buf === false) {
        break;
    }
    $client->send($buf, 'binary');
    $sent++;
    waitReady($client);
}

// Send empty binary frame to mark end-of-stream
$client->send('', 'binary');
// Drain remaining transcripts up to ~3s
$deadline = microtime(true) + 5.0;
$client->setTimeout(1); // integer seconds
while (microtime(true) < $deadline) {
    try {
        $msg = $client->receive();
        echo("!$msg ".PHP_EOL);
//        $data = @json_decode($msg, true);
//        if (\is_array($data) && ($data['event'] ?? '') === 'transcript') {
//            $p = $data['phrase'] ?? [];
//            echo ($p['start_time'] ?? 0) . '-' . ($p['end_time'] ?? 0) . 's: ' . ($p['text'] ?? '') . PHP_EOL;
//        }
    } catch (\WebSocket\ConnectionException $e) {
    }
}

// Cleanup
fclose($pipes[1]);
proc_close($proc);
$client->close();