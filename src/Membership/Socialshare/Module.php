<?php
class Membership_Socialshare_Module extends Membership_Base_Module {

	public function afterModulesLoaded() {
		//check if social free plugin is active
		$socialShare = $this->getModel('socialShare', 'socialshare');

		if ($socialShare->isPluginActive()) {
			parent::onInit();
			add_action('mbs_disable_social_sharing', array($this, 'manuallyDisableSocialProject'), 10, 1);

			// backend
			$this->getDispatcher()->on('backendMainContentSettingsSocialShareOpt', array($this, 'getViewBackendSettings'), 10, 1);
			// frontend scripts
			$this->getDispatcher()->on('activity.enqueueActivitiesAssets', array($this, 'registerFrontendAsset'), 10, 1);
		}
	}

	public function registerFrontendAsset() {
		$membershipModel = $this->getModel('settings', 'membership');
		$settings = $membershipModel->getSettings();
		// check membership settings
		if(!empty($settings['plugins']['socialShare']['ids']) && !empty($settings['plugins']['socialShare']['enabled']) && $settings['plugins']['socialShare']['enabled'] == '1') {
			// check SocialShare plugin enabled
			global $supsysticSocialSharing;
			if($supsysticSocialSharing) {

				$ssEnvironment = $supsysticSocialSharing->getEnvironment();
				if($ssEnvironment) {

					$projectModule = $ssEnvironment->getModule('projects');
					$projectUrl = plugins_url('Projects', $projectModule->getLocation());

					$ssCss = array();
					$ssJs = array();

					$ssCss[] = array(
						'handle' => 'sss-base',
						'source' => $projectUrl . '/assets/css/base.css',
					);
					$ssCss[] = array(
						'handle' => 'sss-tooltipster-main',
						'source' => $projectUrl . '/assets/css/tooltipster.css',
					);
					$ssCss[] = array(
						'handle' => 'sss-brand-icons',
						'source' => $projectUrl . '/assets/css/buttons/brand-icons.css',
					);
					$ssCss[] = array(
						'handle' => 'sss-tooltipster-shadow',
						'source' => $projectUrl .'/assets/css/tooltipster-shadow.css',
					);

					$ssJs[] = array(
						'handle' => 'sss-frontend',
						'source' => $projectUrl .'/assets/js/frontend.js',
					);
					$ssJs[] = array(
						'handle' => 'sss-tooltipster-scripts',
						'source' => $projectUrl .'/assets/js/jquery.tooltipster.min.js',
					);
					$ssJs[] = array(
						'handle' => 'sss-bpopup',
						'source' => $projectUrl .'/assets/js/jquery.bpopup.min.js',
					);

					if($ssEnvironment->isPro()) {
						$config = $ssEnvironment->getConfig();
						$projectPathPro = $config->get('pro_modules_path') . '/SocialSharingPro/Projects';
						$projectUrlPro = plugins_url('Projects', $projectPathPro);

						$ssCss[] = array(
							'handle' => 'sss-flat-pro',
							'source' => $projectUrlPro .'/assets/css/flat.css',
						);
						$ssCss[] = array(
							'handle' => 'sss-pro-buttons',
							'source' => $projectUrlPro .'/assets/buttons/buttons.css',
						);

						$ssJs[] = array(
							'handle' => 'sss-cookies',
							'source' => $projectUrlPro .'/assets/js/jquery.cookie.min.js',
						);
						$ssJs[] = array(
							'handle' => 'sss-frontend-pro',
							'source' => $projectUrlPro .'/assets/js/frontend.js',
						);
					}

					$this->getModule('assets')->enqueueAssets($ssCss, $ssJs, MBS_FRONTEND);
				}
			}
		}
	}

	public function getViewBackendSettings() {

		$ssModel = $this->getModel('SocialShare');

		echo $this->render('@socialshare/backend/mainContentSettings.twig', array(
			'socialShareInfo' => array(
				'isPuliginActive' => $ssModel->isPluginActive(),
				'installUrl' => $ssModel->getPluginInstallUrl(),
				'installWpUrl' => $ssModel->getWpInstallUrl(),
				'projectList' => $ssModel->getProjectList(),
			),
		));
		return true;
	}

	/**
	 * disable social sharing in Memberhip, when SocialShare project setting "where_to_show" changed from
	 * "membership" to something else
	 * @param int $ssProjectId id of social sharing project
	 */
	public function manuallyDisableSocialProject($ssProjectId) {
		$ssProjectId = (int) $ssProjectId;
		$membershipModel = $this->getModel('settings', 'membership');
		$settings = $membershipModel->getSettings();
		if(!empty($settings['plugins']['socialShare']['ids']) && is_array($settings['plugins']['socialShare']['ids'])) {

			// find index for search value
			$fInd = array_search($ssProjectId, $settings['plugins']['socialShare']['ids']);
			if($fInd !== null && $fInd !== false) {
				// remove item from array
				unset($settings['plugins']['socialShare']['ids'][$fInd]);
				// save settings
				$membershipModel->saveSettings($settings);
			}
		}
	}
}
