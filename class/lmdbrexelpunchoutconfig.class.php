<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Configuration helper for Rexel Punchout.
 */
class LmdbRexelPunchoutConfig
{
	const PREFIX = 'LMDBREXELPUNCHOUT_';
	const PRODUCT_REF_MODE_PREFIX = 'prefix';
	const PRODUCT_REF_MODE_DOLIBARR = 'dolibarr';
	const PRODUCT_REF_MODE_SUPPLIER_REF = 'supplier_ref';
	const PRODUCT_REF_MODE_MANUAL = 'manual';

	/**
	 * Get string constant.
	 *
	 * @param string $name    Constant name without prefix
	 * @param string $default Default value
	 * @return string
	 */
	public static function getString($name, $default = '')
	{
		$key = self::PREFIX.$name;
		if (function_exists('getDolGlobalString')) {
			return getDolGlobalString($key, $default);
		}

		global $conf;
		return isset($conf->global->{$key}) ? (string) $conf->global->{$key} : $default;
	}

	/**
	 * Get integer constant.
	 *
	 * @param string $name    Constant name without prefix
	 * @param int    $default Default value
	 * @return int
	 */
	public static function getInt($name, $default = 0)
	{
		$key = self::PREFIX.$name;
		if (function_exists('getDolGlobalInt')) {
			return (int) getDolGlobalInt($key, $default);
		}

		return (int) self::getString($name, (string) $default);
	}

	/**
	 * Get numeric constant.
	 *
	 * @param string $name    Constant name without prefix
	 * @param float  $default Default value
	 * @return float
	 */
	public static function getFloat($name, $default = 0.0)
	{
		return (float) str_replace(',', '.', self::getString($name, (string) $default));
	}

	/**
	 * Return fixed supported protocol.
	 *
	 * @return string
	 */
	public static function getProtocol()
	{
		return 'CXML';
	}

	/**
	 * Return opening mode.
	 *
	 * @return string
	 */
	public static function getOpenMode()
	{
		$mode = strtolower(self::getString('OPEN_MODE', 'popup'));
		return in_array($mode, array('iframe', 'popup', 'newtab'), true) ? $mode : 'popup';
	}

	/**
	 * Get encrypted secret value.
	 *
	 * @param string $name Constant name without prefix
	 * @return string
	 */
	public static function getSecret($name)
	{
		$value = self::getString($name, '');
		if ($value !== '' && function_exists('dolDecrypt')) {
			$decrypted = dolDecrypt($value);
			if ($decrypted !== false && $decrypted !== '') {
				return (string) $decrypted;
			}
		}

		return $value;
	}

	/**
	 * Save a regular constant in current entity.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $name  Constant name without prefix
	 * @param string $value Value
	 * @param string $type  Constant type
	 * @return int
	 */
	public static function set($db, $name, $value, $type = 'chaine')
	{
		global $conf;

		return dolibarr_set_const($db, self::PREFIX.$name, $value, $type, 0, '', (int) $conf->entity);
	}

	/**
	 * Save a secret in current entity.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $name  Constant name without prefix
	 * @param string $value Plain value
	 * @return int
	 */
	public static function setSecret($db, $name, $value)
	{
		if ($value !== '' && function_exists('dolEncrypt')) {
			$value = dolEncrypt($value);
		}

		return self::set($db, $name, $value);
	}

	/**
	 * Build cXML return URL for Rexel.
	 *
	 * @param string $token  Public one-time token
	 * @param int    $entity Entity
	 * @return string
	 */
	public static function getReturnUrl($token, $entity)
	{
		return dol_buildpath('/lmdbrexelpunchout/public/return_cxml.php', 2).'?entity='.(int) $entity.'&token='.urlencode($token);
	}

	/**
	 * Return expected currency.
	 *
	 * @return string
	 */
	public static function getExpectedCurrency()
	{
		$currency = strtoupper(self::getString('CURRENCY', 'EUR'));
		return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
	}

	/**
	 * Return supported product reference modes.
	 *
	 * @return array<int,string>
	 */
	public static function getProductRefModes()
	{
		return array(
			self::PRODUCT_REF_MODE_PREFIX,
			self::PRODUCT_REF_MODE_DOLIBARR,
			self::PRODUCT_REF_MODE_SUPPLIER_REF,
			self::PRODUCT_REF_MODE_MANUAL,
		);
	}

	/**
	 * Return configured product reference mode.
	 *
	 * @return string
	 */
	public static function getProductRefMode()
	{
		$mode = self::getString('PRODUCT_REF_MODE', self::PRODUCT_REF_MODE_PREFIX);
		return in_array($mode, self::getProductRefModes(), true) ? $mode : self::PRODUCT_REF_MODE_PREFIX;
	}

	/**
	 * Get missing settings.
	 *
	 * @return array<int,string>
	 */
	public static function getMissingSettings()
	{
		$missing = array();

		if (self::getInt('FK_SOC') <= 0) {
			$missing[] = self::PREFIX.'FK_SOC';
		}

		foreach (array('CXML_URL', 'CXML_SHARED_SECRET', 'CXML_CUSTOMER_DOMAIN', 'CXML_CUSTOMER_IDENTITY', 'CXML_SUPPLIER_DOMAIN', 'CXML_SUPPLIER_IDENTITY') as $key) {
			if ($key === 'CXML_SHARED_SECRET') {
				if (self::getSecret($key) === '') {
					$missing[] = self::PREFIX.$key;
				}
			} elseif (self::getString($key) === '') {
				$missing[] = self::PREFIX.$key;
			}
		}

		return $missing;
	}

	/**
	 * Check if configuration is complete.
	 *
	 * @return bool
	 */
	public static function isComplete()
	{
		return count(self::getMissingSettings()) === 0;
	}
}
