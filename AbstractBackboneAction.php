<?php

/**
 * AbstractBackboneAction class file
 *
 * @author Jeron Diovis <void.jeron.diovis@gmail.com>
 * @package yii.backbone.actions
 * @license MIT
 */

/**
 * Class AbstractBackboneAction
 *
 * Basic class which defines a common requests processing logic and provides a set of tools to process received/sent data.
 * It also provides tools for recognizing request type, including PATCH request.
 *
 * It is designed to process HTTP requests. If you need to change it, you should override {@link getRequest} method to return your own request class, which must satisfy to CHttpRequest interface.
 */
abstract class AbstractBackboneAction extends CAction {

	/**
	 * @var callable
	 */
	protected $_encodeCallback = array('CJSON', 'encode');

	/**
	 * @var callable
	 */
	protected $_decodeCallback = array('CJSON', 'decode');

	/**
	 * @var callable Allows easily to set some external function as data composer,
	 * to do not create a new action class with overridden {@link parseDefuault} method.
	 *
	 * For example, you can assign some controller method to this callback.
	 *
	 * @see compose
	 */
	protected $_composeCallback = null;

	/**
	 * @var callable Allows easily to set some external function as data parser,
	 * to do not create a new action class with overridden {@link parseDefault} method.
	 *
	 * For example, you can assign some controller method to this callback.
	 *
	 * @see parse
	 */
	protected $_parseCallback = null;

	/**
	 * Executes this action
	 *
	 * @see validateRequest
	 */
	public function run() {
		$this->ensureValidRequest();
		$this->init();
		$response = $this->processRequest();
		$response = $this->encode($response);
		echo $response;
		Yii::app()->end();
	}

	/**
	 * Initializes action before processing request
	 */
	protected function init() {}

	/**
	 * Main method
	 * @return mixed Data to be sent to client (not encoded)
	 */
	abstract protected function processRequest();

	// parse/compose:

	/**
	 * Post-processes basic response data to compose them to be sent to client.
	 * Can be used to add to response some params, not mapped to basic data, or to set proper data types, etc.
	 *
	 * If {@link _composeCallback} is set, it will be used, otherwise {@link composeDefault} will be used.
	 *
	 * @param mixed $data Basic response data, returned by some other method
	 * @return array Final data structure to be sent to client.
	 *
	 * @see _composeCallback
	 * @see composeDefault
	 */
	protected function compose($data) {
		if (($callback = $this->getComposeCallback()) !== null) {
			return call_user_func($callback, $data);
		} else {
			return $this->composeDefault($data);
		}
	}

	/**
	 * Basic 'built-in' data composer.
	 *
	 * Mainly, you should override it according to your own data
	 * if you create own action class inherited from this one.
	 *
	 * @param mixed $data
	 * @return mixed
	 *
	 * @see compose
	 */
	protected function composeDefault($data) {
		return $data;
	}

	/**
	 * Pre-processes raw request data to extract only data required for processing by action.
	 * Can be used to filter out some client-only parameters, or to set proper data types, etc.
	 *
	 * If {@link _parseCallback} is set, it will be used, otherwise {@link parseDefault} will be used.
	 *
	 * @param mixed $data Raw data fetched from request
	 * @return array Final data to be processed by action
	 *
	 * @see _parseCallback
	 * @see parseDefault
	 */
	protected function parse($data) {
		if (($callback = $this->getParseCallback()) !== null) {
			return call_user_func($callback, $data);
		} else {
			return $this->parseDefault($data);
		}
	}

	/**
	 * Basic 'built-in' data parser.
	 *
	 * Mainly, you should override it according to your own data
	 * if you create own action class inherited from this one.
	 *
	 * @param mixed $data
	 * @return array
	 */
	protected function parseDefault($data) {
		return $data;
	}

	// encode/decode :

	/**
	 * @param mixed $data Final data structure to be sent to client
	 * @return string
	 */
	protected function encode($data) {
		return call_user_func($this->getEncodeCallback(), $data);
	}

	/**
	 * @param mixed $data Raw data fetched from request
	 * @return mixed
	 */
	protected function decode($data) {
		return call_user_func($this->getDecodeCallback(), $data);
	}

	// access to current request :

	/**
	 * Shortcut to access current request
	 * @return CHttpRequest
	 */
	protected function getRequest() {
		return Yii::app()->getRequest();
	}

	/**
	 * @throws CHttpException if current request is invalid (according to current action logic)
	 * @see validateRequest
	 */
	protected function ensureValidRequest() {
		if (!$this->validateRequest()) {
			throw new CHttpException(400, 'Request is not allowed');
		}
	}

	/**
	 * Specifies whether current request should be processed by this action.
	 * By default it is assumed that only ajax requests should be processed.
	 *
	 * @return bool whether current request is valid
	 */
	protected function validateRequest() {
		return $this->getRequest()->getIsAjaxRequest();
	}

	/**
	 * Check for PATCH request, in same way as it is done for other requests type in Yii.
	 * Required, because Backbone uses PATCH request while Yii does not provide any method to recognize it.
	 *
	 * @return bool
	 */
	protected function getIsPatchRequest() {
		return !strcasecmp($this->getRequest()->getRequestType(), 'PATCH');
	}


	/**
	 * @return bool Whether received in request data is a JSON string.
	 */
	protected function getIsJSON() {
		return isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;
	}

	// Get / Set :

	/**
	 * @param callable $composeCallback
	 * @throws CException
	 */
	public function setComposeCallback($composeCallback) {
		if (!is_callable($composeCallback)) {
			throw new CException(__METHOD__ . ': "composeCallback" must be a callable');
		}
		$this->_composeCallback = $composeCallback;
	}

	/**
	 * @return callable
	 */
	public function getComposeCallback() {
		return $this->_composeCallback;
	}


	/**
	 * @param callable $parseCallback
	 * @throws CException
	 */
	public function setParseCallback($parseCallback) {
		if (!is_callable($parseCallback)) {
			throw new CException(__METHOD__ . ': "parseCallback" must be a callable');
		}
		$this->_parseCallback = $parseCallback;
	}

	/**
	 * @return callable
	 */
	public function getParseCallback() {
		return $this->_parseCallback;
	}

	/**
	 * @param callable $decodeCallback
	 * @throws CException
	 */
	public function setDecodeCallback($decodeCallback) {
		if (!is_callable($decodeCallback)) {
			throw new CException(__METHOD__ . ': "decodeCallback" must be a callable');
		}
		$this->_decodeCallback = $decodeCallback;
	}

	/**
	 * @return callable
	 */
	public function getDecodeCallback() {
		return $this->_decodeCallback;
	}

	/**
	 * @param callable $encodeCallback
	 * @throws CException
	 */
	public function setEncodeCallback($encodeCallback) {
		if (!is_callable($encodeCallback)) {
			throw new CException(__METHOD__ . ': "encodeCallback" must be a callable');
		}
		$this->_encodeCallback = $encodeCallback;
	}

	/**
	 * @return callable
	 */
	public function getEncodeCallback() {
		return $this->_encodeCallback;
	}
}