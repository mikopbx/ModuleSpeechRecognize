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
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Hidden;

class ModuleSpeechRecognizeForm extends Form
{
    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Text('apiKey'));
        $this->add(new Password('secretKey'));

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