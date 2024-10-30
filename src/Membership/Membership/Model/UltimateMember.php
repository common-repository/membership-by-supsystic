<?php

class Membership_Membership_Model_UltimateMember extends Membership_Base_Model_Base {

	/**
	 * Get all Ultimate members fields
	 * @return array
	 */
	public function getFields() {
		$result = array();

		$forms = $this->getData("
			SELECT
				p.ID, p.post_title as title, pm.meta_value as fields
			FROM
				{wp_prefix}posts AS p
			LEFT JOIN {wp_prefix}postmeta as pm ON (pm.post_id = p.ID AND pm.meta_key = '_um_custom_fields')
			JOIN {wp_prefix}postmeta as pm2 ON (pm2.post_id = p.ID AND pm2.meta_key = '_um_mode' AND pm2.meta_value IN ('profile','login','register'))
			WHERE
				post_type = 'um_form'
		");

		$_fields = array();

		if ($forms) {
			foreach ($forms as $form) {
				$_fields[$form['title']] = unserialize($form['fields']);
			}
		}

		foreach ($_fields as $fields) {
			$result = array_merge($result, $fields);
		}

		return $_fields;
	}

	public function getTypesConversion() {
		return array(
			'text' => 'text',
			'url' => 'text',
			'number' => 'numeric',
			'textarea' => 'text',
			'select' => 'drop',
			'multiselect' => 'scroll',
			'radio' => 'radio',
			'checkbox' => 'checkbox',
			'password' => 'password',
			'image' => 'text',
			'file' => 'text',
			'date' => 'date',
			'time' => 'text',
			'rating' => 'text',
			'googlemap' => 'text',
			'youtube_video' => 'text',
			'vimeo_video' => 'text',
			'soundcloud_track' => 'text',
		);
	}

	/**
	 * Convert UM user fields to Membership fields format.
	 *
	 * @param $rawFields
	 *
	 * @return array
	 */
	public function prepareFieldsForMerge($rawFields) {

		if (!$rawFields) {
			return $rawFields;
		}

		$fields = array();
		$typesConversion = $this->getTypesConversion();

		foreach ($rawFields as $formName => $_fields) {
			foreach ($_fields as $field) {

				/*
				 * Skip um special and default wordpress fields
				 */
				if (in_array($field['type'],
						array(
							'divider',
							'block',
							'shortcode',
							'spacing',
							'row',
						)
					) || in_array($field['metakey'],
						array(
							'role_select',
							'role_radio',

							'first_name',
							'last_name',
							'user_password',
							'user_login',
							'username',
							'user_email',
							'user_registered',
							'last_login',
							'nickname'
						)
					)) {
					continue;
				}

				$fields[$field['metakey']] = array(
					'label' => $field['title'],
					'name' => $field['metakey'],
					'description' => @$field['help'],
					'type' => $typesConversion[$field['type']],
					'enabled' => true,
					'required' => intval($field['required']) === 1,
					'registration' => intval($field['required']) === 1,
					'section' => $formName
				);

				if (in_array($field['type'], array('select', 'multiselect', 'radio', 'checkbox'))) {
					$options = array();

					foreach ($field['options'] as $id => $name) {
						$options[] = array(
							'id' => (string) $id,
							'name' => (string) $name
						);
					}

					$fields[$field['metakey']]['options'] = $options;
				}
			}
		}
		return $fields;
	}

	/**
	 * Insert  Ultimate member fields to membership fields table
	 */
	public function insertFields($fields) {

		$fieldNames = array_keys($fields);
		$fieldNamesPlaceholder = array_pad(array(), count($fieldNames), '%s');
		$fieldNamesPlaceholder = implode(',', $fieldNamesPlaceholder);

		/**
		 * Insert field only if user provide some data
		 */
		return $this->query($this->db->prepare($this->preparePrefix("
			INSERT IGNORE INTO {prefix}fields (user_id, name, privacy)
				SELECT
				    um.user_id,
				    um.meta_key AS name,
				    'public' AS privacy
				FROM
				    {wp_base_prefix}usermeta AS um
			    WHERE um.meta_key IN (${fieldNamesPlaceholder})
			    AND um.meta_value IS NOT NULL
	            AND um.meta_value != ''
		"), $fieldNames));
	}
	/**
	 * Insert Ultimate member fields data to membership fields data table
	 */
	public function insertFieldsData($fields) {
//		$countryQuery = false;
		$fieldNames = array_keys($fields);
		$fieldNamesPlaceholder = array_pad(array(), count($fieldNames), '%s');
		$fieldNamesPlaceholder = implode(',', $fieldNamesPlaceholder);

		/**
		 * Remove all Ultimate Member fields data to avoid duplicate if it's run more than once
		 */
		$this->query($this->db->prepare("
			DELETE FROM {prefix}fields_data WHERE field_id IN (
				SELECT
				    f.id
				FROM
				    {prefix}fields AS f
				WHERE
	                f.name IN (${fieldNamesPlaceholder})
	        )
		", $fieldNames));

		$singleValueFields = array();
		$multipleValueFields = array();

		foreach ($fields as $field) {
			if (isset($field['options'])) {
				$multipleValueFields[$field['name']] = $field;
			} else {
				$singleValueFields[$field['name']] = $field;
			}
		}

		$fieldNamesPlaceholder = array_pad(array(), count($singleValueFields), '%s');
		$fieldNamesPlaceholder = implode(',', $fieldNamesPlaceholder);

		/**
		 * Insert field with single value.
		 */
		$this->getData($this->db->prepare("
     	  	INSERT INTO {prefix}fields_data (field_id, data)
			SELECT
			    f.id,
                um.meta_value
			FROM
				{prefix}fields AS f
				LEFT JOIN {wp_base_prefix}usermeta AS um ON (um.user_id = f.user_id AND um.meta_key = f.name)
			WHERE
                f.name IN (${fieldNamesPlaceholder})
		", array_keys($singleValueFields)));

		/**
		 * Insert fields with optional values.
		 */
		foreach ($multipleValueFields as $field) {
			$possibleValues = array();

			foreach ($field['options'] as $option) {
				$possibleValues[] = $this->db->prepare("SELECT '%s' AS id, '%s' AS name", array(
					$option['id'],
					$option['name'],
				));
			}

			$possibleValuesSelect = "SELECT * FROM (" . implode(' UNION ALL ', $possibleValues) . ") AS fieldOptions";
			$joinOn = 'um.meta_value = fo.name';
			if (in_array($field['type'], array('scroll', 'checkbox', 'radio'))) {
				// For serialized values
				$joinOn = "um.meta_value RLIKE CONCAT('\"', fo.name, '\"')";
			}

//			if($field['name']=== 'country'){
//				$countryQuery = true;
//			}

			$this->query($this->db->prepare("
				INSERT INTO {prefix}fields_data (field_id, data)
					SELECT
						f.id AS field_id,
					    fo.id AS data
					FROM
						{wp_base_prefix}usermeta AS um
					    JOIN (
						    SELECT * FROM ($possibleValuesSelect) AS fieldOptions
					    ) AS fo ON ($joinOn)
					    JOIN {prefix}fields AS f ON (f.name = um.meta_key AND f.user_id = um.user_id)
					WHERE
						um.meta_key = '%s'
			", $field['name']));
		}

//		if($countryQuery){
//			//get country field id and value
//			$query = 'SELECT fd.field_id, fd.data FROM {prefix}fields_data as fd WHERE field_id IN (SELECT f.id  FROM {prefix}fields as f WHERE name = "country")';
//			$countryFields = $this->getData($this->db->prepare($query));
//
//			if($countryFields){
//				$count = count($countryFields);
//				$i = 1;
//				$strId = '';
//				$when = '';
//				$end = ' END ';
//
//				foreach ($countryFields as $county){
//					//if country slug from ultimate members
//					if($this->getCountrySlug($county['data'])){
//
//						if($count === $i){
//							$strId .= $county['field_id'];
//						}else{
//							$strId .= $county['field_id'] . ', ';
//						}
//						$when .=  'WHEN '.$county['field_id'].' THEN "'.$this->getCountrySlug($county['data']).'" ';
//					}
//					$i++;
//				}
//				$where = "WHERE field_id IN(".$strId.")";
//				$query = "UPDATE {prefix}fields_data SET data = CASE field_id " . $when . $end . $where;
//
//				$this->query($this->db->prepare($query));
//			}
//		}
	}

//	public function getCountrySlug($slug){
//		$array = array (
//			'AF' => 'afghanistan',
//			'AX' => 'aland_islands',
//			'AL' => 'albania',
//			'DZ' => 'algeria',
//			'AS' => 'american_samoa',
//			'AD' => 'andorra',
//			'AO' => 'angola',
//			'AI' => 'anguilla',
//			'AQ' => 'antarctica',
//			'AG' => 'antigua_and_barbuda',
//			'AR' => 'argentina',
//			'AM' => 'armenia',
//			'AW' => 'aruba',
//			'AU' => 'australia',
//			'AT' => 'austria',
//			'AZ' => 'azerbaijan',
//			'BS' => 'bahamas',
//			'BH' => 'bahrain',
//			'BD' => 'bangladesh',
//			'BB' => 'barbados',
//			'BY' => 'belarus',
//			'BE' => 'belgium',
//			'BZ' => 'belize',
//			'BJ' => 'benin',
//			'BM' => 'bermuda',
//			'BT' => 'bhutan',
//			'BO' => 'bolivia',
//			'BA' => 'bosnia_and_herzegovina',
//			'BW' => 'botswana',
//			'BV' => 'bouvet_island',
//			'BR' => 'brazil',
//			'IO' => 'british_indian_ocean_territory',
//			'BN' => 'brunei_darussalam',
//			'BG' => 'bulgaria',
//			'BF' => 'burkina_faso',
//			'BI' => 'burundi',
//			'KH' => 'cambodia',
//			'CM' => 'cameroon',
//			'CA' => 'canada',
//			'CV' => 'cape_verde',
//			'KY' => 'cayman_islands',
//			'CF' => 'central_african_republic',
//			'TD' => 'chad',
//			'CL' => 'chile',
//			'CN' => 'china',
//			'CX' => 'christmas_island',
//			'CC' => 'cocos__keeling__islands',
//			'CO' => 'colombia',
//			'KM' => 'comoros',
//			'CG' => 'congo',
//			'CD' => 'congo__democratic_republic',
//			'CK' => 'cook_islands',
//			'CR' => 'costa_rica',
//			'CI' => 'cote_d_ivoire',
//			'HR' => 'croatia',
//			'CU' => 'cuba',
//			'CY' => 'cyprus',
//			'CZ' => 'czech_republic',
//			'DK' => 'denmark',
//			'DJ' => 'djibouti',
//			'DM' => 'dominica',
//			'DO' => 'dominican_republic',
//			'EC' => 'ecuador',
//			'EG' => 'egypt',
//			'SV' => 'el_salvador',
//			'GQ' => 'equatorial_guinea',
//			'ER' => 'eritrea',
//			'EE' => 'estonia',
//			'ET' => 'ethiopia',
//			'FK' => 'falkland_islands__malvinas_',
//			'FO' => 'faroe_islands',
//			'FJ' => 'fiji',
//			'FI' => 'finland',
//			'FR' => 'france',
//			'GF' => 'french_guiana',
//			'PF' => 'french_polynesia',
//			'TF' => 'french_southern_territories',
//			'GA' => 'gabon',
//			'GM' => 'gambia',
//			'GE' => 'georgia',
//			'DE' => 'germany',
//			'GH' => 'ghana',
//			'GI' => 'gibraltar',
//			'GR' => 'greece',
//			'GL' => 'greenland',
//			'GD' => 'grenada',
//			'GP' => 'guadeloupe',
//			'GU' => 'guam',
//			'GT' => 'guatemala',
//			'GG' => 'guernsey',
//			'GN' => 'guinea',
//			'GW' => 'guinea_bissau',
//			'GY' => 'guyana',
//			'HT' => 'haiti',
//			'HM' => 'heard_island___mcdonald_islands',
//			'VA' => 'holy_see__vatican_city_state_',
//			'HN' => 'honduras',
//			'HK' => 'hong_kong',
//			'HU' => 'hungary',
//			'IS' => 'iceland',
//			'IN' => 'india',
//			'ID' => 'indonesia',
//			'IR' => 'iran__islamic_republic_of',
//			'IQ' => 'iraq',
//			'IE' => 'ireland',
//			'IM' => 'isle_of_man',
//			'IT' => 'italy',
//			'JM' => 'jamaica',
//			'JP' => 'japan',
//			'JE' => 'jersey',
//			'JO' => 'jordan',
//			'KZ' => 'kazakhstan',
//			'KE' => 'kenya',
//			'KI' => 'kiribati',
//			'KP' => 'korea',
//			'KR' => 'korea',
//			'KW' => 'kuwait',
//			'KG' => 'kyrgyzstan',
//			'LA' => 'lao_people_s_democratic_republic',
//			'LV' => 'latvia',
//			'LB' => 'lebanon',
//			'LS' => 'lesotho',
//			'LR' => 'liberia',
//			'LY' => 'libyan_arab_jamahiriya',
//			'LI' => 'liechtenstein',
//			'LT' => 'lithuania',
//			'LU' => 'luxembourg',
//			'MO' => 'macao',
//			'MK' => 'macedonia',
//			'MG' => 'madagascar',
//			'MW' => 'malawi',
//			'MY' => 'malaysia',
//			'MV' => 'maldives',
//			'ML' => 'mali',
//			'MT' => 'malta',
//			'MH' => 'marshall_islands',
//			'MQ' => 'martinique',
//			'MR' => 'mauritania',
//			'MU' => 'mauritius',
//			'YT' => 'mayotte',
//			'MX' => 'mexico',
//			'FM' => 'micronesia__federated_states_of',
//			'MD' => 'moldova',
//			'MC' => 'monaco',
//			'MN' => 'mongolia',
//			'ME' => 'montenegro',
//			'MS' => 'montserrat',
//			'MA' => 'morocco',
//			'MZ' => 'mozambique',
//			'MM' => 'myanmar',
//			'NA' => 'namibia',
//			'NR' => 'nauru',
//			'NP' => 'nepal',
//			'NL' => 'netherlands',
//			'AN' => 'netherlands_antilles',
//			'NC' => 'new_caledonia',
//			'NZ' => 'new_zealand',
//			'NI' => 'nicaragua',
//			'NE' => 'niger',
//			'NG' => 'nigeria',
//			'NU' => 'niue',
//			'NF' => 'norfolk_island',
//			'MP' => 'northern_mariana_islands',
//			'NO' => 'norway',
//			'OM' => 'oman',
//			'PK' => 'pakistan',
//			'PW' => 'palau',
//			'PS' => 'palestinian_territory__occupied',
//			'PA' => 'panama',
//			'PG' => 'papua_new_guinea',
//			'PY' => 'paraguay',
//			'PE' => 'peru',
//			'PH' => 'philippines',
//			'PN' => 'pitcairn',
//			'PL' => 'poland',
//			'PT' => 'portugal',
//			'PR' => 'puerto_rico',
//			'QA' => 'qatar',
//			'RE' => 'reunion',
//			'RO' => 'romania',
//			'RU' => 'russian_federation',
//			'RW' => 'rwanda',
//			'BL' => 'saint_barthelemy',
//			'SH' => 'saint_helena',
//			'KN' => 'saint_kitts_and_nevis',
//			'LC' => 'saint_lucia',
//			'MF' => 'saint_martin',
//			'PM' => 'saint_pierre_and_miquelon',
//			'VC' => 'saint_vincent_and_grenadines',
//			'WS' => 'samoa',
//			'SM' => 'san_marino',
//			'ST' => 'sao_tome_and_principe',
//			'SA' => 'saudi_arabia',
//			'SN' => 'senegal',
//			'RS' => 'serbia',
//			'SC' => 'seychelles',
//			'SL' => 'sierra_leone',
//			'SG' => 'singapore',
//			'SK' => 'slovakia',
//			'SI' => 'slovenia',
//			'SB' => 'solomon_islands',
//			'SO' => 'somalia',
//			'ZA' => 'south_africa',
//			'GS' => 'south_georgia_and_sandwich_isl_',
//			'SS' => 'sudan',
//			'ES' => 'spain',
//			'LK' => 'sri_lanka',
//			'SD' => 'sudan',
//			'SR' => 'suriname',
//			'SJ' => 'svalbard_and_jan_mayen',
//			'SZ' => 'swaziland',
//			'SE' => 'sweden',
//			'CH' => 'switzerland',
//			'SY' => 'syrian_arab_republic',
//			'TW' => 'taiwan',
//			'TJ' => 'tajikistan',
//			'TZ' => 'tanzania',
//			'TH' => 'thailand',
//			'TL' => 'timor_leste',
//			'TG' => 'togo',
//			'TK' => 'tokelau',
//			'TO' => 'tonga',
//			'TT' => 'trinidad_and_tobago',
//			'TN' => 'tunisia',
//			'TR' => 'turkey',
//			'TM' => 'turkmenistan',
//			'TC' => 'turks_and_caicos_islands',
//			'TV' => 'tuvalu',
//			'UG' => 'uganda',
//			'UA' => 'ukraine',
//			'AE' => 'united_arab_emirates',
//			'GB' => 'united_kingdom',
//			'US' => 'united_states',
//			'UM' => 'united_states_outlying_islands',
//			'UY' => 'uruguay',
//			'UZ' => 'uzbekistan',
//			'VU' => 'vanuatu',
//			'VE' => 'venezuela',
//			'VN' => 'viet_nam',
//			'VG' => 'virgin_islands__british',
//			'VI' => 'virgin_islands__u_s_',
//			'WF' => 'wallis_and_futuna',
//			'EH' => 'western_sahara',
//			'YE' => 'yemen',
//			'ZM' => 'zambia',
//			'ZW' => 'zimbabwe+-',
//			'IL' => 'israel',
//		);
//
//		if(is_numeric($slug)){
//			$array = array_values($array);
//			$number = intval($slug);
//			return $array[$number];
//		}else if(array_key_exists( $slug, $array)){
//			return $array[$slug];
//		}else{
//			return false;
//		}
//	}

}