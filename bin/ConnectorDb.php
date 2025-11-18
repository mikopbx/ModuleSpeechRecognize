<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
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
require_once 'Globals.php';

use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleSpeechRecognize\Lib\MikoPBXVersion;
use Modules\ModuleSpeechRecognize\Lib\Logger;
use Modules\ModuleSpeechRecognize\Models\CdrText;
use Modules\ModuleSpeechRecognize\Models\GptTasks;
use Modules\ModuleSpeechRecognize\Models\ManualTasks;

class ConnectorDb extends WorkerBase
{
    public const PID_FILE = "/var/run/connector-db.pid";

    private Logger  $logger;
    public const MODULE_NAME = 'ModuleSpeechRecognize';
    public const FUNC_TEST = 'test';
    public const FUNC_CDR_BY_ID = 'getCdrDataByLinkId';
    public const FUNC_CDR_BY_OFFSET = 'getCdrDataByOffset';
    public const FUNC_ADD_MANUAL_TASK = 'addManualTasks';
    public const FUNC_ADD_GPT_TASK = 'addGptTask';
    public const FUNC_GPT_RESULTS = 'getGptResults';

    /**
     * Handles the received signal.
     *
     * @param int $signal The signal to handle.
     *
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        parent::signalHandler($signal);
        cli_set_process_title('SHUTDOWN_'.cli_get_process_title());
    }

    /**
     * Старт работы листнера.
     *
     * @param $argv
     */
    public function start($argv):void
    {
        $this->logger =  new Logger('ConnectorDb', $this::MODULE_NAME);
        $this->logger->writeInfo($argv, 'Starting');
        $beanstalk      = new BeanstalkClient(self::class);
        $beanstalk->subscribe(self::class, [$this, 'onEvents']);
        $beanstalk->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);
        while ($this->needRestart === false) {
            $beanstalk->wait();
        }
    }

    /**
     * Получение запросов на идентификацию номера телефона.
     * @param $tube
     * @return void
     */
    public function onEvents($tube): void
    {
        try {
            $pathToData = $tube->getBody();
            if(file_exists($pathToData)) {
                $data = json_decode(file_get_contents($pathToData), true, 512, JSON_THROW_ON_ERROR);
                unlink($pathToData);
            }else{
                $data = json_decode($pathToData, true, 512, JSON_THROW_ON_ERROR);
            }
        }catch (Exception $e){
            return;
        }
        $res_data = [];
        if($data['action'] === 'invoke'){
            $this->logger->writeInfo($data, 'Get command');

            $funcName = $data['function']??'';
            if(method_exists($this, $funcName)){
                if(count($data['args']) === 0){
                    $res_data = $this->$funcName();
                }else{
                    $res_data = $this->$funcName(...$data['args']??[]);
                }
                $res_data = self::saveInTmpFile($res_data);
            }
        }
        $tube->reply($res_data);
    }

    public function test()
    {
        return ['result' => true];
    }

    /**
     * Сериализует данные и сохраняет их во временный файл.
     * @param array $data
     * @return string
     */
    public static function saveInTmpFile(array $data):string
    {
        try {
            $res_data = json_encode($data, JSON_THROW_ON_ERROR);
        }catch (\JsonException $e){
            return '';
        }
        $downloadCacheDir = '/tmp/';
        $tmpDir           = '/tmp/';
        $di = MikoPBXVersion::getDefaultDi();
        if ($di) {
            $dirsConfig = $di->getShared('config');
            $tmoDirName = $dirsConfig->path('core.tempDir') . '/'.self::MODULE_NAME;
            Util::mwMkdir($tmoDirName);
            chown($tmoDirName, 'www');
            if (file_exists($tmoDirName)) {
                $tmpDir = $tmoDirName;
            }

            $downloadCacheDir = $dirsConfig->path('www.downloadCacheDir');
            if (!file_exists($downloadCacheDir)) {
                $downloadCacheDir = '';
            }
        }
        $fileBaseName = md5(microtime(true));
        // "temp-" in the filename is necessary for the file to be automatically deleted after 5 minutes.
        $filename = $tmpDir . '/temp-' . $fileBaseName;
        file_put_contents($filename, $res_data);
        if (!empty($downloadCacheDir)) {
            $linkName = $downloadCacheDir . '/' . $fileBaseName;
            // For automatic file deletion.
            // A file with such a symlink will be deleted after 5 minutes by cron.
            Util::createUpdateSymlink($filename, $linkName, true);
        }
        chown($filename, 'www');
        return $filename;
    }

    /**
     * Выполнение меодов worker, запущенного в другом процессе.
     * @param string $function
     * @param array $args
     * @param bool $retVal
     * @return array|bool|mixed
     */
    public static function invoke(string $function, array $args = [], bool $retVal = true){
        $req = [
            'action'   => 'invoke',
            'function' => $function,
            'args'     => $args
        ];
        $client = new BeanstalkClient(self::class);
        try {
            if($retVal){
                $req['need-ret'] = true;
                $pathToData = self::saveInTmpFile($req);
                $result = $client->request($pathToData, 20);
            }else{
                $pathToData = self::saveInTmpFile($req);
                $client->publish($pathToData);
                return true;
            }
            if(file_exists($result)){
                $object = json_decode(file_get_contents($result), true);
            }else{
                $object = [];
            }
        } catch (\Throwable $e) {
            $object = [];
        }
        return $object;
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


    // REST API FUNCTIONS

    /**
     * Возвращает транскрибацию звонка по его ID
     * @param string $linkedId
     * @return array
     */
    public function getCdrDataByLinkId(string $linkedId):array
    {
        $rowsData = [];
        $filter = [
            'linkedId=:linkedId:',
            'bind'=> [
                'linkedId' => $linkedId
            ],
            'order' => 'id',
        ];
        $rows = CdrText::find($filter)->toArray();
        foreach ($rows as $row){
            if(isset($rowsData[$row['UNIQUEID']])){
                continue;
            }
            $rowsData[$row['UNIQUEID']]['transcript'] = $row['text']??'';
        }
        return array_values($rowsData);
    }

    /**
     * Вощвращает разпознанную речь в виде текста для CDR
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getCdrDataByOffset(int $offset = 1, int $limit = 30):array
    {
        $filter = [
            'id>:id:','bind' => [
                'id'  => $offset
            ],
            'limit' => $limit
        ];
        $result = CdrText::find($filter)->toArray();
        foreach ($result as &$row){
            $row['transcript'] = $row['text']??'';
            unset($row['text']);
        }
        return $result;
    }

    public function addManualTasks(string $linkedId)
    {
        $res    = new PBXApiResult();
        $taskData = ManualTasks::findFirst([
           'linkedId=:linkedid:',
           'bind' => [
               'linkedid' => $linkedId
           ]
       ]);
        if($taskData){
            $res->success = true;
            $res->messages[] = 'The task was already added earlier';
            return $res->getResult();
        }
        $taskData = new ManualTasks();
        $taskData->linkedId = $linkedId;
        $taskData->changeTime = time();
        $res->success = $taskData->save();
        $res->messages[] = 'The task added.';
        return $res->getResult();
    }

    public function addGptTask($data)
    {
        $waitRecognize = true;
        $linkedId = $data['id']??'';
        $res    = new PBXApiResult();
        $instruction = $data['instruction']??"Выдай сводку по телефонному звонку.";
        try {
            $job = [
                'model'       =>  $data['model']??'yandexgpt-lite',
                'temperature' =>  intval($data['temperature']??0),
                'instruction' =>  $instruction,
                'max_tokens'  =>  intval($data['max_tokens']??2000),
                'query'       => ''
            ];
        }catch (\Throwable $e){
            $res->messages[] = 'Fail create job...';
            return $res->getResult();
        }

        $requestId = '';
        $res->data['waitRecognize'] = $waitRecognize;
        $task = GptTasks::findFirst(['linkedId=:linkedId:', 'bind' => ['linkedId' => $linkedId] ]);
        if(!$task){
            $task = new GptTasks();
        }
        $task->linkedId     = $linkedId;
        $task->changeTime   = time();
        $task->waitRecognize= $waitRecognize;
        $task->requestId    = $requestId;
        $task->instruction  = json_encode($job);
        $task->closeTime    = 0;

        try {
            $res->success = $task->save();
        }catch (\Throwable $e){
            $res->messages[] = 'Fail send job (HTTP)...' . $e->getMessage();
        }
        return $res->getResult();
    }

    public function getGptResults(int $time)
    {
        $filter = [
            'changeTime>:changeTime:','bind' => [
                'changeTime'  => $time
            ],
            'columns' => 'linkedId,changeTime,waitRecognize,requestId,closeTime,response',
            'order' => 'changeTime ASC',
            'limit' => 450
        ];
        return  GptTasks::find($filter)->toArray();
    }
}

if(isset($argv) && count($argv) !== 1
    && Util::getFilePathByClassName(ConnectorDb::class) === $argv[0]){

    if(ConnectorDb::processExists()){
        exit(0);
    }
    ConnectorDb::startWorker($argv??[]);
}
