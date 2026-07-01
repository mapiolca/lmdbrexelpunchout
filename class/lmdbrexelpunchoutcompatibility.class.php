<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Compatibility checks for Rexel Punchout.
 */
class LmdbRexelPunchoutCompatibility
{
	/**
	 * Check Dolibarr version.
	 *
	 * @param string $version Minimum version
	 * @return bool
	 */
	public static function isDolibarrVersionAtLeast($version)
	{
		if (!defined('DOL_VERSION')) {
			return false;
		}

		return version_compare(DOL_VERSION, $version, '>=');
	}

	/**
	 * Check PHP version.
	 *
	 * @param string $version Minimum version
	 * @return bool
	 */
	public static function isPhpVersionAtLeast($version)
	{
		return version_compare(PHP_VERSION, $version, '>=');
	}

	/**
	 * Get feature definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function getFeatures()
	{
		return array(
			'module_base' => array(
				'label' => 'LmdbRexelPunchoutFeatureBase',
				'description' => 'LmdbRexelPunchoutFeatureBaseDescription',
				'min_dolibarr' => '20.0.0',
				'min_php' => '8.0.0',
				'methods' => array('dol_buildpath', 'newToken'),
			),
			'cxml' => array(
				'label' => 'LmdbRexelPunchoutFeatureCxml',
				'description' => 'LmdbRexelPunchoutFeatureCxmlDescription',
				'min_dolibarr' => '20.0.0',
				'min_php' => '8.0.0',
				'classes' => array('DOMDocument', 'DOMXPath'),
			),
			'curl_cxml_setup' => array(
				'label' => 'LmdbRexelPunchoutFeatureCxmlCurl',
				'description' => 'LmdbRexelPunchoutFeatureCxmlCurlDescription',
				'min_dolibarr' => '20.0.0',
				'min_php' => '8.0.0',
				'functions' => array('curl_init'),
			),
			'native_secret_encryption' => array(
				'label' => 'LmdbRexelPunchoutFeatureSecretEncryption',
				'description' => 'LmdbRexelPunchoutFeatureSecretEncryptionDescription',
				'min_dolibarr' => '20.0.0',
				'min_php' => '8.0.0',
				'functions' => array('dolEncrypt', 'dolDecrypt'),
			),
		);
	}

	/**
	 * Check if a feature is available.
	 *
	 * @param string $code Feature code
	 * @return bool
	 */
	public static function isFeatureAvailable($code)
	{
		$features = self::getFeatures();
		if (empty($features[$code])) {
			return false;
		}

		return empty(self::getUnavailableReason($features[$code]));
	}

	/**
	 * Get unavailable features.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function getUnavailableFeatures()
	{
		$unavailable = array();
		foreach (self::getFeatures() as $code => $feature) {
			$reason = self::getUnavailableReason($feature);
			if ($reason !== '') {
				$feature['reason'] = $reason;
				$feature['available'] = false;
				$unavailable[$code] = $feature;
			}
		}

		return $unavailable;
	}

	/**
	 * Get feature status list.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function getFeatureStatuses()
	{
		$statuses = array();
		foreach (self::getFeatures() as $code => $feature) {
			$reason = self::getUnavailableReason($feature);
			$feature['available'] = ($reason === '');
			$feature['reason'] = $reason;
			$statuses[$code] = $feature;
		}

		return $statuses;
	}

	/**
	 * Return reason why feature is unavailable.
	 *
	 * @param array<string,mixed> $feature Feature definition
	 * @return string
	 */
	private static function getUnavailableReason($feature)
	{
		if (!empty($feature['min_dolibarr']) && !self::isDolibarrVersionAtLeast($feature['min_dolibarr'])) {
			return 'RequiresDolibarrVersion';
		}

		if (!empty($feature['min_php']) && !self::isPhpVersionAtLeast($feature['min_php'])) {
			return 'RequiresPhpVersion';
		}

		foreach (($feature['functions'] ?? array()) as $function) {
			if (!function_exists($function)) {
				return 'RequiresPhpFunction';
			}
		}

		foreach (($feature['methods'] ?? array()) as $function) {
			if (!function_exists($function)) {
				return 'RequiresDolibarrFunction';
			}
		}

		foreach (($feature['classes'] ?? array()) as $class) {
			if (!class_exists($class)) {
				return 'RequiresPhpClass';
			}
		}

		return '';
	}
}
