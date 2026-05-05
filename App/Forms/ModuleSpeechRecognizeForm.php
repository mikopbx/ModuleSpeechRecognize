<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleSpeechRecognize\App\Forms;

use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Hidden;

class ModuleSpeechRecognizeForm extends Form
{
    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));

        $providerValue = !empty($entity->provider) ? $entity->provider : 'tinkoff';
        $providerSelect = new Select('provider', [
            'tinkoff' => 'Tinkoff VoiceKit',
            'miko'    => 'MIKO Speech (speech.mikolab.ru)',
        ], ['value' => $providerValue, 'class' => 'ui dropdown']);
        $this->add($providerSelect);

        $this->add(new Text('apiKey'));
        $this->add(new Password('secretKey'));

        $syncMaxValue = (int)($entity->syncMaxSeconds ?? 28);
        if ($syncMaxValue <= 0) {
            $syncMaxValue = 28;
        }
        $this->add(new Numeric('syncMaxSeconds', ['value' => $syncMaxValue, 'min' => 1, 'max' => 28]));

        $checkAr = ['value' => null];
        if (!empty($entity->mikoUseDeferredGeneral)) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('mikoUseDeferredGeneral', $checkAr));

        $checkAr = ['value' => null];
        if ($entity->useLongRecognize) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('useLongRecognize', $checkAr));

        $checkAr = ['value' => null];
        if ($entity->recognizeAll) {
            $checkAr = ['checked' => '1'];
        }
        $this->add(new Check('recognizeAll', $checkAr));
    }
}