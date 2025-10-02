<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

namespace Modules\ModuleSpeechRecognize\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;

class ModuleSpeechRecognize extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $apiKey;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $secretKey;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $cdr_offset;

    /**
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $recognizeAll = '0';

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $useLongRecognize = '0';

    public function initialize(): void
    {
        $this->setSource('m_ModuleSpeechRecognize');
        parent::initialize();
    }
}