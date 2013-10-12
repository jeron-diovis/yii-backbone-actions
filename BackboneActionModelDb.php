<?php

/**
 * BackboneActionModelDb class file
 *
 * @author Jeron Diovis <void.jeron.diovis@gmail.com>
 * @package yii.backbone.actions
 * @license MIT
 */

/**
 * Class BackboneActionModelDb
 *
 * This class implements API to map Backbone models to CActiveRecord models.
 *
 * Note, that all methods which accepts a model, are NOT strongly typed for CActiveRecord.
 * It is done to allow you to use with this action some other ORM, which satisfies to CActiveRecord interface.
 */
class BackboneActionModelDb extends AbstractBackboneActionModel {

	/**
	 * @var bool Whether GET requests without 'id' param must be recognized as requests for fetching entire collection.
	 * It is used, when your Backbone collection has same url as it's model's urlRoot (the default and most suitable scenario for ActiveRecord).
	 *
	 * If this property is false, 400 error will be returned for GEt request without 'id' param.
	 *
	 * @see fetch
	 */
	protected $_allowFetchCollection = true;

	/**
	 * @var array|CDbCriteria Criteria to applied to all db queries
	 *
	 * @see composeSearchCriteria
	 */
	protected $_searchCriteria = array();

	/**
	 * @var callable Allows to set dynamic criteria, which, for example, depends on current request params
	 * Callback must return array or CDbCriteria
	 *
	 * @see composeSearchCriteria
	 */
	protected $_searchCriteriaCallback = null;

	/**
	 * @var string|boolean Second parameter to be passed to {@link CDbCriteria::mergeWith}
	 * Not sure it is really required, but let it be. Just in case.
	 *
	 * @see composeSearchCriteria
	 */
	protected $_searchCriteriaCallbackMergeOperator = 'AND';

	/**
	 * @var bool Whether to use built-in helper for composing dynamic filtration criteria.
	 *
	 * Actually, it provides a default implementation of {@link _searchCriteriaCallback}, which will try to
	 * get from request special parameter, specified by {@link filterParamName}, and parse it with some pre-defined rules.
	 *
	 * It was designed strictly for CDbCriteria, so if you use another ORM, you should completely override corresponding methods.
	 *
	 * @see composeFiltrationCriteria
	 * @see composeSingleFilterCondition
	 */
	protected $_useFiltrationCriteria = true;

	/**
	 * @var string Specifies under which parameter is stored filtration criteria config in request.
	 *
	 * @see composeSearchCriteria
	 */
	protected $_filterParamName = 'filter';

	/**
	 * @overridden to use existing model's attributes by default
	 *
	 * @param CActiveRecord $model
	 * @return mixed
	 */
	protected function composeDefault($model) {
		return $model->getAttributes();
	}

	// model access :

	/**
	 * @return CActiveRecord
	 */
	protected function getModelFinder() {
		return call_user_func(array($this->getModelClassName(), 'model'));
	}

	/**
	 * @return CActiveRecord
	 */
	protected function createModel() {
		$className = $this->getModelClassName();
		$model = new $className;
		return $model;
	}

	/**
	 * @return CActiveRecord
	 * @throws CHttpException
	 */
	protected function findModel() {
		$finder = $this->getModelFinder();
		$modelId = $this->getModelId();
		$model = $finder->findByPk($modelId, $this->composeSearchCriteria());
		if ($model === null) {
			throw new CHttpException(404, "Model with id '{$modelId}' was not found in database");
		}
		return $model;
	}

	/**
	 * "Template method"
	 *
	 * Flexibly update existing model.
	 *
	 * @param CActiveRecord $model
	 * @return array
	 * @throws CHttpException If model is invalid. Exception contains encoded errors list.
	 *
	 * @see extractServerAttributes
	 * @see applyServerAttributes
	 * @see applyClientAttributes
	 */
	protected function updateModel($model) {
		$data = $this->parse($this->getRawModelData());

		$serverAttributes = $this->extractServerAttributes($data);
		$clientAttributes = array_diff_key($data, $serverAttributes);

		$result = $this->applyServerAttributes($model, $serverAttributes);
		$result = $this->applyClientAttributes($model, $clientAttributes) && $result;
		if (!$result) {
			throw new CHttpException(400, $this->encode($model->getErrors()));
		}
		return $this->compose($model);
	}

	/**
	 * Returns all models from collection, which satisfies to current criteria.
	 * @return CActiveRecord[]
	 */
	protected function fetchCollection() {
		$finder = $this->getModelFinder();
		$models = $finder->findAll($this->composeSearchCriteria());
		return $models;
	}

	// implement contract :

	/**
	 * Founds existing model by id, or fetches entire models collection
	 * @return array
	 * @throws CHttpException
	 */
	protected function fetch() {
		if ($this->getModelId() !== null) {
			return $this->compose($this->findModel());
		} elseif ($this->getAllowFetchCollection()) {
			return array_map(
				array($this, 'compose'),
				$this->fetchCollection()
			);
		} else {
			throw new CHttpException(400, 'Model id must be specified');
		}
	}

	/**
	 * Removes existing model
	 * @return boolean success
	 */
	protected function delete() {
		$model = $this->findModel();
		$result = $model->delete(); // do not use 'deleteByPk', to trigger beforeDelete/afterDelete events
		return $result;
	}

	/**
	 * Creates a new model
	 * @return array All attributes of created model - as it is expected by default Backbone.Model.save method
	 */
	protected function save() {
		return $this->updateModel($this->createModel());
	}

	/**
	 * Updates existing model
	 * @return array All attributes of updated model - as it is expected by default Backbone.Model.save method
	 */
	protected function update() {
		return $this->updateModel($this->findModel());
	}

	// utils:

	/**
	 * Allows you to separate model's own (db-mapped) attributes from specific client-only attributes, which must processed separately.
	 *
	 * Method should return a list of attributes which are directly mapped to attributes of this action's model (key=>value) - it will be passed to {@link applyServerAttributes} method.
	 * The rest of attributes from incoming $data array will be passed to {@link applyClientAttributes} method.
	 *
	 * Example: client-side Backbone model represents a comment, and has an attribute 'isLiked', which defines whether current user has liked this comment.
	 * But in db each like is stored as separate record, in separate table/collection/etc.
	 * In this case 'isLiked' is called 'client-only' attribute, and must be processed by {@link applyClientAttributes} method.
	 *
	 * @param array $data Full list of received model's attributes (attrName => attrValue)
	 * @return array list of attributes which are directly mapped to attributes of this action's model
	 *
	 * @see updateModel
	 * @see applyServerAttributes
	 */
	protected function extractServerAttributes(array $data) {
		return $data;
	}

	/**
	 * Assigns given attributes to model and tries to save model.
	 *
	 * @param CActiveRecord $model
	 * @param array $data List of attributes to be saved
	 * @return bool Success
	 *
	 * @see updateModel
	 */
	protected function applyServerAttributes($model, array $data) {
		if ($data === array()) {
			return true;
		}

		foreach ($data as $name => $value) {
			$model->$name = $value;
		}
		$result = $model->save(true);
		return $result;
	}

	/**
	 * A stub for client-only attributes processing method.
	 * You should override it for your own data.
	 *
	 * @param CActiveRecord $model
	 * @param array $data List of attributes to be applied
	 * @return bool
	 *
	 * @see updateModel
	 * @see extractServerAttributes
	 */
	protected function applyClientAttributes($model, array $data) {
		// override
		return true;
	}

	// db criteria:

	/**
	 * @return CDbCriteria
	 */
	protected function composeSearchCriteria() {
		$criteria = $this->getSearchCriteria();
		if (!is_object($criteria)) {
			$criteria = new CDbCriteria($criteria);
		}

		$criteriaCallback = $this->getSearchCriteriaCallback();
		if ($criteriaCallback !== null) {
			$criteria->mergeWith(
				call_user_func($criteriaCallback, $this->getRequest()),
				$this->getSearchCriteriaCallbackMergeOperator()
			);
		}

		if ($this->getUseFiltrationCriteria()) {
			$filter = $this->getRequest()->getQuery($this->getFilterParamName());
			if (!empty($filter)) {
				$criteria->mergeWith($this->composeFiltrationCriteria($filter)); // merged with 'AND' operator always
			}
		}

		return $criteria;
	}


	/**
	 * Create a filtration criteria based on parameters, passed in request.
	 *
	 * @param array $filterParams Configuration for filtration criteria. Must have following structure:
	 * <code>
	 * array(
	 *  'modelAttributeName' => array(
	 *     'value' => mixed // value to be compared
	 *     'operator' => string // can be '=', '<>', '<', '>', '<=', '>=', 'in', 'like'
	 *     // other custom parameters if you need it
	 *  ),
	 *  ...
	 * )
	 * </code>
	 *
	 * @return CDbCriteria
	 * @see composeSingleFilterCondition
	 */
	protected function composeFiltrationCriteria(array $filterParams) {
		$criteria = new CDbCriteria();
		$criteriaParams = array();
		foreach ($filterParams as $attributeName => $params) {
			$operator = $params['operator'];
			$value = $params['value'];
			unset($params['value'], $params['operator']);

			$conditionConfig = $this->composeSingleFilterCondition($attributeName, $operator, $value, $params);
			$criteria->addCondition($conditionConfig['condition']);
			$criteriaParams[] = $conditionConfig['params'];
		}
		$criteria->params = call_user_func_array('array_merge', $criteriaParams);
		return $criteria;
	}

	/**
	 * @param string $column
	 * @param string $operator
	 * @param mixed $value
	 * @param array $params
	 * @return array('condition' => string, 'params' => array(string => mixed))
	 * @throws CHttpException
	 */
	protected function composeSingleFilterCondition($column, $operator, $value, $params) {
		$queryParams = array();

		switch ($operator) {
			case 'in':
				$queryParams = array();
				foreach ($value as $val) {
					$queryParams[CDbCriteria::PARAM_PREFIX . CDbCriteria::$paramCount++] = $val;
				}
				$condition = $column . ' IN (' . implode(', ', array_keys($queryParams)) . ')';
				break;

			case '=':
			case '<':
			case '>':
			case '<=':
			case '>=':
			case '<>':
				$condition = $column . $operator . CDbCriteria::PARAM_PREFIX . CDbCriteria::$paramCount;
				$queryParams[CDbCriteria::PARAM_PREFIX . CDbCriteria::$paramCount++] = $value;
				break;

			case 'like':
				// copies algorithm of 'CDbCriteria::addSearchCondition', but allows to configure '%' placeholder at the start and end of keyword:
				$keyword = strtr($value, array('%' => '\%', '_' => '\_', '\\' => '\\\\'));
				if (!$params['likeStartAnchor']) {
					$keyword = '%' . $keyword;
				}
				if (!$params['likeEndAnchor']) {
					$keyword .= '%';
				}

				$condition = $column . " {$operator} " . CDbCriteria::PARAM_PREFIX . CDbCriteria::$paramCount;
				$queryParams[CDbCriteria::PARAM_PREFIX . CDbCriteria::$paramCount++] = $keyword;
				break;

			default:
				throw new CHttpException(400, "Invalid operator: '{$operator}'");
		}

		return array(
			'condition' => $condition,
			'params' => $queryParams,
		);
	}

	// Get / Set :

	/**
	 * @param callable|array $searchCriteria
	 */
	public function setSearchCriteria($searchCriteria) {
		$this->_searchCriteria = $searchCriteria;
	}

	/**
	 * @return callable|array
	 */
	public function getSearchCriteria() {
		return $this->_searchCriteria;
	}

	/**
	 * @param callable $searchCriteriaCallback
	 * @throws CException
	 */
	public function setSearchCriteriaCallback($searchCriteriaCallback) {
		if (!is_callable($searchCriteriaCallback)) {
			throw new CException(__METHOD__ . ': "searchCriteriaCallback" must be a callable');
		}
		$this->_searchCriteriaCallback = $searchCriteriaCallback;
	}

	/**
	 * @return callable
	 */
	public function getSearchCriteriaCallback() {
		return $this->_searchCriteriaCallback;
	}

	/**
	 * @param bool|string $callbackMergeOperator
	 */
	public function setSearchCriteriaCallbackMergeOperator($callbackMergeOperator) {
		$this->_searchCriteriaCallbackMergeOperator = $callbackMergeOperator;
	}

	/**
	 * @return bool|string
	 */
	public function getSearchCriteriaCallbackMergeOperator() {
		return $this->_searchCriteriaCallbackMergeOperator;
	}

	/**
	 * @param boolean $allowFetchCollection
	 */
	public function setAllowFetchCollection($allowFetchCollection) {
		$this->_allowFetchCollection = $allowFetchCollection;
	}

	/**
	 * @return boolean
	 */
	public function getAllowFetchCollection() {
		return $this->_allowFetchCollection;
	}

	/**
	 * @param boolean $useCriteriaFiltrationCallback
	 */
	public function setUseFiltrationCriteria($useCriteriaFiltrationCallback) {
		$this->_useFiltrationCriteria = $useCriteriaFiltrationCallback;
	}

	/**
	 * @return boolean
	 */
	public function getUseFiltrationCriteria() {
		return $this->_useFiltrationCriteria;
	}

	/**
	 * @param string $filterParamName
	 */
	public function setFilterParamName($filterParamName) {
		$this->_filterParamName = $filterParamName;
	}

	/**
	 * @return string
	 */
	public function getFilterParamName() {
		return $this->_filterParamName;
	}
}