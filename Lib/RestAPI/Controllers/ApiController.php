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
use Modules\ModuleSpeechRecognize\bin\ConnectorDb;

class ApiController extends ModulesControllerBase
{
    /**
     * curl 'http://127.0.0.1/pbxcore/api/speech-recognize/get-cdr-data?limit=2&offset=0'
     * curl 'http://127.0.0.1/pbxcore/api/speech-recognize/get-cdr-data?link-id=mikopbx-1763544495.12'
     */
    public function getCdrData(): void
    {
        $data = $_REQUEST;
        $result = new PBXApiResult();
        try {
            if (isset($data['offset'])) {
                $offset = intval($data['offset'] ?? 0);
                $limit  = intval($data['limit'] ?? 30);
                $result->data = ConnectorDb::invoke(ConnectorDb::FUNC_CDR_BY_OFFSET, [$offset, $limit]) ?: [];
            } elseif (isset($data['link-id'])) {
                $result->data = ConnectorDb::invoke(ConnectorDb::FUNC_CDR_BY_ID, [$data['link-id']]) ?: [];
            } else {
                $result->data[] = $data;
            }
            $result->success = true;
        } catch (\Throwable $e) {
            $result->messages[] = $e->getMessage();
        }
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
            if(!is_array($data)){
                $data =  $data->getResult();
            }
            echo json_encode( $data,JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }catch (\Throwable $e){
            Util::sysLogMsg('ModuleSpeechRecognize', $e->getMessage());
        }
        $this->response->sendRaw();
    }

    /**
     * curl 'http://127.0.0.1/pbxcore/api/speech-recognize/add-manual-task?linkedid=mikopbx-1763544495.12'
     * @return void
     */
    public function addManualTasks()
    {
        $res = new PBXApiResult();
        $linkedId = $_REQUEST['linkedid'] ?? '';
        if (empty($linkedId)) {
            $res->messages[] = 'linkedid is empty';
            $this->printResult($res);
            return;
        }
        try {
            $res = ConnectorDb::invoke(ConnectorDb::FUNC_ADD_MANUAL_TASK, [$linkedId]);
        } catch (\Throwable $e) {
            $res->messages[] = $e->getMessage();
        }
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
      "id": "mikopbx-1763544495.12"
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
        $linkedId = $data['id'] ?? '';
        if (empty($linkedId)) {
            $res->messages[] = 'ID is empty...';
            $this->printResult($res);
            return;
        }
        try {
            $res = ConnectorDb::invoke(ConnectorDb::FUNC_ADD_GPT_TASK, [$data]);
        } catch (\Throwable $e) {
            $res->messages[] = $e->getMessage();
        }
        $this->printResult($res);
    }

    public static function sendGptTask(string $id, array $job)
    {
        if(empty(trim( $job['query']))){
            return [false, '', 503];
        }
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
     * curl http://127.0.0.1/pbxcore/api/speech-recognize/get-gpt-results?time=1763476467
     */
    public function getGptResults(): void
    {
        $res = new PBXApiResult();
        try {
            $res->data = ConnectorDb::invoke(ConnectorDb::FUNC_GPT_RESULTS, [$_REQUEST['time'] ?? time()]) ?: [];
            $res->success = true;
        } catch (\Throwable $e) {
            $res->messages[] = $e->getMessage();
        }
        $this->printResult($res);
    }

}