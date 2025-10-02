<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2020
 */

namespace Modules\ModuleSpeechRecognize\Lib\RestAPI\Controllers;
use GuzzleHttp\Client;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Util;
use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleSpeechRecognize\Lib\SpeechRecognizeConf;
use Modules\ModuleSpeechRecognize\Models\CdrText;
use Modules\ModuleSpeechRecognize\Models\GptTasks;
use Modules\ModuleSpeechRecognize\Models\ManualTasks;

class ApiController extends ModulesControllerBase
{
    /**
     * curl http://127.0.0.1/pbxcore/api/speech-recognize/get-cdr-data?limit=2&offset=1
     * http://127.0.0.1/pbxcore/api/speech-recognize/get-cdr-data?link-id=mikopbx-1636956887.22252
     */
    public function getCdrData(): void
    {
        $sr = new SpeechRecognizeConf();
        $result = $sr->getCdrDataAction($_REQUEST);
        $this->printResult($result);
    }

    /**
     * Вывод результата работы функций в браузер.
     * @param $data
     * @return void
     */
    private function printResult($data):void
    {
        try {
            echo json_encode($data->getResult(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }catch (\Throwable $e){
            Util::sysLogMsg('ModuleSpeechRecognize', $e->getMessage());
        }
        $this->response->sendRaw();
    }

    /**
     * curl 'http://127.0.0.1/pbxcore/api/speech-recognize/add-manual-task?linkedid=mikopbx-1757929000.72'
     * @return void
     */
    public function addManualTasks()
    {
        $res    = new PBXApiResult();
        $linkedId = $_REQUEST['linkedid']??'';
        if(empty($linkedId)){
            $res->messages[] = 'linkedid is empty';
            $this->printResult($res);
            return;
        }
        $taskData = ManualTasks::findFirst([
            'linkedId=:linkedid:',
            'bind' => [
                'linkedid'=>$linkedId
            ]
        ]);
        if($taskData){
            $res->success = true;
            $res->messages[] = 'The task was already added earlier';
            $this->printResult($res);
            return;
        }
        $taskData = new ManualTasks();
        $taskData->linkedId = $linkedId;
        $taskData->changeTime = time();
        $res->success = $taskData->save();
        $res->messages[] = 'The task added.';
        $this->printResult($res);
    }

    /**
     curl -X POST http://127.0.0.1/pbxcore/api/speech-recognize/add-gpt-task \
      -H "Content-Type: application/json" \
      -d '{
      "model": "yandexgpt-lite",
      "instruction": "Верни ответ в JSON формате. в запросе телефонный разговор в виде текста\nО.НомерКанала: Реплика ПереводСтроки\nО.НомерКанала: Реплика ПереводСтроки\nПроанализируй реплики. Требуется получить ответы в виде JSON и дозаполнить поля comment, resultBoolean, resultArray\n{\n  \"q1\": {q: \"Задавал ли менеджер вопрос – Когда планируется приобретение?\", comment: \"\", resultBoolean: true},\n  \"q2\": {q: \"Какая номенклатура (товары упоминались), верни массив значений\", comment: \"\", resultArray: true}\n}",
      "temperature": 0,
      "max_tokens": 2000,
      "id": "mikopbx-1739431681.8"
     }'
     *
     * Наполняется таблица задач.
     * sqlite3 /storage/usbdisk1/mikopbx/custom_modules/ModuleSpeechRecognize/db/module.db "select id,linkedId,waitRecognize,requestId,changeTime,closeTime from m_GptTasks"
     */
    public function addGptTask(): void
    {
        $res    = new PBXApiResult();
        try {
            $data =  $this->request->getJsonRawBody(true);
        }catch (\Throwable $e){
            $res->messages[] = 'Fail JSON data';
            $this->printResult($res);
            return;
        }
        $linkedId = $data['id']??'';
        if(empty($linkedId)){
            $res->messages[] = 'ID is empty...';
            $this->printResult($res);
            return;
        }
        $instruction = $data['instruction']??"Выдай сводку по телефонному звонку.";
        try {
            $job = [
                'model'       =>  $data['model']??'yandexgpt-lite',
                'temperature' =>  intval($data['temperature']??0),
                'instruction' =>  $instruction,
                'max_tokens'  =>  intval($data['max_tokens']??2000),
                'query'       => ''
            ];
            $dataCdr = CdrText::find(["linkedId=:linkedId:", 'bind' => ['linkedId' => $linkedId] ])->toArray();
            $waitRecognize = count($dataCdr) === 0;
            foreach ($dataCdr as $d) {
                $textData = json_decode($d['text'], true);
                foreach ($textData as $text) {
                    $ch = $text['channel']??'';
                    $job['query'].="О.$ch: ".$text['text'].PHP_EOL;
                }
            }
            unset($dataCdr);
        }catch (\Throwable $e){
            $res->messages[] = 'Fail create job...';
            $this->printResult($res);
            return;
        }

        $requestId = '';
        $res->data['waitRecognize'] = $waitRecognize;
        if($waitRecognize === false){
            try {
                [$waitRecognize, $requestId, $statusCode] = self::sendGptTask($linkedId, $job);
                $this->response->setStatusCode($statusCode);
                $res->messages[] = 'Send send job (HTTP)... status: '.$statusCode;
                $res->data['requestId']  = $requestId;
                $res->data['statusCode'] = $statusCode;
            } catch (\Throwable $e) {
                $res->messages[] = 'Fail send job (HTTP)...';
                $waitRecognize = true;
                $this->response->setStatusCode(503);
            }
        }
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
        $res->success = $task->save();

        $this->printResult($res);
    }

    public static function sendGptTask(string $id, array $job)
    {
        $waitRecognize = true;
        $requestId = '';
        $key = PbxSettings::getValueByKey('PBXLicense');
        $client = new Client();
        $jsonData = json_encode($job);
        $response = $client->post('https://speech.mikolab.ru/v1/gpt/completionAsync', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Key '.$key,
                'X-Request-ID'  => $id.microtime(true),
            ],
            'body' => $jsonData
        ]);

        $tmpDir = '/storage/usbdisk1/mikopbx/tmp/ModuleSpeechRecognize';
        Util::mwMkdir($tmpDir);
        file_put_contents("$tmpDir/$id.json", $jsonData);
        $statusCode = $response->getStatusCode();
        if($statusCode === 200){
            $body = json_decode($response->getBody()->getContents(), true);
            $waitRecognize = false;
            $requestId = $body['result']['id']??'';
        }
        return [$waitRecognize, $requestId, $statusCode];
    }

    public static function getGptTaskResult($id):array
    {
        $key = PbxSettings::getValueByKey('PBXLicense');
        try{
            $client = new Client();
            $response = $client->get('https://speech.mikolab.ru/v1/gpt/result/'.$id, [
                'headers' => [
                    'Authorization' => 'Key '.$key,
                ],
            ]);
            $statusCode = $response->getStatusCode();
            $body       = $response->getBody()->getContents();
        } catch (\Throwable $e) {
            $statusCode = 0;
            $body       = $e->getMessage();
        }
        return [$statusCode, $body];
    }

    /**
     * curl http://127.0.0.1/pbxcore/api/speech-recognize/get-gpt-results?time=1758013611
     */
    public function getGptResults(): void
    {
        $res    = new PBXApiResult();
        $res->success = true;

        $filter = [
            'changeTime>:changeTime:','bind' => [
                'changeTime'  => $_REQUEST['time']??time()
            ],
            'columns' => 'linkedId,changeTime,waitRecognize,requestId,closeTime,response',
            'order' => 'changeTime ASC',
            'limit' => 450
        ];
        $res->data = GptTasks::find($filter)->toArray();
        $this->printResult($res);
    }

}