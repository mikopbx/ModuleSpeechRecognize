#!/usr/bin/php
<?php

require_once 'Globals.php';
use MikoPBX\Core\Asterisk\AGI;
// EAGI: audio on FD 3, AGI protocol on STDIN/STDOUT
/**
 * 1,NoOp(STT demo)
 * n,Set(CHANNEL(audioreadformat)=slin16)       ; 16‑bit PCM, 8 kHz
 * n,Set(CHANNEL(readformat)=slin16)
 * n,EAGI(/storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/bin/get-stt-eagi.php,ws://172.16.32.216:8080/api/ws)
 * n,Hangup()
 */
require_once __DIR__ . '/../vendor/autoload.php';
use WebSocket\Client;

$agi = new AGI();
$agi->answer();

// 2) Args
$wsUrl = $argv[1] ?? 'ws://127.0.0.1:8080/api/ws';

// 3) Open EAGI audio (raw PCM16LE mono 8kHz if audioreadformat=slin16)
$audio = fopen('php://fd/3', 'rb');
if (!$audio) { fwrite(STDERR, "EAGI fd3 open failed\n"); exit(1); }
stream_set_blocking($audio, true);

// 4) Connect WS
$client = new Client($wsUrl, ['timeout' => 30]);

// Helper: wait for server "ready" and collect transcripts
$results = [];
$waitReady = function (Client $c) use (&$results,$agi) {
    while (true) {
        $msg = $c->receive();
        $data = @json_decode($msg, true);
        if (!is_array($data) || !isset($data['event'])) continue;
        if ($data['event'] === 'transcript' && isset($data['phrase'])) {
            $results[] = $data['phrase'];  // collect
            $agi->verbose(urlencode(json_encode($data['phrase'])),3);
            $agi->verbose($data['phrase']['text']??'',3);
            continue;
        }
        if ($data['event'] === 'ready') return;
    }
};

$chunkBytes = 2400 * 2; // 4800
$carry = '';
// initial ready
$waitReady($client);
while (true) {
    // накапливаем до полного чанка
    while (strlen($carry) < $chunkBytes) {
        $buf = fread($audio, $chunkBytes - strlen($carry));
        if ($buf === '' || $buf === false) { break 2; } // EOF → выходим из обоих циклов
        $carry .= $buf;
    }

    // отправляем ровно 4800 байт
    $chunk = substr($carry, 0, $chunkBytes);
    $carry = substr($carry, $chunkBytes);

    // сервер уже ждёт после готовности → отправляем, затем ждём следующий ready
    $client->send($chunk, 'binary');
    $waitReady($client);
}

// после EOF: отправляем остаток с паддингом (один раз)
if ($carry !== '') {
    if (strlen($carry) < $chunkBytes) {
        $carry .= str_repeat("\0", $chunkBytes - strlen($carry));
    }
    $client->send($carry, 'binary');
    $waitReady($client);
}

// EOF кадр и дренаж
$client->send('', 'binary');
$client->setTimeout(2);
$timeouts = 0;
while ($timeouts < 5) {  // ~10s
    try {
        $msg = $client->receive();
        $data = @json_decode($msg, true);
        if (is_array($data) && ($data['event'] ?? '') === 'transcript' && isset($data['phrase'])) {
            $results[] = $data['phrase'];
            $timeouts = 0;
        }
    } catch (\WebSocket\ConnectionException $e) {
        $timeouts++;
    }
}
fclose($audio);
$client->close();