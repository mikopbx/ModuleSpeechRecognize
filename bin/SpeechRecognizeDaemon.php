<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2021 Alexey Portnov and Nikolay Beketov
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

namespace Modules\ModuleSpeechRecognize\bin;
require_once('Globals.php');

use MikoPBX\Common\Providers\CDRDatabaseProvider;
use Modules\ModuleSpeechRecognize\Lib\Logger;
use Modules\ModuleSpeechRecognize\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSpeechRecognize\Lib\SpeechRecognizeConf;
use Throwable;

class SpeechRecognizeDaemon
{
    private Logger  $logger;

    public const PID_FILE = "/var/run/speech-recognize.pid";
    private const LIMIT   = 200;
    private const VERBOSE_LOG = false;
    private int $offset;
    private SpeechRecognizeConf $sr;

    private $sleepGptTime = 0;

    public function __construct()
    {
        $this->logger =  new Logger('RecognizeDaemon', 'ModuleSpeechRecognize');
        $this->logger->writeInfo('Starting...');

        $this->sr = new SpeechRecognizeConf();
        $this->sr->getSettings();
        $this->offset = $this->sr->getOffset();
    }

    private function logVerbose(string $message): void
    {
        if (self::VERBOSE_LOG) {
            $this->logger->writeInfo($message);
        }
    }

    public static function processExists():bool
    {
        $result = false;
        if(file_exists(self::PID_FILE)){
            $pid = trim((string)file_get_contents(self::PID_FILE));
            if (ctype_digit($pid)) {
                $pidInt = (int)$pid;
                // Exact process check to avoid false positives from grep-based matching.
                if ($pidInt > 1 && function_exists('posix_kill')) {
                    $result = @posix_kill($pidInt, 0);
                } elseif ($pidInt > 1) {
                    $result = file_exists('/proc/' . $pidInt);
                }
            }
        }
        if(!$result){
            file_put_contents(self::PID_FILE, getmypid());
        }
        return $result;
    }

    /**
     * Старт процесса распознавания.
     * @return void
     */
    public function startRecognize():void
    {
        $this->logger->rotate();
        if(!$this->sr->settingsExists()){
            $this->logger->writeError('Settings not found...');
            return;
        }
        // Docker-контейнер требуется только Tinkoff-провайдеру.
        if(!$this->sr->isMikoProvider() && empty($this->sr->getPidContainer())){
            $this->logger->writeError('Docker container not started...');
            return;
        }
        $filter = [];
        if($this->sr->recognizeAllEnable()){
            $filter = [
                'id>:id: AND recordingfile<>""',
                'bind'                => ['id' => $this->offset],
                'order'               => 'id',
                'limit'               => self::LIMIT,
                'miko_result_in_file' => true,
            ];
            $updateOffsetInDB = true;
        }else{
            // Выборочное распознавание.
            $ids = ConnectorDb::invoke(ConnectorDb::FUNC_GET_MANUAL_ID, []);
            if(is_array($ids) && !empty($ids)){
                $filter = [
                    'linkedid IN ({linkedid:array}) AND recordingfile<>""',
                    'bind'                => ['linkedid' => $ids],
                    'order'               => 'id',
                    'miko_result_in_file' => true,
                ];
                $this->logVerbose('Starting manual tasks...' . implode( ' ', $ids));
            }
            $updateOffsetInDB = false;
        }
        if(!empty($filter)){
            $rows = CDRDatabaseProvider::getCdr($filter);
            $this->processData($rows, $updateOffsetInDB);
        }
    }

    public function startGetGptResponse():void
    {
        if(time() - $this->sleepGptTime < 0){
            // Блокировка частых запросов.
            return;
        }
        $tasks = ConnectorDb::invoke(ConnectorDb::FUNC_GPT_OPEN_TASKS_WAITING, []);
        $this->startGetGptResponsePart2($tasks);
    }

    private function startGetGptResponsePart2($tasks)
    {
        foreach($tasks as $srcTask){
            $task = (object) $srcTask;

            $this->logVerbose('Starting manual tasks GPT...' . $task->linkedId);
            $dataCdr = ConnectorDb::invoke(ConnectorDb::FUNC_GET_TEXT_BY_ID, [$task->linkedId]);
            if(intval($task->waitRecognize) === 1){
                $waitRecognize = count($dataCdr) === 0;
                if($waitRecognize){
                    $this->logVerbose('Waiting recognize...' . $task->linkedId);
                    continue;
                }
            }
            if(empty($task->requestId)){
                $this->logVerbose('Send job to GPT ...' . $task->linkedId);
                $job = json_decode($task->instruction, true);
                if (!is_array($job)) {
                    $this->logger->writeError('Skip task: invalid instruction JSON, ' . $task->linkedId);
                    continue;
                }
                if (empty($dataCdr) || !is_array($dataCdr)) {
                    if ((int)($task->waitRecognize ?? 0) !== 1) {
                        $task->waitRecognize = 1;
                        $task->changeTime = time();
                        ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_GPT_TASK, [(array)$task]);
                    }
                    $this->logVerbose('Skip send: no transcripts, ' . $task->linkedId);
                    continue;
                }
                foreach ($dataCdr as $d) {
                    $textData = json_decode($d['text'], true);
                    if (!is_array($textData)) {
                        continue;
                    }
                    foreach ($textData as $text) {
                        $ch = $text['channel']??'';
                        $job['query'].="О.$ch: ".$text['text'].PHP_EOL;
                    }
                }
                try {
                    [,$requestId,$statusCode] = ApiController::sendGptTask($task->linkedId, $job);
                }catch (Throwable $e){
                    if($e->getCode() === 429){
                        $this->sleepGptTime = time()+120;
                        $this->logger->writeError('Error send job to GPT (sleep 2 minute)...'.$e->getMessage().', ' . $task->linkedId);
                    }else{
                        $this->logger->writeError('Error send job to GPT ...'.$e->getMessage().', ' . $task->linkedId);
                    }
                    continue;
                }
                $this->logVerbose("Get status $statusCode..." . $task->linkedId);
                if ($statusCode !== 200 || empty($requestId)) {
                    $this->logVerbose("Skip update task after send, status=$statusCode, " . $task->linkedId);
                    continue;
                }
                $task->waitRecognize = false;
                $task->requestId     = $requestId;
                $task->changeTime    = time();

                $resultSave = ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_GPT_TASK, [(array)$task]);
                $resultSave = empty($resultSave)?false:$resultSave[0];

                $this->logVerbose("Result update db data $resultSave..." . $task->linkedId);
            }else{
                $this->logVerbose("Get result GPT..." . $task->linkedId);
                try {
                    [$statusCode, $body] = ApiController::getGptTaskResult($task->requestId);
                }catch (Throwable $e){
                    $this->logger->writeError('Throwable get result job to GPT ...'.$e->getMessage().', ' . $task->linkedId);
                    continue;
                }
                if($statusCode === 200){
                    $bodyData = json_decode($body, true);
                    $done = $bodyData['result']['done']??'';
                    if($done !== true){
                        $this->logVerbose('Task not done... waiting...' . $task->linkedId);
                        continue;
                    }
                    $body     = trim(str_replace('```','',$bodyData['result']['text']??''));
                    unset($bodyData);
                    $this->logVerbose('Result saved for task...' . $task->linkedId);
                    $task->changeTime  = time();
                    $task->closeTime   = time();
                    $task->response = $body;

                    $resultSave = ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_GPT_TASK, [(array)$task]);
                    $resultSave = empty($resultSave)?false:$resultSave[0];

                    $this->logVerbose("Result update db data $resultSave..." . $task->linkedId);
                }elseif ($statusCode === 0) {
                    $this->logVerbose('GPT result unavailable (status=0), ' . $task->linkedId);
                }else{
                    $this->logger->writeError('Error get result job to GPT ... code:'.$statusCode.", " . $task->linkedId);
                }
            }
        }

    }

    /**
     * Получение результатов распознавания.
     * Tinkoff long-режим — для записей без provider/с provider='tinkoff'.
     * MIKO — всегда async, опрашивается отдельной веткой.
     */
    public function getRecognizeResponses():void
    {
        $isMiko = $this->sr->isMikoProvider();
        if (!$isMiko && !$this->sr->useLongRecognize()) {
            return;
        }
        $operations = ConnectorDb::invoke(ConnectorDb::FUNC_GET_RECOGNIZE_OPERATIONS, []);
        $now = time();
        foreach ($operations as $srcTask) {
            $task = (object)$srcTask;
            $taskProvider = strtolower((string)($task->provider ?? ''));
            if ($taskProvider === '') {
                // Старые записи без provider — это Tinkoff long.
                $taskProvider = SpeechRecognizeConf::PROVIDER_TINKOFF;
            }
            // Откладываем задачи, для которых задана пауза между попытками.
            if (!empty($task->nextRetryAt) && (int)$task->nextRetryAt > $now) {
                continue;
            }

            if ($taskProvider === SpeechRecognizeConf::PROVIDER_MIKO) {
                $this->processMikoOperation($task, $now);
            } else {
                $response = $this->sr->getLongRecognizeResponse($task->operation, $task->filename);
                if (empty($response)) {
                    $task->fail = 1;
                } else {
                    if ($this->saveResponse($response, (array)$task, $task->linkedId)) {
                        continue;
                    }
                    ConnectorDb::invoke(ConnectorDb::FUNC_DEL_RECOGNIZE_OPERATIONS, [$task->id]);
                }
                usleep(50000);
            }
        }
    }

    private const MIKO_MAX_ATTEMPTS = 3;
    private const MIKO_TASK_TIMEOUT_SEC = 7200;       // 2 часа для general
    private const MIKO_DEFERRED_TIMEOUT_SEC = 86400;  // 24 часа для deferred-general

    /**
     * Опрос одной MIKO-задачи: обработать done/in-progress/ошибки, обновить attempts/timeouts.
     */
    private function processMikoOperation(\stdClass $task, int $now): void
    {
        $submittedAt = (int)($task->submittedAt ?? $task->time ?? 0);
        $deadline = $this->sr->isMikoUseDeferredGeneral()
            ? self::MIKO_DEFERRED_TIMEOUT_SEC
            : self::MIKO_TASK_TIMEOUT_SEC;
        if ($submittedAt > 0 && ($now - $submittedAt) > $deadline) {
            $payload = (array)$task;
            $payload['fail'] = 1;
            ConnectorDb::invoke(ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS, [$payload], false);
            return;
        }
        try {
            $response = $this->sr->getMikoRecognizeResponse((string)$task->operation, (string)$task->filename);
        } catch (Throwable $e) {
            $code = (int)$e->getCode();
            // 401/403 — битый/отозванный лицензионный ключ. Дальнейшие ретраи бесполезны.
            if ($code === 401 || $code === 403) {
                $payload = (array)$task;
                $payload['fail'] = 1;
                ConnectorDb::invoke(ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS, [$payload], false);
                $this->logger->writeError('MIKO fetch fatal (' . $code . '): ' . $e->getMessage() . ', ' . ($task->linkedId ?? ''));
                return;
            }
            // 5xx / network — экспоненциальный backoff до MIKO_MAX_ATTEMPTS.
            $this->scheduleMikoRetry($task, $now, $e->getMessage());
            return;
        }
        if (empty($response)) {
            // done=false — просто ждём, без увеличения attempts.
            return;
        }
        if ($this->saveResponse($response, (array)$task, $task->linkedId ?? '')) {
            return;
        }
        ConnectorDb::invoke(ConnectorDb::FUNC_DEL_RECOGNIZE_OPERATIONS, [$task->id]);
    }

    private function scheduleMikoRetry(\stdClass $task, int $now, string $reason): void
    {
        $attempts = (int)($task->attempts ?? 0) + 1;
        $payload = (array)$task;
        $payload['attempts'] = $attempts;
        if ($attempts >= self::MIKO_MAX_ATTEMPTS) {
            $payload['fail'] = 1;
            $this->logger->writeError('MIKO fetch attempts exceeded: ' . $reason . ', ' . ($task->linkedId ?? ''));
        } else {
            $delay = min(60 * (2 ** ($attempts - 1)), 600);
            $payload['nextRetryAt'] = $now + $delay;
            $this->logVerbose('MIKO fetch retry in ' . $delay . 's (attempt ' . $attempts . '): ' . $reason);
        }
        ConnectorDb::invoke(ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS, [$payload], false);
    }

    /**
     * Сохранение результата распознавания.
     * @param $response
     * @param $uid
     * @param $linkedId
     * @return bool
     */
    private function saveResponse($response, $dataCdr, $linkedid):bool
    {
        $result = false;
        $data = $this->getCdrTextByUid($dataCdr['UNIQUEID']);
        if(!$data){
            $dataCdr['text']     = json_encode($response, JSON_THROW_ON_ERROR);
            $dataCdr['linkedId'] = $linkedid;
            $res = ConnectorDb::invoke(ConnectorDb::FUNC_UPD_CDR_TEXT, [$dataCdr]);
            $result = empty($res)?false:$res[0];
        }
        return $result;
    }

    /**
     * Возвращает сохраненный результат распознванаия.
     * @param string $uid
     * @return ?object
     */
    private function getCdrTextByUid(string $uid)
    {
        $res = ConnectorDb::invoke(ConnectorDb::FUNC_GET_TEXT_BY_UNIQUEID, [$uid]);
        if(empty($res) || !is_array($res)){
            return null;
        }
        return (object)$res;
    }

    /**
     * @param $result_data
     * @param bool $updateOffsetInDB
     */
    private function processData($result_data, bool $updateOffsetInDB = true):void
    {
        $newOffset = $this->offset;
        foreach ($result_data as $data){
            $newOffset = $data['id'];
            if(!file_exists($data['recordingfile'])){
                $this->logger->writeError('File not found...' .$data['linkedid'].':'. $data['recordingfile']);
                continue;
            }
            if($this->getCdrTextByUid($data['UNIQUEID'])){
                $this->logVerbose('File was transcribe...' .$data['linkedid'].':'. $data['recordingfile']);
                continue;
            }
            $useAsync = $this->resolveAsyncMode($data);
            if($useAsync){
                $this->sr->sendToRecognize($data);
            }else{
                $response = $this->sr->recognize($data['recordingfile']);
                $this->logVerbose('Transcribe completed...' .$data['linkedid'].':'. $data['recordingfile']);
                $result   = $this->saveResponse($response, $data, $data['linkedid']);
                if(!$result){
                    ConnectorDb::invoke(ConnectorDb::FUNC_UPD_RECOGNIZE_OPERATIONS, [$data], false);
                }
            }
            usleep(100000);
        }

        if($updateOffsetInDB){
            $this->updateOffsetInDB($newOffset);
        }
    }

    /**
     * Для MIKO решение sync/async — по длительности файла (probe без перекодирования).
     * Для Tinkoff — по флагу useLongRecognize (старое поведение).
     */
    private function resolveAsyncMode(array $data): bool
    {
        if (!$this->sr->isMikoProvider()) {
            return $this->sr->useLongRecognize();
        }
        try {
            $duration = $this->sr->probeDuration($data['recordingfile']);
        } catch (Throwable $e) {
            // Если probe вообще упал — пускаем в async, sendToRecognizeMiko
            // повторно вызовет prepare() и при ошибке пометит запись как fail.
            $this->logger->writeError('MIKO probe failed: ' . $e->getMessage() . ', ' . ($data['linkedid'] ?? ''));
            return true;
        }
        if ($duration <= 0.0) {
            // Не удалось определить длительность — безопаснее в async.
            return true;
        }
        return $duration > $this->sr->getSyncMaxSeconds();
    }

    private function updateOffsetInDB($newOffset):void
    {
        $this->offset = $newOffset;
        ConnectorDb::invoke(ConnectorDb::FUNC_UPDATE_SETTINGS, [['cdr_offset' => $this->offset]], false);
    }

}
$cliDebug = php_sapi_name() === 'cli' && isset($argv) && in_array('start', $argv, true);
$debugOut = static function (string $message) use ($cliDebug): void {
    if (!$cliDebug) {
        return;
    }
    echo '[' . date('c') . '] ' . $message . PHP_EOL;
};

$debugOut('Boot SpeechRecognizeDaemon');
if(SpeechRecognizeDaemon::processExists()){
    $debugOut('Daemon already running. Exit.');
    exit(0);
}

cli_set_process_title(SpeechRecognizeConf::DAEMON_TITLE);
$debugOut('Process title set: ' . SpeechRecognizeConf::DAEMON_TITLE);
$srd = new SpeechRecognizeDaemon();
$debugOut('Daemon initialized. Enter loop.');
while (true){
    try {
        $srd->startRecognize();
        $srd->getRecognizeResponses();
        $srd->startGetGptResponse();
    } catch (Throwable $e) {
        $debugOut('Loop error: ' . $e->getMessage());
    }
    sleep(5);
}