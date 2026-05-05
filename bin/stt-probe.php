<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * Temporary debugging CLI for the MIKO STT provider.
 *
 *   php bin/stt-probe.php <file> [--async] [--deferred] [--force]
 *
 * Без --async — sync, иначе async + polling раз в 15 сек до done=true.
 * --deferred включает Model=deferred-general. --force форсирует ре-кодирование.
 */

namespace Modules\ModuleSpeechRecognize\bin;

use Modules\ModuleSpeechRecognize\Lib\SpeechRecognizeConf;
use Modules\ModuleSpeechRecognize\Lib\Stt\AudioPreparer;
use Modules\ModuleSpeechRecognize\Lib\Stt\MikoSttClient;

require_once 'Globals.php';

$args = array_slice($argv, 1);
$file = null;
$async = false;
$deferred = false;
$force = false;
foreach ($args as $arg) {
    if ($arg === '--async')    { $async = true;    continue; }
    if ($arg === '--deferred') { $deferred = true; continue; }
    if ($arg === '--force')    { $force = true;    continue; }
    if ($file === null)        { $file = $arg;     continue; }
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/stt-probe.php <file> [--async] [--deferred] [--force]\n");
    exit(1);
}

$sr = new SpeechRecognizeConf();
$sr->getSettings();

$preparer = new AudioPreparer($sr->getMikoTmpDir());
$prepared = $preparer->prepare($file, 'probe-' . getmypid(), $force);
echo "Prepared: " . json_encode([
    'path'      => $prepared['path'],
    'duration'  => $prepared['duration'],
    'channels'  => $prepared['channels'],
    'temporary' => $prepared['temporary'],
    'size'      => $prepared['size'],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

$client = new MikoSttClient();
if (!$client->hasKey()) {
    fwrite(STDERR, "PBXLicense is empty — set the key first.\n");
    exit(2);
}
$requestId = 'probe.' . microtime(true);

try {
    if (!$async) {
        $resp = $client->recognizeSync($prepared['path'], $requestId);
        echo "SYNC RESULT: " . $resp['text'] . PHP_EOL;
    } else {
        $taskId = $client->submitAsync($prepared['path'], $requestId, $deferred);
        echo "Submitted: $taskId" . PHP_EOL;
        while (true) {
            sleep(15);
            $r = $client->fetchAsync($taskId, $requestId);
            if ($r['done']) {
                echo "DONE, chunks=" . count($r['chunks']) . PHP_EOL;
                foreach ($r['chunks'] as $c) {
                    echo sprintf("  ch=%d %.2f-%.2f: %s\n",
                        (int)($c['channel'] ?? 0),
                        (float)($c['start_time'] ?? 0),
                        (float)($c['end_time'] ?? 0),
                        (string)($c['text'] ?? ''));
                }
                break;
            }
            echo "  still pending...\n";
        }
    }
} finally {
    if (!empty($prepared['temporary']) && is_file($prepared['path'])) {
        @unlink($prepared['path']);
    }
}
