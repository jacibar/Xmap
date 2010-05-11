<?php
/**
 * @version       $Id$
 * @copyright     Copyright (C) 2005 - 2009 Joomla! Vargas. All rights reserved.
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 * @author	Guillermo Vargas (guille@vargas.co.cr)
 */

// No direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.modelitem');
jimport('joomla.database.query');
require_once(JPATH_COMPONENT.DS.'helpers'.DS.'xmap.php');

/**
 * Xmap Component Sitemap Model
 *
 * @package		Xmap
 * @subpackage	com_xmap
 * @since 2.0
 */
class XmapModelSitemap extends JModelItem
{
	/**
	 * Model context string.
	 *
	 * @var		string
	 */
	 protected $_context = 'com_xmap.sitemap';

	 protected $_extensions = null;

	/**
	 * Method to auto-populate the model state.
	 *
	 * @return	void
	 */
	protected function populateState()
	{
		$app = &JFactory::getApplication('site');

		// Load state from the request.
		$pk = JRequest::getInt('id');
		$this->setState('sitemap.id', $pk);

		$offset = JRequest::getInt('limitstart');
		$this->setState('list.offset', $offset);

		// Load the parameters.
		$params	= $app->getParams();
		$this->setState('params', $params);

		// TODO: Tune these values based on other permissions.
		$this->setState('filter.published',	1);
		$this->setState('filter.access',		true);
	}

	/**
	 * Method to get sitemap data.
	 *
	 * @param	integer	The id of the article.
	 *
	 * @return	mixed	Menu item data object on success, false on failure.
	 */
	public function &getItem($pk = null)
	{
		// Initialize variables.
        $pk = (!empty($pk)) ? $pk : (int) $this->getState('sitemap.id');

		if ($this->_item === null) {
			$this->_item = array();
		}

		if (!isset($this->_item[$pk]))
		{
			try
			{
                $db = $this->getDbo();
				$query = $db->getQuery(true);

				$query->select($this->getState('item.select', 'a.*'));
				$query->from('#__xmap_sitemap AS a');

				$query->where('a.id = '.(int) $pk);

				// Filter by published state.
				$published = $this->getState('filter.published');
				if (is_numeric($published)) {
					$query->where('a.state = '.(int) $published);
				}

				// Filter by access level.
				if ($access = $this->getState('filter.access'))
				{
					$user	= &JFactory::getUser();
					$groups	= implode(',', $user->authorisedLevels());
					$query->where('a.access IN ('.$groups.')');
				}

				$this->_db->setQuery($query);

				$data = $this->_db->loadObject();

				if ($error = $this->_db->getErrorMsg()) {
                	throw new Exception($error);
				}

				if (empty($data)) {
					throw new Exception(JText::_('Xmap_Error_Sitemap_not_found'));
				}

				// Check for published state if filter set.
				if (is_numeric($published) && $data->state != $published) {
					throw new Exception(JText::_('Xmap_Error_Sitemap_not_found'));
				}

				// Convert parameter fields to objects.
				$registry = new JRegistry('_default');
				$registry->loadJSON($data->attribs);
				$data->params = clone $this->getState('params');
				if (!$data->params->merge($registry)){
					die('cannot merge');
				}

				// Convert the selections field to an array.
				$registry = new JRegistry('_default');
				$registry->loadJSON($data->selections);
				$data->selections = $registry->toArray();

				// Compute access permissions.
				if ($access)
				{
					// If the access filter has been set, we already know this user can view.
					$data->params->set('access-view', true);
				}
				else
				{
					// If no access filter is set, the layout takes some responsibility for display of limited information.
					$user	= &JFactory::getUser();
					$groups	= $user->authorisedLevels();

					$data->params->set('access-view', in_array($data->access, $groups));
				}
				// TODO: Type 2 permission checks?

				$this->_item[$pk] = $data;
			}
			catch (Exception $e)
			{
				$this->setError($e->getMessage());
				$this->_item[$pk] = false;
			}
		}

		return $this->_item[$pk];
	}

	public function getItems()
	{
		$item =& $this->getItem();
		return XmapHelper::getMenuItems($item->selections);
	}


	function getExtensions( ) {
        return XmapHelper::getExtensions();
	}

	private function prepareMenuItem(&$item)
	{
		$extensions =& $this->getExtensions();
		if ( preg_match('#^/?index.php.*option=(com_[^&]+)#',$item->link,$matches) ) {
			$option = $matches[1];
			if ( !empty($extensions[$option]) ) {
				$className = 'xmap_'.$option;
				$obj = new $className;
				if (method_exists($obj,'prepareMenuItem')) {
					$obj->prepareMenuItem($item);
				}
			}
		}
	}

	/**
	 * Increment the hit counter for the sitemap.
	 *
	 * @param	int		Optional primary key of the sitemap to increment.
	 *
	 * @return	boolean	True if successful; false otherwise and internal error set.
	 */
	public function hit( $count )
	{
		// Initialize variables.
		$pk = (int) $this->getState('sitemap.id');

		$view = JRequest::getCmd('view','html');
		if ($view != 'xml' && $view != 'html')  {
			return false;
		}


		$this->_db->setQuery(
			'UPDATE #__xmap_sitemap' .
			' SET views_'.$view.' = views_'.$view.' + 1, count_'.$view.' = ' . $count. ', lastvisit_'.$view.' = '.JFactory::getDate()->toUnix().
			' WHERE id = '.(int) $pk
		);

		if (!$this->_db->query())
		{
			$this->setError($this->_db->getErrorMsg());
			return false;
		}

		return true;
	}

	function chageItemPropery($uid,$itemid,$view,$property,$value)
	{
		$this->loadItems($view,$itemid);
		$db = &JFactory::getDBO();
		$isNew = false;
		if (empty($this->_items[$view][$itemid][$uid])) {
			$this->_items[$view][$itemid][$uid] = array();
			$isNew = true;
		}
		$this->_items[$view][$itemid][$uid][$property]=$value;
		$sep = $properties = '';
		foreach ($this->_items[$view][$itemid][$uid] as $k => $v) {
			$properties .= $sep.$k.'='.$v;
			$sep = ';';
		}
		if (!$isNew) {
			$query = 'UPDATE #__xmap_items SET properties=\''.$db->getEscaped($properties)."' where uid='".$db->getEscaped($uid). "' and itemid=$itemid and view='$view' and sitemap_id=".$this->id;
		} else {
			$query = 'INSERT #__xmap_items (uid,itemid,view,sitemap_id,properties) values ( \''.$db->getEscaped($uid). "',$itemid,'$view',{$this->id},'".$db->getEscaped($properties)."')";
		}
		$db->setQuery($query);
		echo $db->getQuery();
		if ($db->query()) {
			return true;
		} else {
			return false;
		}
	}

	function toggleItem($uid,$itemid)
	{
		$app = &JFactory::getApplication('site');
		$sitemap = $this->getItem();


		$displayer = new XmapDisplayer($app->getParams(),$sitemap);

		$excludedItems = $displayer->getExcludedItems();
		if ( isset($excludedItems[$itemid])) {
			$excludedItems[$itemid] = (array) $excludedItems[$itemid];
		}
		if ( !$displayer->isExcluded($itemid, $uid) ) {
			$excludedItems[$itemid][] = $uid;
			$state = 0;
		} else {
			if (is_array($excludedItems[$itemid]) && count($excludedItems[$itemid])) {
				$excludedItems[$itemid] = array_filter($excludedItems[$itemid],create_function('$var', 'return ($var != \''.$uid.'\');'));
			} else {
				unset($excludedItems[$itemid]);
			}
			$state = 1;
		}

		$registry = new JRegistry('_default');
		$registry->loadArray($excludedItems);
		$str = $registry->toString();
/*
		$sep = $str = '';
		foreach ($excludedItems as $itemid => $items) {
			$str .= $sep."$itemid:".implode(',',$items);
			$sep = ';';
		}
*/
		$db = &JFactory::getDBO();
		$query = "UPDATE #__xmap_sitemap set excluded_items='".$db->getEscaped($str) ."' where id=". $sitemap->id;
		$db->setQuery($query);
		$db->query();
		return $state;
	}
}
