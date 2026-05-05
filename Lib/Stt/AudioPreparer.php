<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2026 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace Modules\ModuleSpeechRecognize\Lib\Stt;

use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;

/**
 * Готовит аудиофайл к отправке в провайдер MIKO (speech.mikolab.ru).
 * Реализует решающее дерево из брифа: оставляем нормальный mp3 как есть,
 * перекодируем мелкобитные mp3 и не-mp3 контейнеры в mp3 32 kbps,
 * сохраняя число каналов оригинала.
 */
class AudioPreparer
{
    public const MAX_DURATION_SECONDS = 3600;
    public const TARGET_BITRATE = '32k';
    public const MIN_ACCEPTABLE_MP3_BITRATE = 24000;

    private string $tmpDir;

    public function __construct(string $tmpDir)
    {
        $this->tmpDir = rtrim($tmpDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->tmpDir)) {
            Util::mwMkdir($this->tmpDir);
        }
    }

    /**
     * Считывает параметры аудио. Приоритет — ffprobe, fallback — soxi.
     * @return array{codec_name:string, channels:int, sample_rate:int, bit_rate:int, duration:float}
     */
    public function probe(string $file): array
    {
        $info = [
            'codec_name'  => '',
            'channels'    => 0,
            'sample_rate' => 0,
            'bit_rate'    => 0,
            'duration'    => 0.0,
        ];
        if (!is_file($file)) {
            return $info;
        }
        $ffprobe = Util::which('ffprobe');
        if (!empty($ffprobe)) {
            $cmd = sprintf(
                '%s -v error -of csv=p=0 -show_entries stream=codec_name,sample_rate,channels,bit_rate -show_entries format=duration %s',
                $ffprobe,
                escapeshellarg($file)
            );
            $out = [];
            Processes::mwExec($cmd, $out);
            // First line — stream values; last line — duration.
            $streamLine = $out[0] ?? '';
            $durationLine = $out[count($out) - 1] ?? '';
            $parts = array_pad(explode(',', $streamLine), 4, '');
            $info['codec_name']  = trim($parts[0]);
            $info['channels']    = (int)trim($parts[1]);
            $info['sample_rate'] = (int)trim($parts[2]);
            $info['bit_rate']    = (int)trim($parts[3]);
            $info['duration']    = (float)trim($durationLine);
            return $info;
        }
        // Fallback на soxi (нет webm/opus, но для legacy-mp3 хватает).
        $soxi = Util::which('soxi');
        if (!empty($soxi)) {
            $type = trim((string)shell_exec(sprintf('%s -t %s 2>/dev/null', $soxi, escapeshellarg($file))));
            $rate = (int)trim((string)shell_exec(sprintf('%s -r %s 2>/dev/null', $soxi, escapeshellarg($file))));
            $ch   = (int)trim((string)shell_exec(sprintf('%s -c %s 2>/dev/null', $soxi, escapeshellarg($file))));
            $dur  = (float)trim((string)shell_exec(sprintf('%s -D %s 2>/dev/null', $soxi, escapeshellarg($file))));
            $info['codec_name']  = strtolower($type);
            $info['channels']    = $ch;
            $info['sample_rate'] = $rate;
            $info['duration']    = $dur;
            // soxi не отдаёт bit_rate точно; считаем безопасно — пометим как «низкий»,
            // если файл явно похож на legacy-mp3 mono 16 kbps.
            if ($info['codec_name'] === 'mp3' && $info['channels'] <= 1 && $dur > 0) {
                $sizeBits = (int)((@filesize($file) ?: 0) * 8);
                $info['bit_rate'] = (int)round($sizeBits / max($dur, 1));
            }
            return $info;
        }
        return $info;
    }

    /**
     * Готовит файл к отправке. Возвращает массив:
     *  path        — путь к итоговому файлу (исходный либо перекодированный во временный),
     *  duration    — длительность в секундах,
     *  channels    — число каналов,
     *  isMp3       — true если итог mp3 (для MIKO всегда true после prepare),
     *  temporary   — нужно ли удалить файл после отправки,
     *  size        — размер в байтах.
     *
     * @param string $file       полный путь к исходному CDR-файлу
     * @param string $uniqueId   UNIQUEID звонка (используется в имени временного файла)
     * @param bool   $force      форс-перекодирование, даже если mp3 нормальный
     * @return array{path:string, duration:float, channels:int, isMp3:bool, temporary:bool, size:int}
     * @throws SkipAudioException если файл нельзя отправлять (битый, пустой, слишком длинный)
     */
    public function prepare(string $file, string $uniqueId, bool $force = false): array
    {
        if (!is_file($file)) {
            throw new SkipAudioException('File not found: ' . $file);
        }
        $size = (int)@filesize($file);
        if ($size <= 0) {
            throw new SkipAudioException('File is empty: ' . $file);
        }
        $info = $this->probe($file);
        if ($info['duration'] <= 0.1) {
            throw new SkipAudioException('Cannot read duration: ' . $file);
        }
        if ($info['duration'] > self::MAX_DURATION_SECONDS) {
            throw new SkipAudioException('Audio too long: ' . $info['duration'] . 's > ' . self::MAX_DURATION_SECONDS);
        }
        $codec = strtolower($info['codec_name']);
        $channels = max(1, min(2, $info['channels'] ?: 1));

        $isAcceptableMp3 = !$force
            && $codec === 'mp3'
            && $info['bit_rate'] >= self::MIN_ACCEPTABLE_MP3_BITRATE;

        if ($isAcceptableMp3) {
            return [
                'path'      => $file,
                'duration'  => $info['duration'],
                'channels'  => $channels,
                'isMp3'     => true,
                'temporary' => false,
                'size'      => $size,
            ];
        }

        $outPath = $this->reEncodeToMp3($file, $uniqueId, $channels);
        return [
            'path'      => $outPath,
            'duration'  => $info['duration'],
            'channels'  => $channels,
            'isMp3'     => true,
            'temporary' => true,
            'size'      => (int)@filesize($outPath),
        ];
    }

    /**
     * Удаляет временный файл, созданный prepare() (no-op если не temporary).
     */
    public function cleanup(array $prepared): void
    {
        if (!empty($prepared['temporary']) && !empty($prepared['path']) && is_file($prepared['path'])) {
            @unlink($prepared['path']);
        }
    }

    /**
     * Перекодирует исходник в MP3 32 kbps, сохраняя число каналов.
     * Приоритет — ffmpeg, fallback — sox|lame.
     * Атомарная запись через .tmp + rename.
     */
    private function reEncodeToMp3(string $src, string $uniqueId, int $channels): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9._-]/', '_', $uniqueId) ?: ('audio_' . md5($src));
        $finalPath = $this->tmpDir . DIRECTORY_SEPARATOR . $safeId . '.mp3';
        $tmpPath   = $finalPath . '.tmp.mp3';

        $ffmpeg = Util::which('ffmpeg');
        if (!empty($ffmpeg)) {
            $cmd = sprintf(
                '%s -y -loglevel error -i %s -ar 8000 -ac %d -codec:a libmp3lame -b:a %s %s',
                $ffmpeg,
                escapeshellarg($src),
                $channels,
                self::TARGET_BITRATE,
                escapeshellarg($tmpPath)
            );
            Processes::mwExec($cmd, $out, $code);
            if ($code === 0 && is_file($tmpPath) && filesize($tmpPath) > 0) {
                @rename($tmpPath, $finalPath);
                return $finalPath;
            }
            @unlink($tmpPath);
        }

        // Fallback: sox + lame (legacy-стенды без ffmpeg).
        $sox  = Util::which('sox');
        $lame = Util::which('lame');
        if (empty($sox) || empty($lame)) {
            throw new SkipAudioException('No suitable audio transcoder (ffmpeg/sox+lame) found');
        }
        $wavPath  = $this->tmpDir . DIRECTORY_SEPARATOR . $safeId . '.tmp.wav';
        $modeFlag = $channels >= 2 ? 'j' : 'm'; // joint stereo / mono
        $cmd1 = sprintf(
            '%s %s -t wav -r 8000 -c %d -b 16 %s',
            $sox,
            escapeshellarg($src),
            $channels,
            escapeshellarg($wavPath)
        );
        Processes::mwExec($cmd1, $o1, $c1);
        if ($c1 !== 0 || !is_file($wavPath)) {
            @unlink($wavPath);
            throw new SkipAudioException('sox failed: exit=' . $c1);
        }
        $cmd2 = sprintf(
            '%s --quiet --cbr -b 32 -m %s %s %s',
            $lame,
            $modeFlag,
            escapeshellarg($wavPath),
            escapeshellarg($tmpPath)
        );
        Processes::mwExec($cmd2, $o2, $c2);
        @unlink($wavPath);
        if ($c2 !== 0 || !is_file($tmpPath) || filesize($tmpPath) <= 0) {
            @unlink($tmpPath);
            throw new SkipAudioException('lame failed: exit=' . $c2);
        }
        @rename($tmpPath, $finalPath);
        return $finalPath;
    }
}
