<?php
/**
 * Created by PhpStorm.
 * User: pv
 * Date: 10/05/2016
 * Time: 09:26
 */

namespace CsvDataImporterBundle\Model;


class FieldDescription
{

    protected $fieldname;

    protected $required = false;

    protected $setter;
 
    protected $hook;


    public function __construct()
    {

    }

    public function getDefaultSetter()
    {
        return 'set' . ucfirst($this->fieldname);
    }

    /**
     * @return mixed
     */
    public function getFieldname()
    {
        return $this->fieldname;
    }

    /**
     * @param mixed $fieldname
     */
    public function setFieldname($fieldname)
    {
        $this->fieldname = $fieldname;
        return $this;
    }

    /**
     * @return boolean
     */
    public function isRequired()
    {
        return $this->required;
    }

    /**
     * @param boolean $required
     */
    public function setRequired($required)
    {
        $this->required = $required;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSetter()
    {
        return ($this->setter ?: $this->getDefaultSetter());
    }

    /**
     * @param mixed $setter
     */
    public function setSetter($setter)
    {
        $this->setter = $setter;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getHook()
    {
        return $this->hook;
    }

    /**
     * @param mixed $hook
     */
    public function setHook($hook)
    {
        $this->hook = $hook;
        return $this;
    }

}