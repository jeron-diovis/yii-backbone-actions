<?php

/**
 * AbstractBackboneActionModel class file
 *
 * @author Jeron Diovis <void.jeron.diovis@gmail.com>
 * @package yii.backbone.actions
 * @license MIT
 */

/**
 * Class AbstractBackboneActionModel
 *
 * This class defines a common logic of processing requests from Backbone's models/collections
 * - i.e., mapping request type to corresponding action,
 * as it is described in default Backbone's 'sync' method implementation.
 *
 * That is, following rules used:
 * GET - find and return existing model
 * POST - create and save a new model from received data
 * PUT/PATCH - update existing model with received data (the difference is only in received data volume)
 * DELETE - remove existing model
 *
 * All combinations of Backbone's 'emulateHTTP' or 'emulateJSON' options are supported (if your server supports PUT/DELETE/etc. and application/json too, of course).
 *
 * It is also assumed default Backbone's 'url' method implementation - i.e., that existing model's id is just appended to model's urlRoot.
 * So ensure that your UrlManager provides right rules for this action - so model id can be fetched from request.
 * If you need to change default implementation, you can override {@link getModelId} method.
 */
abstract class AbstractBackboneActionModel extends AbstractBackboneAction {

	/**
	 * @var string The class of model this action works with.
	 */
	protected $_modelClassName = '';

	/**
	 * @var callable Processed raw model id, fetched from request, before it will be used to find model.
	 * It can be useful if you work, for example, with MongoDB, where model's id is MongoId object, which must be created from raw id string.
	 *
	 * Callback takes raw id as first argument
	 */
	protected $_composeModelIdCallback = null;

	/**
	 * Implement contract method
	 * @return array|string
	 */
	protected function processRequest() {
		$request = $this->getRequest();
		if ($request->getIsDeleteRequest()) {
			$response = $this->delete();
		} elseif ($request->getIsPutRequest() || $this->getIsPatchRequest()) {
			$response = $this->update();
		} elseif ($request->getIsPostRequest()) {
			$response = $this->save();
		} else {
			$response = $this->fetch();
		}
		return $response;
	}

	/**
	 * Founds existing model
	 * @return mixed
	 */
	abstract protected function fetch();

	/**
	 * Removes existing model
	 * @return mixed
	 */
	abstract protected function delete();

	/**
	 * Creates a new model
	 * @return mixed
	 */
	abstract protected function save();

	/**
	 * Updates existing model
	 * @return mixed
	 */
	abstract protected function update();

	// Following methods fetches from request specific params provided by Backbone.sync :

	/**
	 * Fetches model id from request.
	 *
	 * Default implementation assumes that request route was composed as Backbone's default - by appending model's id to model's urlRoot,
	 * and that composed url was parsed by CUrlManager with proper rule configured,
	 * so id was parsed and saved to $_GET array.
	 *
	 * @return string|int Model id
	 */
	protected function getModelId() {
		$modelId = $this->getRequest()->getParam('id'); // id is always available through 'getParam', no matter to request type
		if (($callback = $this->getComposeModelIdCallback()) !== null) {
			$modelId = call_user_func($callback, $modelId);
		}
		return $modelId;
	}

	/**
	 * @return array Raw model attributes to be parsed and processed by action
	 */
	protected function getRawModelData() {
		$request = $this->getRequest();

		// support 'emulateJSON' Backbone's option - both 'application/json' and 'application/x-www-form-urlencoded' requests:
		if ($this->getIsJSON()) {
			$data = $request->getRawBody();
		} else {
			$restParams = $request->getRestParams(); // use 'getRestParams', because data can be passed through PATCH request which is not supported by Yii
			$data = $restParams['model'];
		}

		$data = $this->decode($data);
		if ($data === null) {
			$data = array();
		}

		return $data;
	}

	// Get / Set :

	/**
	 * @param string $modelClassName
	 */
	public function setModelClassName($modelClassName) {
		$this->_modelClassName = $modelClassName;
	}

	/**
	 * @return string
	 * @throws CException
	 */
	public function getModelClassName() {
		if (empty($this->_modelClassName)) {
			throw new CException(__METHOD__ . ": 'modelClassName' is not specified!");
		}
		return $this->_modelClassName;
	}

	/**
	 * @param callable $callback
	 * @throws CException
	 */
	public function setComposeModelIdCallback($callback) {
		if (!is_callable($callback)) {
			throw new CException(__METHOD__ . ': "composeModelIdCallback" must be a callable');
		}
		$this->_composeModelIdCallback = $callback;
	}

	/**
	 * @return callable
	 */
	public function getComposeModelIdCallback() {
		return $this->_composeModelIdCallback;
	}
}