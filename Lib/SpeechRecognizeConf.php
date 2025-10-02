<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 12 2019
 */

namespace Modules\ModuleSpeechRecognize\Lib;

use MikoPBX\Common\Providers\CDRDatabaseProvider;
use MikoPBX\Core\System\Configs\CronConf;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleSpeechRecognize\Lib\RestAPI\Controllers\ApiController;
use Modules\ModuleSpeechRecognize\Models\CdrText;
use Modules\ModuleSpeechRecognize\Models\GptTasks;
use Modules\ModuleSpeechRecognize\Models\ModuleSpeechRecognize;
use Modules\ModuleSpeechRecognize\Models\RecognizeOperations;
use Throwable;

class SpeechRecognizeConf extends ConfigClass
{
    public const DAEMON_TITLE       = 'SpeechRecognizeDaemon';
    public const CONTAINER_NAME     = 'tinkoff-tts';
    public const CONTAINER_IMG_NAME = 'tinkoff:tts-v1';
    private string $apiKey='';
    private string $secretKey='';
    private int $cdrOffset;
    private bool $useLongRecognize = false;
    private bool $recognizeAll = false;


    /**
     * Необходимо ли использовать отложенное распознавание.
     * @return bool
     */
    public function useLongRecognize():bool
    {
        return $this->useLongRecognize;
    }

    /**
     * Returns array of additional routes for PBXCoreREST interface from module
     *
     * [ControllerClass, ActionMethod, RequestTemplate, HttpMethod, RootUrl, NoAuth ]
     *
     * @return array
     * @example
     *  [[GetController::class, 'callAction', '/pbxcore/api/backup/{actionName}', 'get', '/', false],
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [ApiController::class, 'addManualTasks', '/pbxcore/api/speech-recognize/add-manual-task',  'get', '/', true],
            [ApiController::class, 'getCdrData',     '/pbxcore/api/speech-recognize/get-cdr-data',  'get', '/', true],
            [ApiController::class, 'addGptTask',     '/pbxcore/api/speech-recognize/add-gpt-task',  'post', '/', true],
            [ApiController::class, 'getGptResults',  '/pbxcore/api/speech-recognize/get-gpt-results',  'get', '/', true],
        ];
    }

    /**
     * Получение настроек;
     */
    public function getSettings(): void
    {
        $settings = ModuleSpeechRecognize::findFirst();
        if ($settings === null) {
            return;
        }
        $this->apiKey = $settings->apiKey;
        $this->secretKey = $settings->secretKey;
        $this->cdrOffset = intval($settings->cdr_offset);
        $this->useLongRecognize = intval($settings->useLongRecognize) === 1;
        $this->recognizeAll = intval($settings->recognizeAll) === 1;
        if(empty($this->cdrOffset)){
            $this->cdrOffset = 1;
        }
    }

    /**
     * Возвращает номер строки истории звонков.
     * @return int
     */
    public function getOffset():int
    {
        return $this->cdrOffset;
    }

    public function getDaemonPath():string
    {
        return $this->moduleDir.'/bin/SpeechRecognizeDaemon.php';
    }

    /**
     * Проверка, разрешено ли распознавание всех разговоров
     * @return bool
     */
    public function recognizeAllEnable():bool
    {
        return $this->recognizeAll;
    }

    /**
     * Проверка, заполнены ли настройки.
     * @return bool
     */
    public function settingsExists():bool
    {
        return !empty($this->apiKey) && !empty($this->secretKey);
    }

    /**
     *  Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->processor = __METHOD__;
        $action = "{$request['action']}Action";
        $data   = $request['data']??[];
        if(method_exists($this, $action)){
            $res = $this->$action($data);
        }else{
            $res->success = false;
            $res->data = $request;
        }
        return $res;
    }

    public function checkAction():PBXApiResult
    {
        $res    = new PBXApiResult();
        $res->data[] = 'ddd';
        return $res;
    }

    public function getCdrDataAction($data):PBXApiResult
    {
        $res    = new PBXApiResult();
        if(isset($data['offset'])){
            $res->data = $this->getCdrDataByOffset($data);
        }elseif(isset($data['link-id'])){
            $res->data = $this->getCdrDataByLinkId($data['link-id']);
        }else{
            $res->data[] = $data;
        }

        $res->success = true;
        return $res;
    }

    public function getCdrDataByLinkId($linkedId):array
    {
        $rowsData = [];
        $filter = [
            'linkedId = :linkedId:',
            'bind'                => [
                'linkedId' => $linkedId
            ],
            'order'               => 'id',
        ];
        $rows = CdrText::find($filter)->toArray();
        foreach ($rows as $row){
            if(!isset($rowsData[$row['UNIQUEID']])){
                continue;
            }
            $rowsData[$row['UNIQUEID']]['transcript'] = $row['text']??'';
        }
        return array_values($rowsData);
    }

    /**
     * @param $data
     * @return array
     */
    public function getCdrDataByOffset($data):array
    {
        $filter = [
            'id>:id:','bind' => [
                'id'  => $data['offset']??1
            ],
            'limit' => $data['limit']??30
        ];
        $result = CdrText::find($filter)->toArray();
        foreach ($result as &$row){
            $row['transcript'] = $row['text']??'';
            unset($row['text']);
        }
        return $result;
    }

    /**
     * Возвращает путь к исполняемому файлу docker.
     * @return string
     */
    private function getDockerPath():string
    {
        return dirname($this->moduleDir) .DIRECTORY_SEPARATOR.'ModuleDocker'. DIRECTORY_SEPARATOR . 'bin'.DIRECTORY_SEPARATOR.'docker';
    }

    /**
     * Возваращает путь к образу контейнера.
     * @return string
     */
    private function getDockerFile():string
    {
        return "$this->moduleDir/bin/docker/Dockerfile";
    }

    /**
     * Возвращает идентификатор контейнера Docker.
     * @return string
     */
    public function getPidContainer():string{
        $dockerPath  = $this->getDockerPath();
        $grep        = Util::which('grep');
        $busybox     = Util::which('busybox');
        Processes::mwExec("$dockerPath ps | $busybox $grep ".self::CONTAINER_IMG_NAME." |{$busybox} awk  '{ print $1}'", $out);
        return implode('', $out);
    }

    /**
     * Установка образа контейнера
     */
    public function installDockerImg():void
    {
        $dockerPath  = $this->getDockerPath();
        $imgPath     = $this->getDockerFile();

        $pid = Processes::getPidOfProcess('docker build');
        if(!empty($pid)){
            // Уже запущена сборка.
            return;
        }
        if(!$this->imgExists()){
            // Нужно запустить сборку.
            Processes::mwExecBg("$dockerPath build --tag ".self::CONTAINER_IMG_NAME." - < $imgPath");
        }
    }

    /**
     * Проверка существует ли образ docker.
     * @return bool
     */
    public function imgExists():bool
    {
        $busybox     = Util::which('busybox');
        $dockerPath  = $this->getDockerPath();
        $awcCmd      = '{ print $1 ":" $2 ":" $3}';
        $result      = Processes::mwExec("$dockerPath images | $busybox awk '$awcCmd' | $busybox grep '".self::CONTAINER_IMG_NAME."'");
        return ($result !== 1);
    }

    /**
     * Process after enable action in web interface
     *
     * @return void
     */
    public function onAfterModuleEnable(): void
    {
        if(!$this->imgExists()){
            return;
        }
        if(!empty($this->getPidContainer())){
            return;
        }
        $cron = new CronConf();
        $cron->reStart();
        $dockerPath  = $this->getDockerPath();
        $params = [
            "$dockerPath run -t -d --rm --name ".self::CONTAINER_NAME,
            "-v /storage/usbdisk1/mikopbx/astspool/monitor:/storage/usbdisk1/mikopbx/astspool/monitor",
            "-v $this->moduleDir/Lib/python/recognize.py:/voicekit/python/recognize.py",
            "-v $this->moduleDir/Lib/python/common.py:/voicekit/python/common.py",
            "-v $this->moduleDir/Lib/python/miko-recognize-long.py:/voicekit/python/miko-recognize-long.py",
            "-v $this->moduleDir/Lib/python/miko-recognize-long-get-result.py:/voicekit/python/miko-recognize-long-get-result.py",
            self::CONTAINER_IMG_NAME
        ];
        Processes::mwExecBg(implode(' ', $params));

        $path = $this->getSafeScriptPath();
        $phpPath = Util::which('php');
        Processes::mwExecBg("$phpPath -f $path");
    }

    /**
     * Process after disable action in web interface
     *
     * @return void
     */
    public function onAfterModuleDisable(): void
    {
        $dockerPath = $this->getDockerPath();
        $pid = $this->getPidContainer();
        if(!empty($pid)){
            Processes::mwExec($dockerPath.' kill '.$pid);
        }
        $killPath = Util::which('kill');
        $busyPath = Util::which('busybox');
        Processes::mwExec($killPath.' $('.$busyPath.' ps | '.$busyPath.' grep '.SpeechRecognizeConf::DAEMON_TITLE.' | grep -v grep | cut -f 1 -d " ")');
    }

    /**
     * Проверка работы docker процесса.
     */
    public function checkStart():bool{
        $moduleEnabled  = PbxExtensionUtils::isEnabled($this->moduleUniqueId);
        if($moduleEnabled === true){
            $this->onAfterModuleEnable();
        }else{
            $this->onAfterModuleDisable();
        }

        return $moduleEnabled;
    }

    public function getSttResultFileName($filename):string
    {
        return Util::trimExtensionForFile($filename).'_stt.txt';
    }

    /**
     * Отправка файла на распознавание.
     * @param $filename
     * @param $uid
     * @param $linkedId
     */
    public function sendToRecognize($dataCdr):void
    {
        $resultFile = $this->getSttResultFileName($dataCdr['recordingfile']);
        if(file_exists($resultFile)){
            return;
        }
        $countChannels = $this->getCountRecordChannels($dataCdr['recordingfile']);
        $dockerPath = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/miko-recognize-long.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-r 8000  -c $countChannels -e MPEG_AUDIO ",
            $dataCdr['recordingfile']
        ];

        $result = shell_exec(implode(' ', $params));
        $filter = [
            'UNIQUEID=:UNIQUEID:','bind' => [
                'UNIQUEID'  => $dataCdr['UNIQUEID']
            ]
        ];
        /** @var RecognizeOperations $operation */
        $operation = RecognizeOperations::findFirst($filter);
        if(!$operation){
            $operation = new RecognizeOperations();
        }
        $operation->filename  = $dataCdr['recordingfile'];
        $operation->operation = trim($result);
        $operation->linkedId  = $dataCdr['linkedid'];
        $operation->time = time();
        foreach ($operation->toArray() as $key => $value) {
            if(isset($dataCdr[$key])){
                $operation->$key = $dataCdr[$key];
            }
        }
        $operation->save();
    }

    /**
     * Получение результата распознавания.
     * @param $id
     * @param $filename
     * @return array
     */
    public function getLongRecognizeResponse($id, $filename):array
    {
        $dockerPath = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/miko-recognize-long-get-result.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-i $id"
        ];
        $resultFile = $this->getSttResultFileName($filename);
        $output = shell_exec(implode(' ', $params));
        file_put_contents($resultFile, $output);
        return $this->convertResponse($output);
    }

    /**
     * Распознавание текста в mp3 файле.
     * @param $filename
     * @return array
     */
    public function recognize($filename):array
    {
        $resultFile = $this->getSttResultFileName($filename);
        if(file_exists($resultFile)){
            return $this->convertResponse(file_get_contents($resultFile));
        }
        $countChannels  = $this->getCountRecordChannels($filename);
        $dockerPath     = $this->getDockerPath();
        $params = [
            "$dockerPath exec -t ".self::CONTAINER_NAME,
            "/usr/bin/python3 /voicekit/python/recognize.py",
            "--api_key='$this->apiKey' --secret_key='$this->secretKey'",
            "-r 8000  -c $countChannels -e MPEG_AUDIO ",
            $filename
        ];
        $output = shell_exec(implode(' ', $params));
        file_put_contents($resultFile, $output);
        return $this->convertResponse($output);
    }

    /**
     * Преобразование ответа в массив нужного формата.
     * @param $output
     * @return array
     */
    private function convertResponse($output):array
    {
        $messages = [];
        try {
            $data   = json_decode($output, true);
        }catch (Throwable $e){
            return $messages;
        }

        if(isset($data['response']['results'])){
            // Это ответ Long Request.
            $data = $data['response']['results'];
        }

        if(is_array($data)){
            foreach ($data as $row){
                $text = $row['alternatives'][0]['transcript']??'';
                if(empty($text)){
                    continue;
                }
                $messages[] = [
                    'text'     => $text,
                    'channel'  => $row['channel']??'',
                    'start'    => $row['start_time']??'',
                    'end'      => $row['end_time']??'',
                    'sentiment'=> $row['sentiment_analysis_result']??['negative_prob_audio'=>0, 'negative_prob_audio_text'=>0],
                    'gender'   => $row['gender_identification_result']??[],
                ];
            }
        }
        return $messages;
    }

    /**
     * Получение количества каналов звукового файла.
     * @param $filename
     * @return string
     */
    private function getCountRecordChannels($filename):string
    {
        $soxPath = Util::which('soxi');
        $busyboxPath = Util::which('busybox');
        $out = [];
        Processes::mwExec("$soxPath $filename | $busyboxPath grep Channels | $busyboxPath awk -F ':' '{ print $2}'", $out);

        return trim(implode('', $out));
    }

    private function getSafeScriptPath():string
    {
        return $this->moduleDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'safeScript.php';
    }

    /**
     * Добавление задач в crond.
     *
     * @param $tasks
     */
    public function createCronTasks(&$tasks): void
    {
        if ( !is_array($tasks)) {
            return;
        }
        $workerPath = $this->getSafeScriptPath();
        $phpPath = Util::which('php');
        $tasks[]      = "*/5 * * * * {$phpPath} -f {$workerPath} > /dev/null 2> /dev/null\n";
    }
}