<?php

use SilverStripe\Framework\Core\Extensible;
use SilverStripe\ORM\DataModel;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Admin\LeftAndMain;

/**
 * Provides view and edit forms at GridField-specific URLs.
 *
 * These can be placed into pop-ups by an appropriate front-end.
 *
 * Usually added to a {@link GridField} alongside of a
 * {@link GridFieldEditButton} which takes care of linking the
 * individual rows to their edit view.
 *
 * The URLs provided will be off the following form:
 *  - <FormURL>/field/<GridFieldName>/item/<RecordID>
 *  - <FormURL>/field/<GridFieldName>/item/<RecordID>/edit
 *
 * @package forms
 * @subpackage fields-gridfield
 */
class GridFieldDetailForm implements GridField_URLHandler {

	use Extensible;

	/**
	 * @var string
	 */
	protected $template = 'GridFieldDetailForm';

	/**
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * @var Validator The form validator used for both add and edit fields.
	 */
	protected $validator;

	/**
	 * @var FieldList Falls back to {@link DataObject->getCMSFields()} if not defined.
	 */
	protected $fields;

	/**
	 * @var string
	 */
	protected $itemRequestClass;

	/**
	 * @var callable With two parameters: $form and $component
	 */
	protected $itemEditFormCallback;

	public function getURLHandlers($gridField) {
		return array(
			'item/$ID' => 'handleItem',
			'autocomplete' => 'handleAutocomplete',
		);
	}

	/**
	 * Create a popup component. The two arguments will specify how the popup form's HTML and
	 * behaviour is created.  The given controller will be customised, putting the edit form into the
	 * template with the given name.
	 *
	 * The arguments are experimental API's to support partial content to be passed back to whatever
	 * controller who wants to display the getCMSFields
	 *
	 * @param string $name The name of the edit form to place into the pop-up form
	 */
	public function __construct($name = 'DetailForm') {
		$this->name = $name;
		$this->constructExtensions();
	}

	/**
	 *
	 * @param GridField $gridField
	 * @param SS_HTTPRequest $request
	 * @return GridFieldDetailForm_ItemRequest
	 */
	public function handleItem($gridField, $request) {
		// Our getController could either give us a true Controller, if this is the top-level GridField.
		// It could also give us a RequestHandler in the form of GridFieldDetailForm_ItemRequest if this is a
		// nested GridField.
		$requestHandler = $gridField->getForm()->getController();

		if(is_numeric($request->param('ID'))) {
			$record = $gridField->getList()->byID($request->param("ID"));
		} else {
			$record = Object::create($gridField->getModelClass());
		}

		$handler = $this->getItemRequestHandler($gridField, $record, $requestHandler);

		// if no validator has been set on the GridField and the record has a
		// CMS validator, use that.
		if(!$this->getValidator() && (method_exists($record, 'getCMSValidator') || $record instanceof Object && $record->hasMethod('getCMSValidator'))) {
			$this->setValidator($record->getCMSValidator());
		}

		return $handler->handleRequest($request, DataModel::inst());
	}

	/**
	 * Build a request handler for the given record
	 *
	 * @param GridField $gridField
	 * @param DataObject $record
	 * @param Controller $requestHandler
	 * @return GridFieldDetailForm_ItemRequest
	 */
	protected function getItemRequestHandler($gridField, $record, $requestHandler) {
		$class = $this->getItemRequestClass();
		$this->extend('updateItemRequestClass', $class, $gridField, $record, $requestHandler);
		$handler = \Injector::inst()->createWithArgs(
			$class,
			array($gridField, $this, $record, $requestHandler, $this->name)
		);
		$handler->setTemplate($this->template);
		$this->extend('updateItemRequestHandler', $handler);
		return $handler;
	}

	/**
	 * @param string $template
	 * @return $this
	 */
	public function setTemplate($template) {
		$this->template = $template;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getTemplate() {
		return $this->template;
	}

	/**
	 * @param string $name
	 * @return $this
	 */
	public function setName($name) {
		$this->name = $name;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * @param Validator $validator
	 * @return $this
	 */
	public function setValidator(Validator $validator) {
		$this->validator = $validator;
		return $this;
	}

	/**
	 * @return Validator
	 */
	public function getValidator() {
		return $this->validator;
	}

	/**
	 * @param FieldList $fields
	 * @return $this
	 */
	public function setFields(FieldList $fields) {
		$this->fields = $fields;
		return $this;
	}

	/**
	 * @return FieldList
	 */
	public function getFields() {
		return $this->fields;
	}

	/**
	 * @param string $class
	 * @return $this
	 */
	public function setItemRequestClass($class) {
		$this->itemRequestClass = $class;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getItemRequestClass() {
		if($this->itemRequestClass) {
			return $this->itemRequestClass;
		} else if(ClassInfo::exists(get_class($this) . "_ItemRequest")) {
			return get_class($this) . "_ItemRequest";
		} else {
			return 'GridFieldDetailForm_ItemRequest';
		}
	}

	/**
	 * @param Closure $cb Make changes on the edit form after constructing it.
	 * @return $this
	 */
	public function setItemEditFormCallback(Closure $cb) {
		$this->itemEditFormCallback = $cb;
		return $this;
	}

	/**
	 * @return Closure
	 */
	public function getItemEditFormCallback() {
		return $this->itemEditFormCallback;
	}

}

/**
 * @package forms
 * @subpackage fields-gridfield
 */
class GridFieldDetailForm_ItemRequest extends RequestHandler {

	private static $allowed_actions = array(
		'edit',
		'view',
		'ItemEditForm'
	);

	/**
	 *
	 * @var GridField
	 */
	protected $gridField;

	/**
	 *
	 * @var GridField_URLHandler
	 */
	protected $component;

	/**
	 *
	 * @var DataObject
	 */
	protected $record;

	/**
	 * This represents the current parent RequestHandler (which does not necessarily need to be a Controller).
	 * It allows us to traverse the RequestHandler chain upwards to reach the Controller stack.
	 *
	 * @var RequestHandler
	 */
	protected $popupController;

	/**
	 *
	 * @var string
	 */
	protected $popupFormName;

	/**
	 * @var String
	 */
	protected $template = 'GridFieldItemEditView';

	private static $url_handlers = array(
		'$Action!' => '$Action',
		'' => 'edit',
	);

	/**
	 *
	 * @param GridFIeld $gridField
	 * @param GridField_URLHandler $component
	 * @param DataObject $record
	 * @param RequestHandler $requestHandler
	 * @param string $popupFormName
	 */
	public function __construct($gridField, $component, $record, $requestHandler, $popupFormName) {
		$this->gridField = $gridField;
		$this->component = $component;
		$this->record = $record;
		$this->popupController = $requestHandler;
		$this->popupFormName = $popupFormName;
		parent::__construct();
	}

	public function Link($action = null) {
		return Controller::join_links($this->gridField->Link('item'),
			$this->record->ID ? $this->record->ID : 'new', $action);
	}

	public function view($request) {
		if(!$this->record->canView()) {
			$this->httpError(403);
		}

		$controller = $this->getToplevelController();

		$form = $this->ItemEditForm($this->gridField, $request);
		$form->makeReadonly();

		$data = new ArrayData(array(
			'Backlink'     => $controller->Link(),
			'ItemEditForm' => $form
		));
		$return = $data->renderWith($this->template);

		if($request->isAjax()) {
			return $return;
		} else {
			return $controller->customise(array('Content' => $return));
		}
	}

	public function edit($request) {
		$controller = $this->getToplevelController();
		$form = $this->ItemEditForm($this->gridField, $request);

		$return = $this->customise(array(
			'Backlink' => $controller->hasMethod('Backlink') ? $controller->Backlink() : $controller->Link(),
			'ItemEditForm' => $form,
		))->renderWith($this->template);

		if($request->isAjax()) {
			return $return;
		} else {
			// If not requested by ajax, we need to render it within the controller context+template
			return $controller->customise(array(
				// TODO CMS coupling
				'Content' => $return,
			));
		}
	}

	/**
	 * Builds an item edit form.  The arguments to getCMSFields() are the popupController and
	 * popupFormName, however this is an experimental API and may change.
	 *
	 * @todo In the future, we will probably need to come up with a tigher object representing a partially
	 * complete controller with gaps for extra functionality.  This, for example, would be a better way
	 * of letting Security/login put its log-in form inside a UI specified elsewhere.
	 *
	 * @return Form
	 */
	public function ItemEditForm() {
		$list = $this->gridField->getList();

		if (empty($this->record)) {
			$controller = $this->getToplevelController();
			$url = $controller->getRequest()->getURL();
			$noActionURL = $controller->removeAction($url);
			$controller->getResponse()->removeHeader('Location');   //clear the existing redirect
			return $controller->redirect($noActionURL, 302);
		}

		$canView = $this->record->canView();
		$canEdit = $this->record->canEdit();
		$canDelete = $this->record->canDelete();
		$canCreate = $this->record->canCreate();

		if(!$canView) {
			$controller = $this->getToplevelController();
			// TODO More friendly error
			return $controller->httpError(403);
		}

		// Build actions
		$actions = $this->getFormActions();

		// If we are creating a new record in a has-many list, then
		// pre-populate the record's foreign key.
		if($list instanceof HasManyList && !$this->record->isInDB()) {
			$key = $list->getForeignKey();
			$id = $list->getForeignID();
			$this->record->$key = $id;
		}

		$fields = $this->component->getFields();
		if(!$fields) $fields = $this->record->getCMSFields();

		// If we are creating a new record in a has-many list, then
		// Disable the form field as it has no effect.
		if($list instanceof HasManyList) {
			$key = $list->getForeignKey();

			if($field = $fields->dataFieldByName($key)) {
				$fields->makeFieldReadonly($field);
			}
		}

		// Caution: API violation. Form expects a Controller, but we are giving it a RequestHandler instead.
		// Thanks to this however, we are able to nest GridFields, and also access the initial Controller by
		// dereferencing GridFieldDetailForm_ItemRequest->getController() multiple times. See getToplevelController
		// below.
		$form = new Form(
			$this,
			'ItemEditForm',
			$fields,
			$actions,
			$this->component->getValidator()
		);

		$form->loadDataFrom($this->record, $this->record->ID == 0 ? Form::MERGE_IGNORE_FALSEISH : Form::MERGE_DEFAULT);

		if($this->record->ID && !$canEdit) {
			// Restrict editing of existing records
			$form->makeReadonly();
			// Hack to re-enable delete button if user can delete
			if ($canDelete) {
				$form->Actions()->fieldByName('action_doDelete')->setReadonly(false);
			}
		} elseif(!$this->record->ID && !$canCreate) {
			// Restrict creation of new records
			$form->makeReadonly();
		}

		// Load many_many extraData for record.
		// Fields with the correct 'ManyMany' namespace need to be added manually through getCMSFields().
		if($list instanceof ManyManyList) {
			$extraData = $list->getExtraData('', $this->record->ID);
			$form->loadDataFrom(array('ManyMany' => $extraData));
		}

		// TODO Coupling with CMS
		$toplevelController = $this->getToplevelController();
		if($toplevelController && $toplevelController instanceof LeftAndMain) {
			// Always show with base template (full width, no other panels),
			// regardless of overloaded CMS controller templates.
			// TODO Allow customization, e.g. to display an edit form alongside a search form from the CMS controller
			$form->setTemplate([
				'type' => 'Includes',
				'SilverStripe\\Admin\\LeftAndMain_EditForm',
			]);
			$form->addExtraClass('cms-content cms-edit-form center');
			$form->setAttribute('data-pjax-fragment', 'CurrentForm Content');
			if($form->Fields()->hasTabset()) {
				$form->Fields()->findOrMakeTab('Root')->setTemplate('CMSTabSet');
				$form->addExtraClass('cms-tabset');
			}

			$form->Backlink = $this->getBackLink();
		}

		$cb = $this->component->getItemEditFormCallback();
		if($cb) $cb($form, $this);
		$this->extend("updateItemEditForm", $form);
		return $form;
	}

	/**
	 * Build the set of form field actions for this DataObject
	 *
	 * @return FieldList
	 */
	protected function getFormActions() {
		$canEdit = $this->record->canEdit();
		$canDelete = $this->record->canDelete();
		$actions = new FieldList();
		if($this->record->ID !== 0) {
			if($canEdit) {
				$actions->push(FormAction::create('doSave', _t('GridFieldDetailForm.Save', 'Save'))
					->setUseButtonTag(true)
					->addExtraClass('ss-ui-action-constructive')
					->setAttribute('data-icon', 'accept'));
			}

			if($canDelete) {
				$actions->push(FormAction::create('doDelete', _t('GridFieldDetailForm.Delete', 'Delete'))
					->setUseButtonTag(true)
					->addExtraClass('ss-ui-action-destructive action-delete'));
			}

		} else { // adding new record
			//Change the Save label to 'Create'
			$actions->push(FormAction::create('doSave', _t('GridFieldDetailForm.Create', 'Create'))
				->setUseButtonTag(true)
				->addExtraClass('ss-ui-action-constructive')
				->setAttribute('data-icon', 'add'));

			// Add a Cancel link which is a button-like link and link back to one level up.
			$crumbs = $this->Breadcrumbs();
			if($crumbs && $crumbs->count() >= 2){
				$oneLevelUp = $crumbs->offsetGet($crumbs->count() - 2);
				$text = sprintf(
					"<a class=\"%s\" href=\"%s\">%s</a>",
					"crumb ss-ui-button ss-ui-action-destructive cms-panel-link ui-corner-all", // CSS classes
					$oneLevelUp->Link, // url
					_t('GridFieldDetailForm.CancelBtn', 'Cancel') // label
				);
				$actions->push(new LiteralField('cancelbutton', $text));
			}
		}
		$this->extend('updateFormActions', $actions);
		return $actions;
	}

	/**
	 * Traverse the nested RequestHandlers until we reach something that's not GridFieldDetailForm_ItemRequest.
	 * This allows us to access the Controller responsible for invoking the top-level GridField.
	 * This should be equivalent to getting the controller off the top of the controller stack via Controller::curr(),
	 * but allows us to avoid accessing the global state.
	 *
	 * GridFieldDetailForm_ItemRequests are RequestHandlers, and as such they are not part of the controller stack.
	 *
	 * @return Controller
	 */
	protected function getToplevelController() {
		$c = $this->popupController;
		while($c && $c instanceof GridFieldDetailForm_ItemRequest) {
			$c = $c->getController();
		}
		return $c;
	}

	protected function getBackLink(){
		// TODO Coupling with CMS
		$backlink = '';
		$toplevelController = $this->getToplevelController();
		if($toplevelController && $toplevelController instanceof LeftAndMain) {
			if($toplevelController->hasMethod('Backlink')) {
				$backlink = $toplevelController->Backlink();
			} elseif($this->popupController->hasMethod('Breadcrumbs')) {
				$parents = $this->popupController->Breadcrumbs(false)->items;
				$backlink = array_pop($parents)->Link;
			}
		}
		if(!$backlink) $backlink = $toplevelController->Link();

		return $backlink;
	}

	/**
	 * Get the list of extra data from the $record as saved into it by
	 * {@see Form::saveInto()}
	 *
	 * Handles detection of falsey values explicitly saved into the
	 * DataObject by formfields
	 *
	 * @param DataObject $record
	 * @param SS_List $list
	 * @return array List of data to write to the relation
	 */
	protected function getExtraSavedData($record, $list) {
		// Skip extra data if not ManyManyList
		if(!($list instanceof ManyManyList)) {
			return null;
		}

		$data = array();
		foreach($list->getExtraFields() as $field => $dbSpec) {
			$savedField = "ManyMany[{$field}]";
			if($record->hasField($savedField)) {
				$data[$field] = $record->getField($savedField);
			}
		}
		return $data;
	}

	public function doSave($data, $form) {
		$isNewRecord = $this->record->ID == 0;

		// Check permission
		if (!$this->record->canEdit()) {
			return $this->httpError(403);
		}

		// Save from form data
		try {
			$this->saveFormIntoRecord($data, $form);
		} catch (ValidationException $e) {
			return $this->generateValidationResponse($form, $e);
		}

		$link = '<a href="' . $this->Link('edit') . '">"'
			. htmlspecialchars($this->record->Title, ENT_QUOTES)
			. '"</a>';
		$message = _t(
			'GridFieldDetailForm.Saved',
			'Saved {name} {link}',
			array(
				'name' => $this->record->i18n_singular_name(),
				'link' => $link
			)
		);

		$form->sessionMessage($message, 'good', false);

		// Redirect after save
		return $this->redirectAfterSave($isNewRecord);
	}

	/**
	 * Response object for this request after a successful save
	 *
	 * @param bool $isNewRecord True if this record was just created
	 * @return SS_HTTPResponse|DBHTMLText
	 */
	protected function redirectAfterSave($isNewRecord) {
		$controller = $this->getToplevelController();
		if($isNewRecord) {
			return $controller->redirect($this->Link());
		} elseif($this->gridField->getList()->byID($this->record->ID)) {
			// Return new view, as we can't do a "virtual redirect" via the CMS Ajax
			// to the same URL (it assumes that its content is already current, and doesn't reload)
			return $this->edit($controller->getRequest());
		} else {
			// Changes to the record properties might've excluded the record from
			// a filtered list, so return back to the main view if it can't be found
			$url = $controller->getRequest()->getURL();
			$noActionURL = $controller->removeAction($url);
			$controller->getRequest()->addHeader('X-Pjax', 'Content');
			return $controller->redirect($noActionURL, 302);
		}
	}

	public function httpError($errorCode, $errorMessage = null) {
		$controller = $this->getToplevelController();
		return $controller->httpError($errorCode, $errorMessage);
	}

	/**
	 * Loads the given form data into the underlying dataobject and relation
	 *
	 * @param array $data
	 * @param Form $form
	 * @throws ValidationException On error
	 * @return DataObject Saved record
	 */
	protected function saveFormIntoRecord($data, $form) {
		$list = $this->gridField->getList();

		// Check object matches the correct classname
		if (isset($data['ClassName']) && $data['ClassName'] != $this->record->ClassName) {
			$newClassName = $data['ClassName'];
			// The records originally saved attribute was overwritten by $form->saveInto($record) before.
			// This is necessary for newClassInstance() to work as expected, and trigger change detection
			// on the ClassName attribute
			$this->record->setClassName($this->record->ClassName);
			// Replace $record with a new instance
			$this->record = $this->record->newClassInstance($newClassName);
		}

		// Save form and any extra saved data into this dataobject
		$form->saveInto($this->record);
		$this->record->write();
		$extraData = $this->getExtraSavedData($this->record, $list);
		$list->add($this->record, $extraData);

		return $this->record;
	}

	/**
	 * Generate a response object for a form validation error
	 *
	 * @param Form $form The source form
	 * @param ValidationException $e The validation error message
	 * @return SS_HTTPResponse
	 * @throws SS_HTTPResponse_Exception
	 */
	protected function generateValidationResponse($form, $e) {
		$controller = $this->getToplevelController();

		$form->sessionMessage($e->getResult()->message(), 'bad', false);
		$responseNegotiator = new PjaxResponseNegotiator(array(
			'CurrentForm' => function() use(&$form) {
				return $form->forTemplate();
			},
			'default' => function() use(&$controller) {
				return $controller->redirectBack();
			}
		));
		if($controller->getRequest()->isAjax()){
			$controller->getRequest()->addHeader('X-Pjax', 'CurrentForm');
		}
		return $responseNegotiator->respond($controller->getRequest());
	}


	public function doDelete($data, $form) {
		$title = $this->record->Title;
		$backLink = $this->getBacklink();
		try {
			if (!$this->record->canDelete()) {
				throw new ValidationException(
					_t('GridFieldDetailForm.DeletePermissionsFailure',"No delete permissions"),0);
			}

			$this->record->delete();
		} catch(ValidationException $e) {
			$form->sessionMessage($e->getResult()->message(), 'bad', false);
			return $this->getToplevelController()->redirectBack();
		}

		$message = sprintf(
			_t('GridFieldDetailForm.Deleted', 'Deleted %s %s'),
			$this->record->i18n_singular_name(),
			htmlspecialchars($title, ENT_QUOTES)
		);

		$toplevelController = $this->getToplevelController();
		if($toplevelController && $toplevelController instanceof LeftAndMain) {
			$backForm = $toplevelController->getEditForm();
			$backForm->sessionMessage($message, 'good', false);
		} else {
			$form->sessionMessage($message, 'good', false);
		}

		//when an item is deleted, redirect to the parent controller
		$controller = $this->getToplevelController();
		$controller->getRequest()->addHeader('X-Pjax', 'Content'); // Force a content refresh

		return $controller->redirect($backLink, 302); //redirect back to admin section
	}

	/**
	 * @param string $template
	 * @return $this
	 */
	public function setTemplate($template) {
		$this->template = $template;
		return $this;
	}

	/**
	 * @return String
	 */
	public function getTemplate() {
		return $this->template;
	}

	/**
	 * @return Controller
	 */
	public function getController() {
		return $this->popupController;
	}

	/**
	 * @return GridField
	 */
	public function getGridField() {
		return $this->gridField;
	}

	/**
	 * @return DataObject
	 */
	public function getRecord() {
		return $this->record;
	}

	/**
	 * CMS-specific functionality: Passes through navigation breadcrumbs
	 * to the template, and includes the currently edited record (if any).
	 * see {@link LeftAndMain->Breadcrumbs()} for details.
	 *
	 * @param boolean $unlinked
	 * @return ArrayData
	 */
	public function Breadcrumbs($unlinked = false) {
		if(!$this->popupController->hasMethod('Breadcrumbs')) return;

		$items = $this->popupController->Breadcrumbs($unlinked);
		if($this->record && $this->record->ID) {
			$title = ($this->record->Title) ? $this->record->Title : "#{$this->record->ID}";
			$items->push(new ArrayData(array(
				'Title' => $title,
				'Link' => $this->Link()
			)));
		} else {
			$items->push(new ArrayData(array(
				'Title' => sprintf(_t('GridField.NewRecord', 'New %s'), $this->record->i18n_singular_name()),
				'Link' => false
			)));
		}

		return $items;
	}
}
