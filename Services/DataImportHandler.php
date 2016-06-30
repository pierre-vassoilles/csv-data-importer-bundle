<?php
/**
 * Created by PhpStorm.
 * User: pv
 * Date: 10/05/2016
 * Time: 09:31
 */

namespace CsvDataImporterBundle\Services;


use CsvDataImporterBundle\Model\DataImportResult;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use CsvDataImporterBundle\Model\FieldDescription;
use Symfony\Component\Debug\Exception\ClassNotFoundException;

class DataImportHandler
{

    const ONLY_UPDATE = 1;
    const ONLY_CREATE = 2;
    const CREATE_AND_UPDATE = 3;


    /** @var EntityManager $em */
    protected $em;

    /** @var int $importMode */
    protected $importMode = self::CREATE_AND_UPDATE;

    /** @var string $separator */
    protected $separator = ';';

    /** @var bool $containsHeaders */
    protected $containsHeaders = false;

    /** @var string $filename */
    protected $filename;

    /** @var string $classname */
    protected $classname;

    /** @var string $identifierFieldname */
    protected $identifierFieldname;

    /** @var int $identifierPosition */
    protected $identifierPosition;

    /** @var bool $isInitialized */
    protected $isInitialized = false;

    /** @var array $fields */
    protected $fields = array();

    /** @var \Closure $checkForExistanceClosure */
    protected $checkForExistanceClosure;

    /** @var \Closure $prePersistClosure */
    protected $prePersistClosure;

    /** @var \Closure $postPersistClosure */
    protected $postPersistClosure;

    /**
     * DataImportHandler constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Initializes the importer. <br><br>
     * The $filename is the full filename of the file to import, including path. <br>
     * The $classname is the full name of the class you want to fill with the datas. It must contain the full namespace. <br>
     * The default separator is the ';'. <br>
     * For the $importMode, pick it for the DateImportHander constants (CREATE_AND_UPDATE, ONLY_CREATE, ONLY_UPDATE). <br>
     * The $fileContainsHeaders parameters says if we have to ignore the first line of the file or not. <br>
     *
     * @param string $filename
     * @param string $classname
     * @param string $separator
     * @param int    $importMode
     * @param bool   $fileContainsHeaders
     *
     * @return $this
     *
     * @throws ClassNotFoundException
     */
    public function init($filename, $classname, $separator = ';', $importMode = self::CREATE_AND_UPDATE, $fileContainsHeaders = false)
    {
        if (!class_exists($classname)) {
            throw new ClassNotFoundException(sprintf('The class %s was not found, did you misspelled it ?', $classname), new \ErrorException(''));
        }

        if (!file_exists($filename)) {
            throw new \InvalidArgumentException(sprintf('The file %s you provided was not found on this server.', $filename));
        }

        if (!in_array($importMode, array(
            self::CREATE_AND_UPDATE,
            self::ONLY_CREATE,
            self::ONLY_UPDATE,
        ))
        ) {
            throw new \InvalidArgumentException('The import mode you provided was not found. Please use DataImportHandler\'s constants.');
        }

        $this->fields = array();
        $this->identifierFieldname = null;
        $this->identifierPosition = null;

        $this->classname = $classname;
        $this->filename = $filename;
        $this->separator = $separator;
        $this->importMode = $importMode;
        $this->containsHeaders = (bool)$fileContainsHeaders;

        $this->isInitialized = true;

        return $this;
    }

    /**
     * Adds a field description to the mapping. <br>
     * The $position is the number of the column in the CSV file. <b>Be careful : It starts to 0</b> <br>
     * If the field is the identifier, it will be used to retrieve the object to UPDATE (in ONLY_UPDATE and CREATE_AND_UPDATE modes). <br>
     * The $setter parameter is the name of the setter called by the import process. Let it null il you want to use default setter ('set' . ucfirst($fieldname)). <br>
     *
     * @param      $fieldname
     * @param      $position
     * @param bool $isIdentifier
     * @param bool $required
     * @param null $setter
     *
     * @return $this
     */
    public function addField($fieldname, $position, $isIdentifier = false, $required = false, $setter = null)
    {
        $fieldDescription = new FieldDescription();
        $fieldDescription
            ->setFieldname($fieldname)
            ->setRequired((bool)$required)
            ->setSetter($setter);
        $this->fields[$position] = $fieldDescription;

        if ($isIdentifier) {
            $this->identifierFieldname = $fieldname;
            $this->identifierPosition = $position;
        }

        return $this;
    }

    /**
     * Returns a field description by position
     *
     * @param $position
     *
     * @return null|FieldDescription
     */
    public function getFieldByPosition($position)
    {
        if (array_key_exists($position, $this->fields)) {
            return $this->fields[$position];
        }
        return null;
    }

    /**
     * Returns a field description by fieldname
     *
     * @param $fieldname
     *
     * @return null|FieldDescription
     */
    public function getFieldByFieldname($fieldname)
    {
        /** @var FieldDescription $fieldDescription */
        foreach ($this->fields as $fieldDescription) {
            if ($fieldDescription->getFieldname() == $fieldname) {
                return $fieldDescription;
            }
        }

        return null;
    }

    /**
     * Adds a Closure hook for a position (corresponding to a field).
     * The closure MUST have two parameters : The $value and the $object.
     *
     * @param          $position
     * @param \Closure $closure
     *
     * @throws \Exception
     */
    public function addHook($position, \Closure $closure)
    {
        if ($this->getFieldByPosition($position)) {
            $this->getFieldByPosition($position)->setHook($closure);
        } else {
            throw new \Exception(sprintf('[Import Hooks] No field was found at position %d', $position));
        }

        return $this;
    }

    /**
     * Launches the Import Process. <br>
     * Returns an array of errors. If this array is empty, nos errors was found in the file.
     *
     * @return DataImportResult
     *
     * @throws \Exception
     */
    public function doImport($debug = false, $fake = false)
    {

        if (!$this->isInitialized) {
            throw new \Exception('You must initialize the importer with the init() method before launching the import process.');
        }

        if (!$this->identifierFieldname && ($this->importMode == self::CREATE_AND_UPDATE || $this->importMode == self::ONLY_UPDATE)) {
            throw new \Exception('You must define a field as identifier for the update mode');
        }

        // Initialize the import result
        $result = new DataImportResult();


        // Try to open the file in read mode
        $handle = fopen($this->filename, 'r');

        if ($handle !== false) {

            if ($this->containsHeaders) {
                // Ignore first line with headers
                fgetcsv($handle, 10000, ";");
            }

            $objectCollection = array();

            $line = 2;

            // Going through the dataset
            /** @var array $data */
            while (($data = fgetcsv($handle, 10000, $this->separator)) !== FALSE) {

                if ($debug && $line % 100 == 0) {
                    echo sprintf('%d lignes traitées', $line), PHP_EOL;
                }

                $data = $this->convertUtf8($data);

                $object = null;

                if ($this->importMode == self::CREATE_AND_UPDATE || $this->importMode == self::ONLY_UPDATE) {
                    $identifierValue = $data[$this->identifierPosition];

                    $collection = $this->em->getRepository($this->classname)->findBy(array(
                        $this->identifierFieldname => $identifierValue,
                    ));

                    if (count($collection) > 0) {
                        $object = $collection[0];
                    }

                    if ($this->importMode == self::ONLY_UPDATE && !$object) {
                        $result->addError(sprintf('The object (%s) was not found with a %s corresponding to "%s"', $this->classname, $this->identifierFieldname, $identifierValue));
                        continue;
                    }

                }

                if ($object) {
                    $result->incrementUpdateCount();
                }

                // If we just create objects
                if ($this->importMode == self::ONLY_CREATE) {

                    // Check if a "check for existance" closure is set
                    if ($this->getCheckForExistanceClosure()) {

                        // If it's set, we test it.
                        // The closure must return false il the entity doesn't exist
                        $closure = $this->getCheckForExistanceClosure();
                        if ($closure($data)) {
                            // If the closure says that the entity exists, we do not create it and go to the next line
                            ++$line;
                            continue;
                        }
                    }

                    // If there is no closure set, we just go over
                }

                if ($this->importMode == self::CREATE_AND_UPDATE || $this->importMode == self::ONLY_CREATE) {
                    if (!$object) {
                        $result->incrementCreateCount();

                        $object = new $this->classname();

                        if ($this->identifierFieldname && isset($identifierValue)) {
                            if ($this->identifierPosition && array_key_exists($this->identifierPosition, $this->fields)) {
                                $this->applyHook($identifierValue, $this->fields[$this->identifierPosition], $object);
                            }

                            $identifierSetter = 'set' . ucfirst($this->identifierFieldname);
                            if (method_exists($object, $identifierSetter)) {
                                $object->$identifierSetter($identifierValue);
                            }
                        }

                    }
                }

                $lineHasError = false;

                /**
                 * For all values in the line :
                 * - Check required
                 * - Apply hook
                 * - Set the value to the object
                 */
                foreach ($data as $index => $value) {

                    // Go to next value if not mapped
                    if (!$this->getFieldByPosition($index)) {
                        continue;
                    }

                    // We do not update the identifier value here, if there is an identifier
                    if ($this->identifierFieldname && $index == $this->identifierPosition) {
                        continue;
                    }

                    /** @var FieldDescription $fieldDescription */
                    $fieldDescription = $this->getFieldByPosition($index);

                    // Apply HOOK
                    $this->applyHook($value, $fieldDescription, $object);

                    // Check if the value is empty and required
                    if (!$value && $value !== false && $value != "0" && $fieldDescription->isRequired()) {
                        $lineHasError = true;
                        $result->addError(sprintf('At line %d : The field %s (position %d) cannot be empty', $line, $fieldDescription->getFieldname(), $index));
                        break;
                    }

                    // Setting value to object
                    $this->setValue($object, $value, $fieldDescription);

                }

                $line++;

                if ($lineHasError) {
                    // If the line has an error, go to the next line without storing the object into the collection
                    continue;
                }

                if ($this->importMode == self::CREATE_AND_UPDATE || $this->importMode == self::ONLY_UPDATE) {
                    $objectCollection[$data[$this->identifierPosition]] = $object;

                } else {
                    $objectCollection[] = $object;
                }

            }

            if ($debug) {
                --$line;
                echo sprintf('%d lines processed', $this->containsHeaders ? --$line : $line), PHP_EOL;
            }

            // Save all objects contained in the collection
            if ($debug) {
                echo PHP_EOL,
                '-------------------------------------------------------', PHP_EOL,
                sprintf('Start saving objects (%d objects)...', count($objectCollection)), PHP_EOL,
                '-------------------------------------------------------',
                PHP_EOL;
            }

            if (!$fake) {
                try {
                    if (count($objectCollection) > 10000) {
                        $this->em->getConnection()->getConfiguration()->setSQLLogger(null);
                    }

                    gc_enable();

                    $count = 0;
                    $emCleared = false;

                    $associationNames = $this->em->getClassMetadata($this->classname)->getAssociationNames();
                    $associationNamesCount = count($associationNames);

                    foreach ($objectCollection as $object) {

                        if ($emCleared) {
                            // Get all associations for object and merge them after the clear command
                            for ($i = 0; $i < $associationNamesCount; ++$i) {
                                $assoName = $associationNames[$i];

                                $objAsso = $object->{'get' . $assoName}();
                                if ($objAsso && is_object($objAsso) && !($objAsso instanceof ArrayCollection)) {
                                    $object->{'set' . $assoName}($this->em->merge($objAsso));
                                }
                            }
                        }

                        // Apply a prePersist hook on the object if the closure has been set
                        $prePersistClosure = $this->getPrePersistClosure();
                        if ($prePersistClosure instanceof \Closure) {
                            $prePersistClosure($object);
                        }

                        $this->em->persist($object);

                        // Apply a postPersist hook on the object if the closure has been set
                        $postPersistClosure = $this->getPostPersistClosure();
                        if ($postPersistClosure instanceof \Closure) {
                            $postPersistClosure($object);
                        }

                        if (++$count % 10000 == 0) {
                            $this->em->flush();
                            $this->em->clear();
                            gc_collect_cycles();
                            $emCleared = true;

                            if ($debug) {
                                echo sprintf('%d objects saved on %d...', $count, count($objectCollection)), PHP_EOL;
                            }
                        }
                    }

                    $this->em->flush();
                    $this->em->clear();
                    gc_collect_cycles();

                    if ($debug) {
                        echo 'Datebase record completed',
                        '-------------------------------------------------------',
                        PHP_EOL,
                        PHP_EOL;
                    }

                } catch (\Exception $e) {
                    $result->addError($e->getMessage());
                }
            }

        } else {
            $result->addError(sprintf('The file %s could not be open', $this->filename));
        }

        return $result;
    }

    /**
     * Apply a closure to the value.
     *
     * @param                  $value
     * @param FieldDescription $fieldDescription
     * @param                  $object
     */
    protected function applyHook(&$value, FieldDescription $fieldDescription, $object)
    {
        $hook = $fieldDescription->getHook();
        if ($hook instanceof \Closure) {
            $value = $hook($value, $object);
        }
    }

    /**
     * Set the $value to the $object using the $fieldDescription's setter
     *
     * @param                  $object
     * @param                  $value
     * @param FieldDescription $fieldDescription
     */
    protected function setValue($object, $value, FieldDescription $fieldDescription)
    {
        $setter = $fieldDescription->getSetter();
        if (method_exists($object, $setter)) {
            $object->$setter($value);
        } else {
            throw new \BadMethodCallException(sprintf('The %s class must provide a method named %s', get_class($object), $setter));
        }
    }

    /**
     * Converts all datas of an array to UTF8 if needed
     *
     * @param array $array
     */
    protected function convertArrayUtf8(array $array)
    {
        foreach ($array as $key => $value) {
            $array[$key] = $this->convertUtf8($value);
        }

        return $array;
    }

    /**
     * Convert data in UTF-8 if needed
     *
     * @param $data
     *
     * @return string
     *
     */
    protected function convertUtf8($data)
    {
        if (is_array($data)) {
            return $this->convertArrayUtf8($data);
        }

        if (mb_detect_encoding($data, "UTF-8", true) != 'UTF-8') {
            $data = utf8_encode($data);
        }

        return trim($data);
    }

    /**
     * @return string
     */
    public function getIdentifierFieldname()
    {
        return $this->identifierFieldname;
    }

    /**
     * @param string $identifierFieldname
     */
    public function setIdentifierFieldname($identifierFieldname)
    {
        $this->identifierFieldname = $identifierFieldname;
        return $this;
    }

    /**
     * @return int
     */
    public function getIdentifierPosition()
    {
        return $this->identifierPosition;
    }

    /**
     * @param int $identifierPosition
     */
    public function setIdentifierPosition($identifierPosition)
    {
        $this->identifierPosition = $identifierPosition;
        return $this;
    }

    /**
     * @return \Closure
     */
    public function getCheckForExistanceClosure()
    {
        return $this->checkForExistanceClosure;
    }

    /**
     * @param \Closure $checkForExistanceClosure
     */
    public function setCheckForExistanceClosure($checkForExistanceClosure)
    {
        $this->checkForExistanceClosure = $checkForExistanceClosure;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPrePersistClosure()
    {
        return $this->prePersistClosure;
    }

    /**
     * @param mixed $prePersistClosure
     */
    public function setPrePersistClosure($prePersistClosure)
    {
        $this->prePersistClosure = $prePersistClosure;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPostPersistClosure()
    {
        return $this->postPersistClosure;
    }

    /**
     * @param mixed $postPersistClosure
     */
    public function setPostPersistClosure($postPersistClosure)
    {
        $this->postPersistClosure = $postPersistClosure;

        return $this;
    }

}