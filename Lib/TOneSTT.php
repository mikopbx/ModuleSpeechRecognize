<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2025 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleSpeechRecognize\Lib;

use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;

class TOneSTT
{
    private string $mp3Path;
    private string $wsUrl;
    public function __construct($mp3Path, $wsUrl)
    {
        $this->mp3Path = $mp3Path;
        $this->wsUrl = $wsUrl;
    }

    public function recognize():PBXApiResult
    {
        $response = new PBXApiResult();


        return new PBXApiResult();
    }

    private function recognizeChannel(int $ch):int
    {
        $lamePath = Util::which('lame');
        $soxPath  = Util::which('sox');
        $cmd = sprintf("$lamePath --silent --decode %s - | $soxPath -q -t wav - -t s16 -L -r 8000 -c 1 - remix $ch", escapeshellarg($this->mp3Path));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout (raw PCM)
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($proc)) {
            fwrite(STDERR, "Failed to start decoder pipeline for $this->mp3Path\n");
            return 1;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], false);

        return 0;
    }

}