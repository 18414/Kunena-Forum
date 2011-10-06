<?php
/**
 * Kunena Component
 * @package Kunena.Framework
 * @subpackage Forum.Category
 *
 * @copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Kunena Forum Category Class
 */
class KunenaForumCategory extends KunenaDatabaseObject {
	public $id = null;
	public $level = 0;

	protected $_channels = false;
	protected $_topics = false;
	protected $_posts = false;
	protected $_lastid = false;
	protected $_authcache = array();
	protected $_authfcache = array();
	protected $_new = 0;
	protected $_table = 'KunenaCategories';
	protected static $actions = array(
			'none'=>array(),
			'read'=>array('Read'),
			'subscribe'=>array('Read', 'CatSubscribe', 'NotBanned', 'NotSection'),
			'moderate'=>array('Read', 'NotBanned', 'Moderate'),
			'admin'=>array('Read', 'NotBanned', 'Admin'),
			'topic.read'=>array('Read'),
			'topic.create'=>array('Read', 'GuestWrite', 'NotBanned', 'NotSection', 'Unlocked', 'Channel'),
			'topic.reply'=>array('Read', 'GuestWrite', 'NotBanned', 'NotSection', 'Unlocked'),
			'topic.edit'=>array('Read', 'NotBanned', 'Unlocked'),
			'topic.move'=>array('Read', 'NotBanned', 'Moderate', 'Channel'),
			'topic.approve'=>array('Read','NotBanned', 'Moderate'),
			'topic.delete'=>array('Read', 'NotBanned', 'Unlocked'),
			'topic.undelete'=>array('Read', 'NotBanned', 'Moderate'),
			'topic.permdelete'=>array('Read', 'NotBanned', 'Admin'),
			'topic.favorite'=>array('Read','NotBanned', 'Favorite'),
			'topic.subscribe'=>array('Read','NotBanned', 'Subscribe'),
			'topic.sticky'=>array('Read','NotBanned', 'Moderate'),
			'topic.lock'=>array('Read','NotBanned', 'Moderate'),
			'topic.poll.read'=>array('Read', 'Poll'),
			'topic.poll.create'=>array('Read', 'GuestWrite', 'NotBanned', 'Unlocked', 'Poll'),
			'topic.poll.edit'=>array('Read', 'NotBanned', 'Unlocked', 'Poll'),
			'topic.poll.delete'=>array('Read', 'NotBanned', 'Unlocked', 'Poll'),
			'topic.poll.vote'=>array('Read', 'NotBanned', 'Unlocked', 'Poll'),
			'topic.post.read'=>array('Read'),
			'topic.post.reply'=>array('Read', 'GuestWrite', 'NotBanned', 'NotSection', 'Unlocked'),
			'topic.post.thankyou' =>array('Read', 'NotBanned'),
			'topic.post.unthankyou' =>array('Read', 'NotBanned', 'Admin'),
			'topic.post.edit'=>array('Read', 'NotBanned', 'Unlocked'),
			'topic.post.move'=>array('Read', 'NotBanned', 'Moderate', 'Channel'),
			'topic.post.approve'=>array('Read', 'NotBanned', 'Moderate'),
			'topic.post.delete'=>array('Read', 'NotBanned', 'Unlocked'),
			'topic.post.undelete'=>array('Read', 'NotBanned', 'Moderate'),
			'topic.post.permdelete'=>array('Read', 'NotBanned', 'Admin'),
			'topic.post.attachment.read'=>array('Read'),
			'topic.post.attachment.create'=>array('Read', 'GuestWrite', 'NotBanned', 'Unlocked', 'Upload'),
			'topic.post.attachment.delete'=>array('Read', 'NotBanned', 'Unlocked'),
		);

	/**
	 * Returns the global KunenaForumCategory object.
	 *
	 * @param   int  $id  The category id to load.
	 *
	 * @return  KunenaForumCategory
	 */
	static public function getInstance($identifier = null, $reload = false) {
		return KunenaForumCategoryHelper::get($identifier, $reload);
	}

	/**
	 * Returns list of children from this category.
	 *
	 * @param   int    $levels  How many levels to search.
	 *
	 * @return  array  List of KunenaForumCategory objects.
	 */
	public function getChildren($levels = 0) {
		return KunenaForumCategoryHelper::getChildren($this->id, $levels);
	}

	/**
	 * Returns object containing user information from this category.
	 *
	 * @param mixed $user
	 * @return KunenaForumCategoryUser
	 */
	public function getUserInfo($user = null) {
		return KunenaForumCategoryUserHelper::get($this->id, $user);
	}

	/**
	 * Returns new topics count from this category for current user.
	 *
	 * @todo Currently new topics needs to be calculated manually, make it automatic.
	 *
	 * @param mixed $count Internal parameter to set new count.
	 * @return bool New topics count.
	 */
	public function getNewCount($count = null) {
		if ($count !== null) $this->_new = $count;
		return $this->_new;
	}

	public function isSection() {
		$this->buildInfo();
		return $this->parent_id == 0 || (!$this->numTopics && $this->locked && empty($this->_channels['none']));
	}

	public function getUrl($category = null, $xhtml = true, $action = null) {
		if (!$category) $category = $this;
		$uri = JURI::getInstance("index.php?option=com_kunena&view=category&catid={$category->id}");
		if ((string)$action === (string)(int)$action) {
			$uri->setVar('limitstart', $action);
		}
		return $xhtml==='object' ? $uri : KunenaRoute::_($uri, $xhtml);
	}

	public function getTopics() {
		$this->buildInfo();
		return $this->_topics;
	}

	public function getPosts() {
		$this->buildInfo();
		return $this->_posts;
	}

	public function getLastCategory() {
		$this->buildInfo();
		return KunenaForumCategoryHelper::get($this->_lastid);
	}

	public function getLastTopic() {
		return KunenaForumTopicHelper::get($this->getLastCategory()->last_topic_id);
	}

	public function getLastPostLocation($direction = 'asc', $hold = null) {
		if (!KunenaUserHelper::getMyself()->isModerator($this->id)) return $direction = 'asc' ? $this->last_topic_posts-1 : 0;
		return KunenaForumMessageHelper::getLocation($this->last_post_id, $direction, $hold);
	}

	public function getChannels($action='read') {
		KUNENA_PROFILER ? KunenaProfiler::instance()->start('function '.__CLASS__.'::'.__FUNCTION__.'()') : null;
		if ($this->_channels === false) {
			$this->_channels['none'] = array();
			if (!$this->published || $this->parent_id == 0 || (!$this->numTopics && $this->locked)) {
				// Unpublished categories and sections do not have channels
			} elseif (empty($this->channels) || $this->channels == $this->id) {
				// No channels defined
				$this->_channels['none'][$this->id] = $this;
			} else {
				// Fetch all channels
				$ids = array_flip(explode(',', $this->channels));
				if (isset($ids[0]) || isset($ids['THIS'])) {
					// Handle current category
					$this->_channels['none'][$this->id] = $this;
				}
				if (!empty($ids)) {
					// More category channels
					$this->_channels['none'] += KunenaForumCategoryHelper::getCategories(array_keys($ids), null, 'none');
				}
				if (isset($ids['CHILDREN'])) {
					// Children category channels
					$this->_channels['none'] += KunenaForumCategoryHelper::getChildren($this->id, 1, array($action=>'none'));
				}
			}
		}
		if (!isset($this->_channels[$action])) {
			$this->_channels[$action] = array();
			foreach ($this->_channels['none'] as $channel) {
				if (($channel->id == $this->id && $action == 'read') || $channel->authorise($action, null, false))
					$this->_channels[$action][$channel->id] = $channel;
			}
		}
		KUNENA_PROFILER ? KunenaProfiler::instance()->stop('function '.__CLASS__.'::'.__FUNCTION__.'()') : null;
		return $this->_channels[$action];
	}

	public function getNewTopicCategory($user=null) {
		foreach ($this->getChannels() as $category) {
			if ($category->authorise('topic.create', $user, true)) return $category;
		}
		if ($this->exists() && $this->isSection()) return new KunenaForumCategory();
		$categories = KunenaForumCategoryHelper::getChildren(intval($this->id), -1, array('action'=>'topic.create', 'parents'=>false));

		if ($categories) return reset($categories);
		return new KunenaForumCategory();
	}

	public function newTopic(array $fields=null, $user=null) {
		$catid = $this->getNewTopicCategory()->id;
		$user = KunenaUserHelper::get($user);
		$message = new KunenaForumMessage();
		$message->catid = $catid;
		$message->name = $user->getName('');
		$message->userid = $user->userid;
		$message->ip = !empty($_SERVER ['REMOTE_ADDR']) ? $_SERVER ['REMOTE_ADDR'] : '';
		$message->hold = $this->review ? (int)!$this->authorise ('moderate', $user, true) : 0;
		$message->bind($fields, array ('name', 'email', 'subject', 'message'), true);

		$topic = new KunenaForumTopic();
		$topic->category_id = $catid;
		$topic->hold = $message->hold;
		$topic->bind($fields, array ('subject','icon_id'), true);

		$message->setTopic($topic);
		return array($topic, $message);
	}

	public function getParent() {
		$parent = KunenaForumCategoryHelper::get($this->parent_id);
		if (!$parent->exists()) {
			$parent->name = JText::_ ( 'COM_KUNENA_TOPLEVEL' );
			$parent->_exists = true;
		}
		return $parent;
	}

	public function authorise($action='read', $user=null, $silent=false) {
		if ($action == 'none') return true;
		KUNENA_PROFILER ? KunenaProfiler::instance()->start('function '.__CLASS__.'::'.__FUNCTION__.'()') : null;
		if ($user === null) {
			$user = KunenaUserHelper::getMyself();
		} elseif (!($user instanceof KunenaUser)) {
			$user = KunenaUserHelper::get($user);
		}

		if (empty($this->_authcache[$user->userid][$action])) {
			if (!isset(self::$actions[$action])) {
				JError::raiseError(500, JText::sprintf ( 'COM_KUNENA_LIB_AUTHORISE_INVALID_ACTION', $action ) );
				KUNENA_PROFILER ? KunenaProfiler::instance()->stop('function '.__CLASS__.'::'.__FUNCTION__.'()') : null;
				return false;
			}

			$this->_authcache[$user->userid][$action] = null;
			foreach (self::$actions[$action] as $function) {
				if (!isset($this->_authfcache[$user->userid][$function])) {
					$authFunction = 'authorise'.$function;
					$this->_authfcache[$user->userid][$function] = $this->$authFunction($user);
				}
				$error = $this->_authfcache[$user->userid][$function];
				if ($error) {
					$this->_authcache[$user->userid][$action] = $error;
					break;
				}
			}
		}
		$error = $this->_authcache[$user->userid][$action];
		if ($silent === false && $error) $this->setError ( $error );

		KUNENA_PROFILER ? KunenaProfiler::instance()->stop('function '.__CLASS__.'::'.__FUNCTION__.'()') : null;
		if ($silent !== null) $error = !$error;
		return $error;
	}

	/**
	 * Get users, who can administrate this category
	 **/
	public function getAdmins($includeGlobal = true) {
		$access = KunenaFactory::getAccessControl();
		$userlist = array();
		if (!empty($this->catid)) $userlist = $access->getAdmins($this->catid);
		if ($includeGlobal) $userlist += $access->getAdmins();
		return $userlist;
	}

	/**
	 * Get users, who can moderate this category
	 **/
	public function getModerators($includeGlobal = true, $objects = true) {
		$access = KunenaFactory::getAccessControl();
		$userlist = array();
		if (!empty($this->id)) {
			$userlist += $access->getModerators($this->id);
		}
		if ($includeGlobal) {
			$userlist += $access->getModerators();
		}
		if (empty($userlist)) return $userlist;
		$userlist = array_keys($userlist);
		return $objects ? KunenaUserHelper::loadUsers($userlist) : array_combine($userlist, $userlist);
	}

	/**
	 * Change user status in category moderators
	 *
	 * @param $user
	 * @example if ($category->authorise('admin')) $category->setModerator($user);
	 **/
	public function setModerator($user = null, $value = false) {
		return KunenaFactory::getAccessControl()->setModerator($this, $user, $value);
	}

	/**
	 * Add user to category moderators
	 *
	 * @param $user
	 * @example if ($category->authorise('admin')) $category->addModerator($user);
	 **/
	public function addModerator($user = null) {
		return $this->setModerator($user, true);
	}

	/**
	 * Remove user from category moderators
	 *
	 * @param $user
	 * @example if ($category->authorise('admin')) $category->removeModerator($user);
	 **/
	public function removeModerator($user = null) {
		return $this->setModerator($user, false);
	}

	/**
	 * (non-PHPdoc)
	 * @see KunenaDatabaseObject::bind()
	 */
	public function bind(array $src = null, array $fields = null, $include = false) {
		if (isset($src['channels']) && is_array($src['channels'])) $src['channels'] = implode(',', $src['channels']);
		return parent::bind($src, $fields, $include);
	}

	/**
	 * (non-PHPdoc)
	 * @see KunenaDatabaseObject::load()
	 */
	public function load($id = null) {
		$exists = parent::load($id);

		// Register category if it exists
		if ($exists) KunenaForumCategoryHelper::register($this);
		return $exists;
	}

	/**
	 * (non-PHPdoc)
	 * @see KunenaDatabaseObject::saveInternal()
	 */
	protected function saveInternal() {
		// Reorder categories
		$table = $this->getTable ();
		$table->bind ( $this->getProperties () );
		$table->exists ( $this->_exists );
		$table->reorder ();

		// Clear cache
		$access = KunenaFactory::getAccessControl();
		$access->clearCache();
		$cache = JFactory::getCache('com_kunena', 'output');
		$cache->clean('categories');

		return true;
	}

	/**
	 * Method to purge old topics from the category
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since 1.6
	 */
	public function purge($time, $limit = 1000) {
		if (!$this->exists()) {
			return true;
		}

		$db = JFactory::getDBO ();
		$query ="SELECT id FROM #__kunena_topics WHERE last_post_time < {$time} ORDER BY last_post_time ASC";
		$db->setQuery($query, 0, $limit);
		$ids = $db->loadResultArray();
		KunenaError::checkDatabaseError ();
		if (empty($ids)) return true;

		$idlist = implode(',', $ids);
		// Delete user topics
		$queries[] = "DELETE FROM #__kunena_user_topics WHERE topic_id IN ({$idlist})";
		// Delete user read
		$queries[] = "DELETE FROM #__kunena_user_read WHERE topic_id IN ({$idlist})";
		// Delete thank yous
		$queries[] = "DELETE t FROM #__kunena_thankyou AS t INNER JOIN #__kunena_messages AS m ON m.id=t.postid WHERE m.thread IN ({$idlist})";
		// Delete poll users
		$queries[] = "DELETE p FROM #__kunena_polls_users AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.pollid WHERE tt.topic_id IN ({$idlist}) AND tt.moved_id=0";
		// Delete poll options
		$queries[] = "DELETE p FROM #__kunena_polls_options AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.pollid WHERE tt.topic_id IN ({$idlist}) AND tt.moved_id=0";
		// Delete polls
		$queries[] = "DELETE p FROM #__kunena_polls AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.id WHERE tt.topic_id IN ({$idlist}) AND tt.moved_id=0";
		// Delete messages
		$queries[] = "DELETE m, t FROM #__kunena_messages AS m INNER JOIN #__kunena_messages_text AS t ON m.id=t.mesid WHERE m.thread IN ({$idlist})";
		// TODO: delete attachments
		// TODO: delete keywords
		// Delete topics
		$queries[] = "DELETE FROM #__kunena_topics WHERE id IN ({$idlist})";

		foreach ($queries as $query) {
			$db->setQuery($query);
			$db->query();
			KunenaError::checkDatabaseError ();
		}

		KunenaUserHelper::recount();
		KunenaForumCategoryHelper::recount($this->id);

		return true;
	}

	/**
	 * Method to delete the KunenaForumCategory object from the database
	 *
	 * @access	public
	 * @return	boolean	True on success
	 */
	public function delete() {
		if (!$this->exists()) {
			return true;
		}

		if (!parent::delete()) {
			return false;
		}

		$access = KunenaFactory::getAccessControl();
		$access->clearCache();

		$db = JFactory::getDBO ();
		// Delete user topics
		$queries[] = "DELETE FROM #__kunena_user_topics WHERE category_id={$db->quote($this->id)}";
		// Delete user categories
		$queries[] = "DELETE FROM #__kunena_user_categories WHERE category_id={$db->quote($this->id)}";
		// Delete user read
		$queries[] = "DELETE FROM #__kunena_user_read WHERE category_id={$db->quote($this->id)}";
		// Delete thank yous
		$queries[] = "DELETE t FROM #__kunena_thankyou AS t INNER JOIN #__kunena_messages AS m ON m.id=t.postid WHERE m.catid={$db->quote($this->id)}";
		// Delete poll users
		$queries[] = "DELETE p FROM #__kunena_polls_users AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.pollid WHERE tt.category_id={$db->quote($this->id)} AND tt.moved_id=0";
		// Delete poll options
		$queries[] = "DELETE p FROM #__kunena_polls_options AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.pollid WHERE tt.category_id={$db->quote($this->id)} AND tt.moved_id=0";
		// Delete polls
		$queries[] = "DELETE p FROM #__kunena_polls AS p INNER JOIN #__kunena_topics AS tt ON tt.poll_id=p.id WHERE tt.category_id={$db->quote($this->id)} AND tt.moved_id=0";
		// Delete messages
		$queries[] = "DELETE m, t FROM #__kunena_messages AS m INNER JOIN #__kunena_messages_text AS t ON m.id=t.mesid WHERE m.catid={$db->quote($this->id)}";
		// TODO: delete attachments
		// TODO: delete keywords
		// Delete topics
		$queries[] = "DELETE FROM #__kunena_topics WHERE category_id={$db->quote($this->id)}";

		foreach ($queries as $query) {
			$db->setQuery($query);
			$db->query();
			KunenaError::checkDatabaseError ();
		}

		KunenaUserHelper::recount();

		$this->id = null;
		KunenaForumCategoryHelper::register($this);
		return true;
	}

	/**
	 * Method to check out the KunenaForumCategory object
	 *
	 * @access	public
	 * @param	integer	$who
	 * @return	boolean	True if checked out by somebody else
	 * @since 1.6
	 */
	public function checkout($who) {
		if (!$this->_exists)
			return false;

		// Create the user table object
		$table = &$this->getTable ();
		$table->bind ( $this->getProperties () );
		$table->exists ( $this->_exists );
		$result = $table->checkout($who);

		// Assuming all is well at this point lets bind the data
		$this->setProperties ( $table->getProperties () );

		$cache = JFactory::getCache('com_kunena', 'output');
		$cache->clean('categories');

		return $result;
	}

	/**
	 * Method to check in the KunenaForumCategory object
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since 1.6
	 */
	public function checkin() {
		if (!$this->_exists)
			return true;

		// Create the user table object
		$table = $this->getTable ();
		$table->bind ( $this->getProperties () );
		$table->exists ( $this->_exists );
		$result = $table->checkin();

		// Assuming all is well at this point lets bind the data
		$this->setProperties ( $table->getProperties () );

		$cache = JFactory::getCache('com_kunena', 'output');
		$cache->clean('categories');

		return $result;
	}

	/**
	 * Method to check if an item is checked out
	 *
	 * @access	public
	 * @return	boolean	True on success
	 * @since 1.6
	 */
	public function isCheckedOut($with) {
		if (!$this->_exists)
			return false;

		// Create the user table object
		$table = $this->getTable ();
		$table->bind ( $this->getProperties () );
		$table->exists ( $this->_exists );
		$result = $table->isCheckedOut($with);
		return $result;
	}

	public function update($topic, $topicdelta=0, $postdelta=0) {
		if (!$topic->id) return false;

		$update = false;
		if ($topicdelta || $postdelta) {
			// Update topic and post count
			$this->numTopics += (int)$topicdelta;
			$this->numPosts += (int)$postdelta;
			$update = true;
		}
		if ($topic->exists() && $topic->hold==0 && $topic->moved_id==0 && $topic->category_id==$this->id
			&& ($this->last_post_time<$topic->last_post_time || ($this->last_post_time==$topic->last_post_time && $this->last_post_id <= $topic->last_post_id))) {
			// If topic has new post or last topic changed, we need to update cache
			$this->last_topic_id = $topic->id;
			$this->last_topic_posts = $topic->posts;
			$this->last_topic_subject = $topic->subject;
			$this->last_post_id = $topic->last_post_id;
			$this->last_post_time = $topic->last_post_time;
			$this->last_post_userid = $topic->last_post_userid;
			$this->last_post_message = $topic->last_post_message;
			$this->last_post_guest_name = $topic->last_post_guest_name;
			$update = true;
		} elseif ($this->last_topic_id == $topic->id) {
			// If last topic/post got moved or deleted, we need to find last post
			$db = JFactory::getDBO ();
			$query = "SELECT * FROM #__kunena_topics WHERE category_id={$db->quote($this->id)} AND hold=0 AND moved_id=0 ORDER BY last_post_time DESC, last_post_id DESC";
			$db->setQuery ( $query, 0, 1 );
			$topic = $db->loadObject ();
			KunenaError::checkDatabaseError ();

			if ($topic) {
				$this->last_topic_id = $topic->id;
				$this->last_topic_posts = $topic->posts;
				$this->last_topic_subject = $topic->subject;
				$this->last_post_id = $topic->last_post_id;
				$this->last_post_time = $topic->last_post_time;
				$this->last_post_userid = $topic->last_post_userid;
				$this->last_post_message = $topic->last_post_message;
				$this->last_post_guest_name = $topic->last_post_guest_name;
				$update = true;
			} else {
				$this->numTopics = 0;
				$this->numPosts = 0;
				$this->last_topic_id = 0;
				$this->last_topic_posts = 0;
				$this->last_topic_subject = '';
				$this->last_post_id = 0;
				$this->last_post_time = 0;
				$this->last_post_userid = 0;
				$this->last_post_message = '';
				$this->last_post_guest_name = '';
				$update = true;
			}
		}
		if (!$update) return true;

		return $this->save();
	}

	// Internal functions

	protected function buildInfo() {
		if ($this->_topics !== false)
			return;
		$this->_topics = 0;
		$this->_posts = 0;
		$this->_lastid = 0;
		$categories = $this->getChannels();
		$categories += KunenaForumCategoryHelper::getChildren($this->id);
		foreach ($categories as $category) {
			$category->buildInfo();
			$this->_topics += $category->numTopics;
			$this->_posts += $category->numPosts;
			if (KunenaForumCategoryHelper::get($this->_lastid)->last_post_time < $category->last_post_time)
				$this->_lastid = $category->id;
		}
	}

	protected function authoriseRead($user) {
		static $catids = false;
		if ($catids === false) {
			$catids = KunenaFactory::getAccessControl ()->getAllowedCategories ( $user, 'read' );
		}

		// Checks if user can read category
		if (!$this->exists()) {
			return JText::_ ( 'COM_KUNENA_NO_ACCESS' );
		}
		if (empty($catids[0]) && empty($catids[$this->id])) {
			return JText::_ ( 'COM_KUNENA_NO_ACCESS' );
		}
	}
	protected function authoriseNotBanned($user) {
		$banned = $user->isBanned();
		if ($banned) {
			$banned = KunenaUserBan::getInstanceByUserid($user->userid, true);
			if (!$banned->isLifetime()) {
				return JText::sprintf ( 'COM_KUNENA_POST_ERROR_USER_BANNED_NOACCESS_EXPIRY', KunenaDate::getInstance($banned->expiration)->toKunena());
			} else {
				return JText::_ ( 'COM_KUNENA_POST_ERROR_USER_BANNED_NOACCESS' );
			}
		}
	}
	protected function authoriseGuestWrite($user) {
		// Check if user is guest and they can create or reply topics
		if ($user->userid == 0 && !KunenaFactory::getConfig()->pubwrite) {
			return JText::_ ( 'COM_KUNENA_POST_ERROR_ANONYMOUS_FORBITTEN' );
		}
	}
	protected function authoriseSubscribe($user) {
		// Check if user is guest and they can create or reply topics
		$config = KunenaFactory::getConfig();
		if ($user->userid == 0 || !$config->allowsubscriptions || $config->topic_subscriptions == 'disabled') {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_SUBSCRIPTIONS' );
		}
	}
	protected function authoriseCatSubscribe($user) {
		// Check if user is guest and they can create or reply topics
		$config = KunenaFactory::getConfig();
		if ($user->userid == 0 || !$config->allowsubscriptions || $config->category_subscriptions == 'disabled') {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_SUBSCRIPTIONS' );
		}
	}
	protected function authoriseFavorite($user) {
		// Check if user is guest and they can create or reply topics
		if ($user->userid == 0 || !KunenaFactory::getConfig()->allowfavorites) {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_FAVORITES' );
		}
	}
	protected function authoriseNotSection($user) {
		// Check if category is not a section
		if ($this->isSection()) {
			return JText::_ ( 'COM_KUNENA_POST_ERROR_IS_SECTION' );
		}
	}
	protected function authoriseChannel($user) {
		// Check if category is alias
		$channels = $this->getChannels('none');
		if (!isset($channels[$this->id])) {
			return JText::_ ( 'COM_KUNENA_POST_ERROR_IS_ALIAS' );
		}
	}
	protected function authoriseUnlocked($user) {
		// Check that category is not locked or that user is a moderator
		if ($this->locked && (!$user->userid || !$user->isModerator($this->id))) {
			return JText::_ ( 'COM_KUNENA_POST_ERROR_CATEGORY_LOCKED' );
		}
	}
	protected function authoriseModerate($user) {
		// Check that user is moderator
		if (!$user->userid || !$user->isModerator($this->id)) {
			return JText::_ ( 'COM_KUNENA_POST_NOT_MODERATOR' );
		}
	}
	protected function authoriseAdmin($user) {
		// Check that user is admin
		if (!$user->userid || !$user->isAdmin($this->id)) {
			return JText::_ ( 'COM_KUNENA_MODERATION_ERROR_NOT_ADMIN' );
		}
	}

	protected function authorisePoll($user) {
		// Check if polls are enabled at all
		if (!KunenaFactory::getConfig()->pollenabled) {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_POLLS_DISABLED' );
		}
		// Check if polls are not enabled in this category
		if (!$this->allow_polls) {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_POLLS_NOT_ALLOWED' );
		}
	}

	protected function authoriseUpload($user) {
		// Check if attachments are allowed
		if (KunenaForumMessageAttachmentHelper::getExtensions($this, $user) === false) {
			return JText::_ ( 'COM_KUNENA_LIB_CATEGORY_AUTHORISE_FAILED_UPLOAD_NOT_ALLOWED' );
		}
	}
}