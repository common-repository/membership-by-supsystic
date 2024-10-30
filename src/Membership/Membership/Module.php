<?php

class Membership_Membership_Module extends Membership_Base_Module
{
	protected $menu = array();
	protected $menuWalkerClassName = null;
	private $globalSettings = null;

	public function onInit() {
		parent::onInit();

		if(is_admin()) {
			// init custom navigation menu edit items
			if(!$this->menuWalkerClassName) {
				$mbsMenuItemsClass = new Membership_Membership_Model_MenuEditItemCustomFields();
				$mbsMenuItemsClass->init();
				add_action('wp_loaded', array($this, 'wpLoadHandler'));
				$this->menuWalkerClassName = 'Membership_Membership_Model_MenuEditItemCustomFieldsWalker';
			}
		}

		if(!is_admin()) {
			add_filter('wp_get_nav_menu_items', array($this, 'conditionalNavMenuHandler'), 9999, 3);
			$this->globalSettings = get_option('supsystic_membership_settings');
			if($this->globalSettings !== false) {
				if(isset($this->globalSettings['global-notification']) && $this->globalSettings['global-notification'] == 'true') {
					add_action('wp_footer', array($this, 'addNotificationButton'));
				}
				if(isset($this->globalSettings['global-search']) && $this->globalSettings['global-search'] == 'true') {
					add_filter('posts_results', array($this, 'globalSearchFilter'), 10, 2);
				}
			}
		}
	}

	public function addNotificationButton() {
		$userId = get_current_user_id();
		if(get_user_meta($userId, 'supsystic_membership_notifications', true) == '1') {
			$module = $this->getModule('users');
			echo '<div style="position: fixed; right: 5px; top: '.(is_admin_bar_showing() ? 40 : 10) .'px;z-index: 999;">
					<a href="'.$module->getUserProfileUrl($module->getCurrentUser(), array('action' => 'notifications')).'">
						<i class="alarm icon red" style="transition: all 0.1s linear; text-shadow: 0px 0px 5px rgba(100, 100, 100, 1);"></i>
					</a>
				</div>';
		}
	}

	public function afterModulesLoaded() {

		$routesModule = $this->getModule('routes');
		$this->registerActions();
		$routesModule->registerAjaxRoutes(array(
			'membership.saveSettings' => array(
				'method' => 'post',
				'admin' => true,
				'handler' => array($this->getController(), 'saveSettings')
			),

			'membership.importBuddyPressData' => array(
				'admin' => true,
				'method' => 'post',
				'handler' => array($this->getController(), 'importBuddyPressData')
			),

			'membership.importUltimateMemberData' => array(
				'admin' => true,
				'method' => 'post',
				'handler' => array($this->getController(), 'importUltimateMemberData')
			),

			'membership.uninstall' => array(
                'method' => 'post',
                'admin' => true,
                'handler' => array($this->getController(), 'uninstall')
            ),
            'membership.createPage' => array(
                'method' => 'post',
                'admin' => true,
                'handler' => array($this->getController(), 'createPage')
            ),
            'membership.savePages' => array(
                'method' => 'post',
                'admin' => true,
                'handler' => array($this->getController(), 'savePages')
            ),
            'membership.createUnassignedPages' => array(
                'method' => 'post',
                'admin' => true,
                'handler' => array($this->getController(), 'createUnassignedPages')
            ),
            'membership.reviewNoticeResponse' => array(
                'method' => 'post',
                'admin' => true,
                'handler' => array($this->getController(), 'reviewNoticeResponse')
            )
		));


		if ($this->isPluginPage()) {
			$this->reviewNoticeCheck();
		}

		if (!$this->isModule('membership')) {
			return;
		}

		$assetsPath = $this->getAssetsPath();
        $baseAssetsPath = $this->getModule('base')->getAssetsPath();
		$reportsAssetsPath = $this->getModule('reports')->getAssetsPath();

        $this->getModule('assets')->enqueueAssets(
			array(
			    $baseAssetsPath . '/lib/tooltipster/tooltipster.bundle.min.css',
                $assetsPath . '/css/membership.backend.css',
            ),
			array(
                $baseAssetsPath . '/lib/tooltipster/tooltipster.bundle.min.js',
				$assetsPath . '/js/membership.backend.js',
				$reportsAssetsPath . '/js/reports.backend.js',
			)
		);
	}

	public function registerActions() {
		add_action('wp_logout', array($this, 'afterLogoutAction'));
		add_action('init', array($this, 'blockedIpCheck'));
		add_action('init', array($this, 'wpLoginRedirect'));
	}

	public function afterLogoutAction() {

		$settings = $this->getSettings();
		$afterLogoutAction = $settings['base']['main']['after-logout-action'];

		$redirectTo = home_url();

		if ($afterLogoutAction === 'redirect-to-url') {
			$redirectTo = $settings['base']['main']['after-logout-action-redirect-url'];
		}

		wp_redirect($redirectTo);
		exit;
	}

	public function wpLoginRedirect()
	{
		global $pagenow;

		if ($pagenow === 'wp-login.php') {

			$settings = $this->getSettings();
			$routesModule = $this->getModule('routes');
			$routesList = $routesModule->getRoutesList();

			if (!$routesList || !isset($routesList['login'])) {
				return;
			}

			$redirect = $settings['base']['security']['backend-login-screen-redirect'];
			$redirectTo = $routesModule->getRouteUrl('login');

			$action = !empty($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

			if ($redirect !== 'no' && (!$action || $action !== 'logout') && $redirectTo !== false && !isset($_GET['interim-login'])) {
				wp_redirect($redirectTo);
				die();
			}
		}
	}

	public function blockedIpCheck()
	{
		$settings = $this->getSettings();

		if (! empty($_SERVER['HTTP_CLIENT_IP'])) {
			$currentUserIp = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$currentUserIp = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$currentUserIp = $_SERVER['REMOTE_ADDR'];
		}

		$blockedIps = trim($settings['base']['security']['blocked-ip']);

		if (!empty($blockedIps)) {


			$blockedIps = preg_split('/\s+/', str_replace(array('.', '*'), array('\.', '\d{0,3}'), $blockedIps));
			$blockedIpsPattern = '/' . implode('|', $blockedIps) . '/';

			if (preg_match($blockedIpsPattern, $currentUserIp)) {
				status_header(404);
				get_template_part(404);
				exit();
			}
		}
	}

	public function reviewNoticeCheck() {
		$option = $this->getConfig('db_prefix') . 'reviewNotice';
		$notice = get_option($option);
		if (!$notice) {
			update_option($option, array(
				'time' => time() + (60 * 60 * 24 * 7),
				'shown' => false
			));
		} elseif ($notice['shown'] === false && time() > $notice['time']) {
			add_action('admin_notices', array($this, 'showReviewNotice'));
		}
	}

	public function showReviewNotice() {
		print $this->render('@membership/backend/review-notice.twig');
	}

	public function wpLoadHandler() {
		add_filter('wp_edit_nav_menu_walker', array($this, 'wpEditNavMenuWalkerHandler' ), 100);
	}

	public function wpEditNavMenuWalkerHandler() {
		return $this->menuWalkerClassName;
	}

	function conditionalNavMenuHandler($items, $menu, $args) {

		$childMenuArrToHide = array();
		$isCurrUserLoggined = (int) get_current_user_id();

		if(count($items)) {
			foreach($items as $menuKey => $oneMenuItem) {
				$mbsVisibilityValue = (int) get_post_meta($oneMenuItem->ID, 'menu-item-mbs-menu-visibility', true);
				$currItemStayVisible = true;

				if(in_array($oneMenuItem->menu_item_parent, $childMenuArrToHide)) {
					$currItemStayVisible = false;
					// for nested menus
					$childMenuArrToHide[] = $oneMenuItem->ID;
				}

				// if current user Guest and param = 'logInned user' (1)
				if(!$isCurrUserLoggined && $mbsVisibilityValue == 1) {
					$currItemStayVisible = false;
				}

				// if current user is Authored and param = 'logOuted user' (2)
				if($isCurrUserLoggined && $mbsVisibilityValue == 2) {
					$currItemStayVisible = false;
				}

				if(!$currItemStayVisible) {
					if(!in_array($oneMenuItem->ID, $childMenuArrToHide)) {
						$childMenuArrToHide[] = $oneMenuItem->ID;
					}
					unset($items[$menuKey]);
				}
			}
		}

		return $items;
	}

	public function globalSearchFilter($posts, $query) {
		if($query->is_search() && $query->is_main_query()) {
			$obj = new ArrayObject($posts);
			$clone = $obj->getArrayCopy();

			$types = array();
			if($this->globalSettings['global-search-groups'] == 'true') {
				$types = array('groups');
			}
			if($this->globalSettings['global-search-users'] == 'true') {
				array_push($types, 'posts');
			}
			$search_query = trim(get_search_query());

			if(sizeof($types) > 0 && !empty($search_query)) {
				$tokens = array_filter(explode(' ', $search_query));
				$module = $this->getModule('activity');

				$params = array('activityTypes' => $types, 'dataLike' => $tokens, 'limit' => 100);
				$userId = get_current_user_id();
				if($userId > 0) {
					$params['userId'] = $userId;
				}

				$activities = $module->getModel('activity', 'activity')->getActivity($params);

				if(isset($activities) && sizeof($activities) > 0) {
					$per_page = $query->query_vars['posts_per_page'];
					$max_pages = $query->max_num_pages;
					$found = (int)$query->found_posts;
					$page = isset($query->query['paged']) ? $query->query['paged'] : 1;

					if($found == 0 && $page > 1)
					{
						global $wpdb;
						$exact = get_query_var( 'exact' );
						$n = ( empty( $exact ) ) ? '%' : '';
						$request = str_replace('SQL_CALC_FOUND_ROWS ', '', $wpdb->remove_placeholder_escape($query->request));
						$order = stripos($request, 'order by');
						if($order > 0) {
							$request = substr($request, 0, $order);
						}
						$result = $wpdb->get_results($request, ARRAY_N);

						if(is_array($result)) {
							$found = sizeof($result);
							$max_pages = ceil($found / $per_page);
						}
					}
					$limit = 0;
					$offset = 0;
					if($page == $max_pages) {
						$limit = $per_page - sizeof($clone);
					} else if ($page > $max_pages) {
						$limit = $per_page;
						$offset = ceil($found / $per_page) * $per_page - $found + $per_page * ($page - $max_pages - 1);
					}

					$cnt = sizeof($activities);
					$siteUrl = get_site_url();
					$n = 0;
					if($limit > 0) {
						foreach($activities as $i => $activity) {
							if($i < $offset) continue;
							$n++;
							$postUrl = $activity['url'];
							if(strpos($postUrl, $siteUrl) === 0) {
								$postUrl = substr($postUrl, strlen($siteUrl) + 1);
							}
							array_push($clone, (object)array(
								'ID' => -1,
								'post_status' => 'closed',
								'post_author' => $activity['user_id'],
								'post_date' => $activity['created_at'],
								'post_title' => ($activity['type'] != 'post' ? $activity['group']['name'].' > ' : '').$activity['author']['displayName'],
								'post_content' => $activity['data'],
								'guid' => $postUrl));
							if($n >= $limit) break;
						}
					}
					$found += $cnt;
					$query->found_posts = (string)$found;
					$query->max_num_pages = ceil($found / $per_page);
				}
			}
			add_filter('pre_post_link', array($this, 'setPermalinkPost'), 10, 2);
			add_filter('edit_post_link', array($this, 'setEditlinkPost'), 10, 2);
			return $clone;
		}

		return $posts;
	}
	public function setPermalinkPost($permalink, $post) {
		if ($post->ID == -1) {
			return $post->guid;
		}
		return $permalink;
	}
	public function setEditlinkPost($link, $post) {
		if ($post == -1) {
			return false;
		}
		return $link;
	}
}
