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
 * Class ModuleCdrTextData
 *
 * @package Modules\ModuleCdrTextData\Models
 * @Indexes(
 *     [name='UNIQUEID', columns=['UNIQUEID'], type=''],
 *     [name='linkedId', columns=['linkedId'], type=''],
 *     [name='fail', columns=['fail'], type=''],
 *     [name='time', columns=['time'], type='']
 * )
 */
class RecognizeOperations extends ModulesModelsBase
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
    public $UNIQUEID;
    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $filename;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $linkedId;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $start;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $src_num;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $dst_num;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $answer;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $endtime;

    /**
     *
     * @Column(type="string", nullable=true)
     */
    public $operation;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $fail;

    /**
     *
     * @Column(type="integer", nullable=true)
     */
    public $time;

    public function initialize(): void
    {
        $this->setSource('m_ModuleRecognizeOperations');
        parent::initialize();
    }
}