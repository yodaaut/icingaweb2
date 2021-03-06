<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Module\Setup\Forms;

use PDOException;
use Icinga\Web\Form;
use Icinga\Forms\Config\Resource\DbResourceForm;
use Icinga\Module\Setup\Utils\DbTool;

/**
 * Wizard page to define connection details for a database resource
 */
class DbResourcePage extends Form
{
    /**
     * Initialize this page
     */
    public function init()
    {
        $this->setTitle($this->translate('Database Resource', 'setup.page.title'));
        $this->setValidatePartial(true);
    }

    /**
     * @see Form::createElements()
     */
    public function createElements(array $formData)
    {
        $this->addElement(
            'hidden',
            'type',
            array(
                'required'  => true,
                'value'     => 'db'
            )
        );

        if (isset($formData['skip_validation']) && $formData['skip_validation']) {
            $this->addSkipValidationCheckbox();
        } else {
            $this->addElement(
                'hidden',
                'skip_validation',
                array(
                    'required'  => true,
                    'value'     => 0
                )
            );
        }

        $resourceForm = new DbResourceForm();
        $this->addElements($resourceForm->createElements($formData)->getElements());
        $this->getElement('name')->setValue('icingaweb_db');
    }

    /**
     * Validate the given form data and check whether it's possible to connect to the database server
     *
     * @param   array   $data   The data to validate
     *
     * @return  bool
     */
    public function isValid($data)
    {
        if (false === parent::isValid($data)) {
            return false;
        }

        if (false === isset($data['skip_validation']) || $data['skip_validation'] == 0) {
            try {
                $db = new DbTool($this->getValues());
                $db->checkConnectivity();
            } catch (PDOException $e) {
                $this->error($e->getMessage());
                $this->addSkipValidationCheckbox();
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether it's possible to connect to the database server
     *
     * This will only run the check if the user pushed the 'backend_validation' button.
     *
     * @param   array   $formData
     *
     * @return  bool
     */
    public function isValidPartial(array $formData)
    {
        if (isset($formData['backend_validation']) && parent::isValid($formData)) {
            try {
                $db = new DbTool($this->getValues());
                $db->checkConnectivity();
            } catch (PDOException $e) {
                $this->warning(sprintf(
                    $this->translate('Failed to successfully validate the configuration: %s'),
                    $e->getMessage()
                ));
                return false;
            }

            $this->info($this->translate('The configuration has been successfully validated.'));
        } elseif (! isset($formData['backend_validation'])) {
            // This is usually done by isValid(Partial), but as we're not calling any of these...
            $this->populate($formData);
        }

        return true;
    }

    /**
     * Add a checkbox to the form by which the user can skip the connection validation
     */
    protected function addSkipValidationCheckbox()
    {
        $this->addElement(
            'checkbox',
            'skip_validation',
            array(
                'required'      => true,
                'label'         => $this->translate('Skip Validation'),
                'description'   => $this->translate(
                    'Check this to not to validate connectivity with the given database server'
                )
            )
        );
    }
}
