<?php
/**
 * Created by PhpStorm.
 * User: pvasouilles
 * Date: 30/06/2016
 * Time: 13:42
 */

namespace CsvDataImporterBundle\Model;


class DataImportResult
{

    /** @var array $errors */
    protected $errors = array();

    /** @var int $updateCount */
    protected $updateCount = 0;

    /** @var int $createCount */
    protected $createCount = 0;

    /**
     * DataImportResult constructor.
     */
    public function __construct()
    {
        
    }

    /**
     * Increments number of updated objects
     *
     * @param int $nb
     */
    public function incrementUpdateCount($nb = 1)
    {
        $this->updateCount += $nb;
    }

    /**
     * Increments number of created objects
     *
     * @param int $nb
     */
    public function incrementCreateCount($nb = 1)
    {
        $this->createCount += $nb;
    }

    /**
     * Adds an error to the result
     *
     * @param $error
     * @return $this
     */
    public function addError($error)
    {
        $this->errors[] = $error;
        return $this;
    }

    /**
     * Returns the number of errors of the result
     *
     * @return int
     */
    public function getErrorCount()
    {
        return count($this->errors);
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param array $errors
     */
    public function setErrors($errors)
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * @return int
     */
    public function getUpdateCount()
    {
        return $this->updateCount;
    }

    /**
     * @param int $updateCount
     */
    public function setUpdateCount($updateCount)
    {
        $this->updateCount = $updateCount;

        return $this;
    }

    /**
     * @return int
     */
    public function getCreateCount()
    {
        return $this->createCount;
    }

    /**
     * @param int $createCount
     */
    public function setCreateCount($createCount)
    {
        $this->createCount = $createCount;

        return $this;
    }

}