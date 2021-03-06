<?php

/**
 * ArProblemType form base class.
 *
 * @method ArProblemType getObject() Returns the current form's model object
 *
 * @package    asterisell
 * @subpackage form
 * @author     Your name here
 */
abstract class BaseArProblemTypeForm extends BaseFormPropel
{
  public function setup()
  {
    $this->setWidgets(array(
      'id'   => new sfWidgetFormInputHidden(),
      'name' => new sfWidgetFormInputText(),
    ));

    $this->setValidators(array(
      'id'   => new sfValidatorChoice(array('choices' => array($this->getObject()->getId()), 'empty_value' => $this->getObject()->getId(), 'required' => false)),
      'name' => new sfValidatorString(array('max_length' => 512, 'required' => false)),
    ));

    $this->widgetSchema->setNameFormat('ar_problem_type[%s]');

    $this->errorSchema = new sfValidatorErrorSchema($this->validatorSchema);

    parent::setup();
  }

  public function getModelName()
  {
    return 'ArProblemType';
  }


}
