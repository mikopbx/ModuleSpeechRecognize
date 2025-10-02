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
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleSpeechRecognize\Lib\Logger;
use Modules\ModuleSpeechRecognize\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSpeechRecognize\Models\GptTasks;
use Modules\ModuleSpeechRecognize\Models\ModuleSpeechRecognize;
use Modules\ModuleSpeechRecognize\Lib\SpeechRecognizeConf;
use Modules\ModuleSpeechRecognize\Models\CdrText;
use MikoPBX\Core\System\Util;
use Modules\ModuleSpeechRecognize\Models\RecognizeOperations;
use Modules\ModuleSpeechRecognize\Models\ManualTasks;
use Throwable;

class SpeechRecognizeDaemon
{
    private Logger  $logger;

    public const PID_FILE = "/var/run/speech-recognize.pid";
    private const LIMIT   = 200;
    private int $offset;
    private SpeechRecognizeConf $sr;

    private $sleepGptTime = 0;

    public function __construct()
    {
        $this->logger =  new Logger('SpeechRecognizeDaemon', 'SpeechRecognize');
        $this->logger->writeInfo('Starting...');

        $this->sr = new SpeechRecognizeConf();
        $this->sr->getSettings();
        $this->offset = $this->sr->getOffset();
    }

    public static function processExists():bool
    {
        $result = false;
        if(file_exists(self::PID_FILE)){
            $psPath      = Util::which('ps');
            $busyboxPath = Util::which('busybox');
            $pid     = file_get_contents(self::PID_FILE);
            $output  = shell_exec("$psPath -A -o pid | $busyboxPath grep $pid ");
            if(!empty($output)){
                $result = true;
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
        if(empty($this->sr->getPidContainer())){
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
            $ids = [];
            $tasks = ManualTasks::find(['closeTime=0', 'limit' => round(self::LIMIT/5)]);
            foreach($tasks as $task){
                $ids[] = $task->linkedId;
                $task->closeTime = time();
                $task->save();
            }
            if(!empty($ids)){
                $filter = [
                    'linkedid IN ({linkedid:array}) AND recordingfile<>""',
                    'bind'                => ['linkedid' => $ids],
                    'order'               => 'id',
                    'miko_result_in_file' => true,
                ];
                $this->logger->writeInfo('Starting manual tasks...' . implode( ' ', $ids));
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
        $filter = [
            'closeTime=0',
            'order' => 'changeTime ASC',
            'limit' => 100
        ];
        $tasks = GptTasks::find($filter);
        foreach($tasks as $task){
            $this->logger->writeInfo('Starting manual tasks GPT...' . $task->linkedId);
            $dataCdr = CdrText::find(["linkedId=:linkedId:", 'bind' => ['linkedId' => $task->linkedId] ])->toArray();
            if(intval($task->waitRecognize) === 1){
                $waitRecognize = count($dataCdr) === 0;
                if($waitRecognize){
                    $this->logger->writeInfo('Waiting recognize...' . $task->linkedId);
                    continue;
                }
            }
            if(empty($task->requestId)){
                $this->logger->writeInfo('Send job to GPT ...' . $task->linkedId);
                $job = json_decode($task->instruction, true);
                foreach ($dataCdr as $d) {
                    $textData = json_decode($d['text'], true);
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
                $this->logger->writeInfo("Get status $statusCode..." . $task->linkedId);
                $task->waitRecognize= false;
                $task->requestId    = $requestId;
                $task->changeTime   = time();
                $resultSave = $task->save();
                $this->logger->writeInfo("Result update db data $resultSave..." . $task->linkedId);
            }else{
                $this->logger->writeInfo("Get result GPT..." . $task->linkedId);
                try {
                    [$statusCode, $body] = ApiController::getGptTaskResult($task->requestId);
                }catch (Throwable $e){
                    $this->logger->writeError('Throwable get result job to GPT ...'.$e->getMessage().', ' . $task->linkedId);
                    continue;
                }
                if($statusCode === 200){
                    $this->logger->writeInfo($task->linkedId.' '.$body);
                    $bodyData = json_decode($body, true);
                    $done = $bodyData['result']['done']??'';
                    if($done !== true){
                        $this->logger->writeInfo('Task not done... waiting...' . $task->linkedId);
                        continue;
                    }
                    $body     = trim(str_replace('```','',$bodyData['result']['text']??''));
                    unset($bodyData);
                    $this->logger->writeInfo("Resilt ".str_replace("\n",'',$body)."..." . $task->linkedId);
                    $task->changeTime   = time();
                    $task->closeTime   = time();
                    $task->response = $body;
                    $resultSave = $task->save();
                    $this->logger->writeInfo("Result update db data $resultSave..." . $task->linkedId);
                }else{
                    $this->logger->writeError('Error get result job to GPT ... code:'.$statusCode.", " . $task->linkedId);
                }
            }
        }

    }

    /**
     * Получение результатов распознавания.
     */
    public function getRecognizeResponses():void
    {
        if(!$this->sr->useLongRecognize()){
            return;
        }
        $timestamp = time()-4;
        $filter = [
            'time<:time:','bind' => [
                'time'  => $timestamp
            ],
            'order' => 'id DESC',
            'limit' => '50'
        ];
        /** @var RecognizeOperations $task */
        $operations = RecognizeOperations::find($filter);
        foreach ($operations as $task){
            $response = $this->sr->getLongRecognizeResponse($task->operation, $task->filename);
            if(empty($response)){
                $task->fail = 1;
            }else{
                if($this->saveResponse($response, $task->toArray())){
                    continue;
                }
                $task->delete();
            }
            usleep(50000);
        }
    }

    /**
     * Сохранение результата распознавания.
     * @param $response
     * @param $uid
     * @param $linkedId
     * @return bool
     */
    private function saveResponse($response, $dataCdr):bool
    {
        $result = false;
        $data = $this->getCdrTextByUid($dataCdr['UNIQUEID']);
        if(!$data){
            $data = new CdrText();
            try {
                $data->text = json_encode($response, JSON_THROW_ON_ERROR);
                $data->linkedId = $dataCdr['linkedid'];
                foreach ($data as $key => $value) {
                    if($key === 'id'){
                        continue;
                    }
                    if(isset($dataCdr[$key])){
                        $data->$key = $dataCdr[$key];
                    }
                }
            }catch (Throwable $e){
                SystemMessages::sysLogMsg(__CLASS__, $e->getMessage());
            }
            $result = $data->save();
        }
        return $result;
    }

    /**
     * Возвращает сохраненный результат распознванаия.
     * @param string $uid
     * @return ?CdrText
     */
    private function getCdrTextByUid(string $uid):?CdrText
    {
        $filter = [
            'UNIQUEID=:uid:',
            'bind' => [
                'uid'  => $uid
            ]
        ];
        /** @var CdrText $data */
        return CdrText::findFirst($filter);
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
                $this->logger->writeError('File was transcribe...' .$data['linkedid'].':'. $data['recordingfile']);
                continue;
            }
            if($this->sr->useLongRecognize()){
                $this->sr->sendToRecognize($data);
            }else{
                $response = $this->sr->recognize($data['recordingfile']);
                $this->logger->writeInfo('Transcribe...' .$data['linkedid'].':'. $data['recordingfile'] . json_encode($response, JSON_THROW_ON_ERROR));
                $result   = $this->saveResponse($response, $data);
                if(!$result){
                    $filter = [
                        'UNIQUEID=:UNIQUEID:',
                        'bind' => [
                            'UNIQUEID'  => $data['UNIQUEID']
                        ]
                    ];
                    /** @var RecognizeOperations $operations */
                    $operations = RecognizeOperations::findFirst($filter);
                    if(!$operations){
                        $operations = new RecognizeOperations();
                        $operations->UNIQUEID = $data['UNIQUEID'];
                        $operations->linkedId = $data['linkedid'];
                    }
                    $operations->fail = 1;
                    $operations->save();
                }
            }
            usleep(100000);
        }

        if($updateOffsetInDB){
            $this->updateOffsetInDB($newOffset);
        }
    }

    private function updateOffsetInDB($newOffset):void
    {
        $this->offset = $newOffset;
        /** @var ModuleSpeechRecognize $settings */
        $settings = ModuleSpeechRecognize::findFirst();
        $settings->cdr_offset = $this->offset;
        $settings->save();
    }

}
if(SpeechRecognizeDaemon::processExists()){
    exit(0);
}

cli_set_process_title(SpeechRecognizeConf::DAEMON_TITLE);
$srd = new SpeechRecognizeDaemon();
while (true){
    $srd->startRecognize();
    $srd->getRecognizeResponses();
    $srd->startGetGptResponse();
    sleep(5);
}