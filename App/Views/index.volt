
<form class="ui large grey segment form" id="module-speech-recognize-form">
    {{ form.render('id') }}

    <div class="ten wide field">
        <label>{{ t._('module_speech_recognize_provider') }}</label>
        {{ form.render('provider') }}
    </div>

    <div class="ten wide field provider-tinkoff">
        <label>{{ t._('module_speech_recognize_apiKey') }}</label>
        {{ form.render('apiKey') }}
    </div>
    <div class="ten wide field provider-tinkoff">
        <label>{{ t._('module_speech_recognize_secretKey') }}</label>
        {{ form.render('secretKey') }}
    </div>

    <div class="four wide field provider-miko">
        <label>{{ t._('module_speech_recognize_syncMaxSeconds') }}</label>
        {{ form.render('syncMaxSeconds') }}
    </div>
    <div class="field provider-miko">
        <div class="ui segment">
            <div class="ui toggle checkbox">
                <label>{{ t._('module_speech_recognize_mikoUseDeferredGeneral') }}</label>
                {{ form.render('mikoUseDeferredGeneral') }}
            </div>
        </div>
    </div>

    <div class="field provider-tinkoff">
        <div class="ui segment">
            <div class="ui toggle checkbox">
                <label>{{ t._('module_speech_recognize_useLongRecognize') }}</label>
                {{ form.render('useLongRecognize') }}
            </div>
        </div>
    </div>
    <div class="field">
        <div class="ui segment">
            <div class="ui toggle checkbox">
                <label>{{ t._('module_speech_recognize_recognizeAll') }}</label>
                {{ form.render('recognizeAll') }}
            </div>
        </div>
    </div>

    {{ partial("partials/submitbutton",['indexurl':'pbx-extension-modules/index/']) }}
</form>

<script>
    (function () {
        function applyProviderVisibility() {
            var $select = $('#module-speech-recognize-form select[name="provider"]');
            if ($select.length === 0) {
                return;
            }
            var provider = $select.val() || 'tinkoff';
            $('#module-speech-recognize-form .provider-tinkoff').toggle(provider === 'tinkoff');
            $('#module-speech-recognize-form .provider-miko').toggle(provider === 'miko');
        }
        $(function () {
            $('#module-speech-recognize-form select[name="provider"]')
                .dropdown({onChange: applyProviderVisibility});
            applyProviderVisibility();
        });
    })();
</script>
