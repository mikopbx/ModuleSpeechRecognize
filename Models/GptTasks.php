<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

/*
 * https://docs.phalcon.io/4.0/en/db-models
 *
 */

namespace Modules\ModuleSpeechRecognize\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Class ManualTasks
 *
 * @package Modules\ModuleCdrTextData\Models
 * @Indexes(
 *     [name='closeTime', columns=['closeTime'], type=''],
 *     [name='requestId', columns=['requestId'], type=''],
 *     [name='changeTime', columns=['changeTime'], type=''],
 *     [name='linkedId', columns=['linkedId'], type='']
 * )
 */
class GptTasks extends ModulesModelsBase
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
    public $linkedId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $instruction;

    /**
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $changeTime = '0';

    /**
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $requestId = "";

    /**
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $waitRecognize = 0;

    /**
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $response = '';

    /**
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $closeTime = '0';

    public function initialize(): void
    {
        $this->setSource('m_GptTasks');
        parent::initialize();
    }
}