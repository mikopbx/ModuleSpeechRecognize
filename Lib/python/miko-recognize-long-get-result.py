#! /usr/bin/env python3
from tinkoff.cloud.stt.v1 import stt_pb2_grpc
from auth import authorization_metadata
from audio import audio_open_read
from common import build_recognition_request, make_channel, print_recognition_response, LongRecognitionParser
from tinkoff.cloud.longrunning.v1 import longrunning_pb2_grpc, longrunning_pb2
from tinkoff.cloud.longrunning.v1.longrunning_pb2 import OperationState, FAILED, ENQUEUED, DONE, PROCESSING
from google.protobuf.json_format import MessageToDict
from tinkoff.cloud.stt.v1 import stt_pb2
import json
import grpc

def build_get_operation_request(id):
    request = longrunning_pb2.GetOperationRequest()
    request.id = id
    return request

def printRecognitionResponse(response):
    if not isinstance(response, dict):
        # https://developers.google.com/protocol-buffers/docs/proto3#json
        response = MessageToDict(response,
                                 including_default_value_fields=True,
                                 preserving_proto_field_name=True)
    print(json.dumps(response))

def main():
    try:
        args = LongRecognitionParser().parse_args()
        operations_stub     = longrunning_pb2_grpc.OperationsStub(grpc.secure_channel(args.endpoint, grpc.ssl_channel_credentials()))
        operations_metadata = authorization_metadata(args.api_key, args.secret_key, "tinkoff.cloud.longrunning")
        operation = operations_stub.GetOperation(build_get_operation_request(args.id), metadata=operations_metadata)
    except grpc._channel._InactiveRpcError as inst:
        print(inst.debug_error_string())
    except Exception as inst:
        print(json.dumps(str(inst)))
    else:
        printRecognitionResponse(operation)

if __name__ == "__main__":
    main()
