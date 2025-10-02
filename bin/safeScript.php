<?php

namespace Modules\ModuleSpeechRecognize\bin;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use Modules\ModuleSpeechRecognize\Lib\SpeechRecognizeConf;

require_once 'Globals.php';

$voiceConf = new SpeechRecognizeConf();
$voiceConf->installDockerImg();
$moduleEnabled = $voiceConf->checkStart();
if($moduleEnabled){
    $sr      = new SpeechRecognizeConf();
    $path    = $sr->getDaemonPath();
    $phpPath = Util::which('php');
    Processes::mwExecBg("$phpPath -f $path");
}

