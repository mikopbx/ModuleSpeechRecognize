FROM debian:bullseye-slim

# docker build --tag tinkoff:tts-v1 - < Dockerfile
# docker run -it -d --rm --name tinkoff-tts -v /root:/root/data -v /root/voicekit-examples/python/recognize.py:/voicekit/python/recognize.py tinkoff:tts-v1
# docker exec -it tinkoff-tts  /usr/bin/python3 /voicekit/python/recognize.py --api_key='' --secret_key='' -r 8000  -c 2 -e MPEG_AUDIO /root/data/79779767628_223.mp3 

# docker save  tinkoff:tts-v1 -o tinkoff-tts-v1.tar
# docker exec -it tinkoff-tts '/bin/bash'

RUN set -eux; \
	apt-get update; \
	apt-get install  -y curl unzip python3-pip python3 python3-pyaudio; \
	curl -L -o master.zip 'https://codeload.github.com/TinkoffCreditSystems/voicekit-examples/zip/refs/heads/master'; \
	unzip master.zip; mv /voicekit-examples-master /voicekit; \
	cd /voicekit/python/; python3 -m pip install -r requirements/all.txt; ./generate_protobuf.sh \
	cd /; rm -rf /master.zip ;

CMD ["python3"]