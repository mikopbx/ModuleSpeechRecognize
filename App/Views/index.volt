
<form class="ui large grey segment form" id="module-speech-recognize-form">
    {{ form.render('id') }}

    <div class="ten wide field disability">
        <label >{{ t._('module_speech_recognize_apiKey') }}</label>
        {{ form.render('apiKey') }}
    </div>
    <div class="ten wide field disability">
        <label >{{ t._('module_speech_recognize_secretKey') }}</label>
        {{ form.render('secretKey') }}
    </div>


    <div class="field disability">
        <div class="ui segment">
            <div class="ui toggle checkbox">
                <label>{{ t._('module_speech_recognize_useLongRecognize') }}</label>
                {{ form.render('useLongRecognize') }}
            </div>
        </div>
    </div>
    <div class="field disability">
        <div class="ui segment">
            <div class="ui toggle checkbox">
                <label>{{ t._('module_speech_recognize_recognizeAll') }}</label>
                {{ form.render('recognizeAll') }}
            </div>
        </div>
    </div>

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>