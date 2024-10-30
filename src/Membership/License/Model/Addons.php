<?php

class Membership_License_Model_Addons extends Membership_Base_Model_Settings
{
	protected $apiUri = 'http://supsystic.com/';
	protected $settingField = 'addons';

	public function getAddons() {

		$activeAddons = $this->get($this->settingField);

		if ($activeAddons) {
			$activeAddons = $activeAddons['addons'];
		}

		$installedAddons = $this->getInstalledAddons();

		foreach ($installedAddons as $id => $addon) {
			if (isset($activeAddons[$id])) {
				$installedAddons[$id] = $activeAddons[$id];

			} else {
				$installedAddons[$id]['state'] = 'not-activated';
			}
		}

		return $installedAddons;
	}


   public function useLicenseGatewayServer($data) {
		 		 // We use this gateway server special for activation our PRO users whos hosted on OVH.NET hosting.
				 // Because some clusters of this hosting block incoming connections due to internal firewall settings.
				 // Therefore, we launched a cluster on OVH hosting to host the activation gateway for PRO users. Thus, each PRO user can activate a license not only through supsystic.com, but also through ovh.net.
         $response = wp_remote_post('http://vps821250.ovh.net/wp-admin/admin-ajax.php?mod=licensegateway&action=getSupsysticAnswer&pl=lgs', array(
            'body' => $data,
            'redirection' => 5,
         ));
         if (!empty($response) && !empty($response['body'])) {
            $response['body'] = base64_decode($response['body']);
            $response['body'] = unserialize($response['body']);
            return $response;
         }
         return false;
      }


	public function activate(array $licenseData, $gateway = false)
	{
      $gateway = $gateway;
		$siteUrl = get_bloginfo('wpurl') . '/';

		$requestData = array_merge($licenseData, array(
			'mod' => 'manager',
			'pl' => 'lms',
			'action' => 'activate',
			'url' => $siteUrl,
		));

      if ($gateway) {
         $response = $this->useLicenseGatewayServer($requestData);
      } else {
         $response = wp_remote_post($this->apiUri, array(
            'body' => $requestData
         ));
      }

		if (is_wp_error($response)) {

			return array(
				'success' => false,
				'error' => $response->get_error_message()
			);

		} else {
			$response = json_decode($response['body'], true);

			if ($response === false) {
				return array(
					'success' => 'false',
					'error' => $this->translate('License server error')
				);
			}

			if ($response['error']) {
				return array(
					'success' => false,
					'error' => $response['errors'][0]
				);
			}

			return array(
				'success' => true,
				'response' => $response
			);
		}
	}

	public function getInstalledAddons() {
		return $this->environment->getConfig()->get('addons');
	}

	public function getInstalledAndActivatedAddons() {
		$instAndActAddons = array();

		$addonList = $this->getAddons();
		if(is_array($addonList) && count($addonList)) {
			foreach($addonList as $addonKey => $oneAddon) {
				if(!empty($oneAddon['state']) && $oneAddon['state'] == 'activated') {
					$instAndActAddons[$addonKey] = $oneAddon;
				}
			}
		}

		return $instAndActAddons;
	}
}
