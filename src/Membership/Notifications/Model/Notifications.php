<?php

class Membership_Notifications_Model_Notifications extends Membership_Base_Model_Base
{
	public function createNotification($userId, $type, $target_id = null, $object_id = null) {
		$fields = array('user_id', 'type', 'created_at', 'updated_at');
		$values = array('%d', '%s', '%s', '%s');
        $currentDateTime = $this->getCurrentDateInUTC();
		$queryParams = array($userId, $type, $currentDateTime, $currentDateTime);

		if ($target_id) {
			$fields[] = 'target_id';
			$values[] = '%d';
			$queryParams[] = $target_id;
		}

		if ($object_id) {
			$fields[] = 'object_id';
			$values[] = '%d';
			$queryParams[] = $object_id;
		}

		$query = $this->getQueryBuilder()
			->insertInto($this->getTable('notifications'))
			->fields($fields)
			->values($values)
			->build();

		$this->db->query(
			$this->db->prepare($query, $queryParams)
		);

		$result = $this->db->insert_id;
		$this->setNotificationUserMeta($userId);
        return $result;
	}

	public function getNotifications($currentUserId, $limit, $offsetId = null, $type = null) {
		$typeQuery = '';

		if ($type) {
			$typeQuery = $this->db->prepare(" AND n.category = '%s'", array($type));
		}

		$lastNotificationQuery = '';

		if ($offsetId) {
			$lastNotificationQuery = $this->db->prepare(" AND n.id < '%d'", array($offsetId));
		}

		$query = $this->preparePrefix("
				SELECT 
					n.*,
					n.type
				FROM
					{prefix}notifications AS n
				WHERE n.user_id = '%d' AND (n.target_id OR n.object_id) IS NOT NULL {$typeQuery} {$lastNotificationQuery}
				ORDER BY id DESC
				LIMIT {$limit}
			");

		$notifications = $this->db->get_results($this->db->prepare($query, array($currentUserId)), ARRAY_A);

		return $this->prepareNotificationsRelatedData($notifications, $currentUserId);
	}

	public function prepareNotificationsRelatedData($notifications, $currentUserId) {

		if (!$notifications) {
			return array();
		}

		$relatedData = array(
			'users' => array(),
			'groups' => array(),
		);

		$fetchedData = array(
			'users' => array(),
			'groups' => array(),
		);

		$usersModule = $this->environment->getModule('users');
		$usersModel = $usersModule->getModel('profile');

		foreach ($notifications as $key => $notification) {
			switch ($notification['type']) {
				case 'friendship_request':
				case 'friendship_accept':
				case 'follow':
				case 'message':
					$relatedData['users'][] = $notification['user_id'];
					$relatedData['users'][] = $notification['target_id'];
					break;
				case 'groups_invite':
					$relatedData['users'][] = $notification['user_id'];
					$relatedData['users'][] = $notification['target_id'];
					$relatedData['groups'][] = $notification['object_id'];
					break;
				default:
					$relatedData['users'][] = $notification['user_id'];
					break;
			}
		}

		$notifications = $this->getDispatcher()->apply('notifications.relatedDataPrepare', array($notifications, &$relatedData));

		$users = $usersModel->getUsersByIds(array('users' => array_unique($relatedData['users'])));

		foreach ($users as &$user) {
			$fetchedData['users'][$user['id']] = $user;
		}

		if ($relatedData['groups']) {
			$groupsModel = $this->getModel('groups', 'groups');
			$groups = $groupsModel->getGroup(array_unique($relatedData['groups']), $currentUserId);

			foreach ($groups as &$group) {
				$fetchedData['groups'][$group['id']] = $group;
			}
		}

		foreach ($notifications as $key => &$notification) {
			switch ($notification['type']) {
				case 'friendship_request':
				case 'friendship_accept':
				case 'follow':
				case 'message':
				    if(isset($fetchedData['users'][$notification['user_id']]) && isset($fetchedData['users'][$notification['target_id']])){
                        $notification['user'] = $fetchedData['users'][$notification['user_id']];
                        $notification['target'] = $fetchedData['users'][$notification['target_id']];
                    }
					break;
				case 'groups_invite':
					$notification['user'] = $fetchedData['users'][$notification['user_id']];
					$notification['target'] = $fetchedData['users'][$notification['target_id']];
					$notification['group'] = $fetchedData['groups'][$notification['object_id']];
					break;
				default:
					$notification['user'] = $fetchedData['users'][$notification['user_id']];
					break;
			}
		}

		return $this->getDispatcher()->apply('notifications.relatedData', array($notifications, $fetchedData));
	}

    public function getNotificationsCounts($currentUserId) {

        $query = $this->preparePrefix("
				SELECT 
                    COUNT(n.id) as count,
					n.type
				FROM
					{prefix}notifications AS n
				WHERE n.user_id = '%d' AND n.viewed = 0
				GROUP BY n.type
			");

        $notifications = $this->db->get_results($this->db->prepare($query, array($currentUserId)), ARRAY_A);
        return $notifications;
    }

	public function setViewed($notificationsIds = array()) {
        $query = $this->preparePrefix("
                UPDATE {prefix}notifications
                 SET viewed = 1
                WHERE id IN (" . implode(', ', array_pad(array(), count($notificationsIds), "'%d'")) . ")");

		$result = $this->db->query($this->db->prepare($query, $notificationsIds));
		$this->setNotificationUserMetaByIds($notificationsIds);
		return $result;
	}

    public function setViewedType($userId, $type) {

        $query = $this->preparePrefix("
            UPDATE {prefix}notifications 
            SET viewed = 1
            WHERE user_id = '%d' AND type = '%s'
        ");

        $result = $this->db->query($this->db->prepare($query, array($userId, $type)));
        $this->setNotificationUserMeta($userId);
		return $result;
    }

    public function setViewedTypeGroup($userId, $type, $groupId){
        $query = $this->preparePrefix("
            UPDATE {prefix}notifications 
            SET viewed = 1
            WHERE `user_id` = '%d' AND `type` = '%s' AND `target_id` = '%s'
        ");

        $result = $this->db->query($this->db->prepare($query, array($userId, $type, $groupId)));
        $this->setNotificationUserMeta($userId);
		return $result;
    }

	public function setViewedAll($currentUserId) {
		$query = $this->preparePrefix("UPDATE {prefix}notifications SET viewed = 1 WHERE user_id = '%d'");

		$result = $this->db->query($this->db->prepare($query, array($currentUserId)));
		$this->setNotificationUserMeta($currentUserId);
		return $result;
	}

	public function getNotificationById($notificationId) {
		if (is_array($notificationId)) {

			$queryParams = $notificationId;
			$notificationIds = implode(', ', array_pad(array(), count($notificationId), "'%d'"));

			$query = $this->preparePrefix("
				SELECT 
					*
				FROM
					{prefix}notifications
				WHERE id IN ({$notificationIds})
			");

		} else {
			$queryParams = array($notificationId);
			$query = $this->preparePrefix("
				SELECT 
					*
				FROM
					{prefix}notifications
				WHERE id = '%d'
				LIMIT 1
			");
		}

		$notifications = $this->db->get_results(
			$this->db->prepare($query, $queryParams),
			ARRAY_A
		);

		return $notifications;
	}

	public function removeNotification($notificationId) {
		$query = $this->preparePrefix("DELETE FROM {prefix}notifications WHERE id = '%d'");

		$result = $this->db->query($this->db->prepare($query, array($notificationId)));
		$this->setNotificationUserMeta(get_current_user_id());
		return $result;
	}

	public function getNotificationCount($userId, array $params = null){
        $query = $this->preparePrefix("SELECT COUNT(*) FROM {prefix}notifications");
	    $queryParams = array_merge(array('user_id' => $userId), $params);
	    $queryParamsArray = array();

	    if(!array_key_exists('target_id', $params)){
	        return 0;
        }

	    foreach($queryParams as $name => $val){
            array_push($queryParamsArray, sprintf('`%s` = \'%s\'', $name, $val));
        }

        $queryParamsStr = implode(" AND ", $queryParamsArray);
        $query = implode(" WHERE ", array($query, $queryParamsStr));

        $count = $this->db->get_results($query, ARRAY_N);
        if(empty($count)){ return null; }
        $count = array_pop($count);
        $count = array_pop($count);

	    return (integer)$count;
    }

    public function getNotification($userId, array $fields){
	    //Select '*'?
        $query = $this->preparePrefix("SELECT * FROM {prefix}notifications");
        $queryParams = array_merge(array('user_id' => $userId), $fields);
        $queryParamsArray = array();

        foreach($queryParams as $name => $val){
            array_push($queryParamsArray, sprintf('`%s` = \'%s\'', $name, $val));
        }

        $queryParamsStr = implode(" AND ", $queryParamsArray);
        $query = implode(" WHERE ", array($query, $queryParamsStr));

        $data = $this->db->get_results($query, ARRAY_A);

        return $data;
    }

	public function setNotificationUserMeta($userId) {
		$query = $this->preparePrefix("SELECT 1 as flag FROM {prefix}notifications WHERE user_id = '%d' AND viewed = 0 LIMIT 1");
		$exists = $this->db->get_row($this->db->prepare($query, array($userId)), ARRAY_N);

		update_user_meta($userId, 'supsystic_membership_notifications', (is_array($exists) && $exists[0] == 1) ? 1 : 0);
		return true;
    }

	public function setNotificationUserMetaByIds($notificationIds) {
		if(!is_array($notificationIds) || sizeof($notificationIds) == 0) return true;

		$query = $this->preparePrefix("SELECT DISTINCT user_id
			FROM {prefix}notifications
			WHERE id IN (" . implode(', ', array_pad(array(), count($notificationIds), "'%d'")) . ")");
		$userIds = $this->db->get_results($this->db->prepare($query, $notificationIds), ARRAY_N);
		foreach($userIds as $i => $userId) {
			$this->setNotificationUserMeta((int)$userId[0]);
		}
		return true;
	}
}