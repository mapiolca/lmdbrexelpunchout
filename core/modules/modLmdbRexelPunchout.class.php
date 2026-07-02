<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        core/modules/modLmdbRexelPunchout.class.php
 * \ingroup     lmdbrexelpunchout
 * \brief       Descriptor for Rexel Punchout module.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor.
 */
class modLmdbRexelPunchout extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 450017;
		$this->rights_class = 'lmdbrexelpunchout';
		$this->family = 'Les Métiers du Bâtiment';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'LmdbRexelPunchoutModuleDescription';
		$this->descriptionlong = 'LmdbRexelPunchoutModuleDescriptionLong';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'lmdbrexelpunchout@lmdbrexelpunchout';
		$this->editor_name = 'Les Métiers du Bâtiment';
		$this->editor_url = 'https://lesmetiersdubatiment.fr';

		$this->module_parts = array(
			'hooks' => array(
				'ordersuppliercard',
				'globalcard',
			),
		);

		$this->dirs = array('/lmdbrexelpunchout/temp');

		$this->config_page_url = array(
			'setup.php@lmdbrexelpunchout',
		);

		$this->hidden = false;
		$this->depends = array('modFournisseur', 'modProduct');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(20, 0);
		$this->langfiles = array('lmdbrexelpunchout@lmdbrexelpunchout');

		$this->const = array(
			1 => array('LMDBREXELPUNCHOUT_FK_SOC', 'chaine', '0', 'Configured Rexel supplier thirdparty', 0, 'current', 1),
			2 => array('LMDBREXELPUNCHOUT_OPEN_MODE', 'chaine', 'popup', 'Default opening mode', 0, 'current', 1),
			3 => array('LMDBREXELPUNCHOUT_CURRENCY', 'chaine', 'EUR', 'Expected currency', 0, 'current', 1),
			4 => array('LMDBREXELPUNCHOUT_DEFAULT_VAT', 'chaine', '20', 'Default VAT rate', 0, 'current', 1),
			5 => array('LMDBREXELPUNCHOUT_CREATE_PRODUCTS', 'chaine', '1', 'Create missing products', 0, 'current', 1),
			6 => array('LMDBREXELPUNCHOUT_ALLOW_ZERO_PRICE', 'chaine', '0', 'Allow zero prices', 0, 'current', 1),
			7 => array('LMDBREXELPUNCHOUT_PRODUCT_REF_PREFIX', 'chaine', 'REXEL-', 'Product reference prefix', 0, 'current', 1),
			8 => array('LMDBREXELPUNCHOUT_PRODUCT_REF_MODE', 'chaine', 'prefix', 'Product reference strategy for missing Rexel products', 0, 'current', 1),
			9 => array('LMDBREXELPUNCHOUT_TOKEN_TTL', 'chaine', '30', 'Punchout token duration in minutes', 0, 'current', 1),
			10 => array('LMDBREXELPUNCHOUT_RETENTION_DAYS', 'chaine', '30', 'Session retention duration in days', 0, 'current', 1),
			11 => array('LMDBREXELPUNCHOUT_CXML_URL', 'chaine', '', 'Rexel cXML PunchOutSetup URL', 0, 'current', 1),
			12 => array('LMDBREXELPUNCHOUT_CXML_SHARED_SECRET', 'chaine', '', 'cXML shared secret', 0, 'current', 1),
			13 => array('LMDBREXELPUNCHOUT_CXML_CUSTOMER_DOMAIN', 'chaine', '', 'cXML customer domain', 0, 'current', 1),
			14 => array('LMDBREXELPUNCHOUT_CXML_CUSTOMER_IDENTITY', 'chaine', '', 'cXML customer identity', 0, 'current', 1),
			15 => array('LMDBREXELPUNCHOUT_CXML_SENDER_DOMAIN', 'chaine', '', 'cXML sender domain', 0, 'current', 1),
			16 => array('LMDBREXELPUNCHOUT_CXML_SENDER_IDENTITY', 'chaine', '', 'cXML sender identity', 0, 'current', 1),
			17 => array('LMDBREXELPUNCHOUT_CXML_SUPPLIER_DOMAIN', 'chaine', '', 'cXML supplier domain', 0, 'current', 1),
			18 => array('LMDBREXELPUNCHOUT_CXML_SUPPLIER_IDENTITY', 'chaine', '', 'cXML supplier identity', 0, 'current', 1),
			19 => array('LMDBREXELPUNCHOUT_CXML_MODE', 'chaine', 'production', 'cXML deployment mode', 0, 'current', 1),
			20 => array('LMDBREXELPUNCHOUT_CXML_LANG', 'chaine', 'en-US', 'cXML language', 0, 'current', 1),
			21 => array('LMDBREXELPUNCHOUT_CXML_IMPORT_SHIPPING', 'chaine', '1', 'Import cXML shipping fees', 0, 'current', 1),
			22 => array('LMDBREXELPUNCHOUT_CXML_SHIPPING_FK_PRODUCT', 'chaine', '0', 'Optional product/service for cXML shipping fees', 0, 'current', 1),
			23 => array('LMDBREXELPUNCHOUT_CXML_SHIPPING_VAT_RATE', 'chaine', '', 'Optional VAT rate for cXML shipping fees', 0, 'current', 1),
			24 => array('LMDBREXELPUNCHOUT_CXML_IMPORT_DEEE', 'chaine', '1', 'Import cXML DEEE ecocontribution', 0, 'current', 1),
			25 => array('LMDBREXELPUNCHOUT_CXML_DEEE_FK_PRODUCT', 'chaine', '0', 'Optional product/service for cXML DEEE ecocontribution', 0, 'current', 1),
			26 => array('LMDBREXELPUNCHOUT_CXML_DEEE_VAT_RATE', 'chaine', '', 'Optional VAT rate for cXML DEEE ecocontribution', 0, 'current', 1),
			8 => array('LMDBREXELPUNCHOUT_TOKEN_TTL', 'chaine', '30', 'Punchout token duration in minutes', 0, 'current', 1),
			9 => array('LMDBREXELPUNCHOUT_RETENTION_DAYS', 'chaine', '30', 'Session retention duration in days', 0, 'current', 1),
			10 => array('LMDBREXELPUNCHOUT_CXML_URL', 'chaine', '', 'Rexel cXML PunchOutSetup URL', 0, 'current', 1),
			11 => array('LMDBREXELPUNCHOUT_CXML_SHARED_SECRET', 'chaine', '', 'cXML shared secret', 0, 'current', 1),
			12 => array('LMDBREXELPUNCHOUT_CXML_CUSTOMER_DOMAIN', 'chaine', '', 'cXML customer domain', 0, 'current', 1),
			13 => array('LMDBREXELPUNCHOUT_CXML_CUSTOMER_IDENTITY', 'chaine', '', 'cXML customer identity', 0, 'current', 1),
			14 => array('LMDBREXELPUNCHOUT_CXML_SENDER_DOMAIN', 'chaine', '', 'cXML sender domain', 0, 'current', 1),
			15 => array('LMDBREXELPUNCHOUT_CXML_SENDER_IDENTITY', 'chaine', '', 'cXML sender identity', 0, 'current', 1),
			16 => array('LMDBREXELPUNCHOUT_CXML_SUPPLIER_DOMAIN', 'chaine', '', 'cXML supplier domain', 0, 'current', 1),
			17 => array('LMDBREXELPUNCHOUT_CXML_SUPPLIER_IDENTITY', 'chaine', '', 'cXML supplier identity', 0, 'current', 1),
			18 => array('LMDBREXELPUNCHOUT_CXML_MODE', 'chaine', 'production', 'cXML deployment mode', 0, 'current', 1),
			19 => array('LMDBREXELPUNCHOUT_CXML_LANG', 'chaine', 'en-US', 'cXML language', 0, 'current', 1),
			20 => array('LMDBREXELPUNCHOUT_CXML_IMPORT_SHIPPING', 'chaine', '1', 'Import cXML shipping fees', 0, 'current', 1),
			21 => array('LMDBREXELPUNCHOUT_CXML_SHIPPING_FK_PRODUCT', 'chaine', '0', 'Optional product/service for cXML shipping fees', 0, 'current', 1),
			22 => array('LMDBREXELPUNCHOUT_CXML_SHIPPING_VAT_RATE', 'chaine', '', 'Optional VAT rate for cXML shipping fees', 0, 'current', 1),
			23 => array('LMDBREXELPUNCHOUT_CXML_IMPORT_UNQUALIFIED_DELTA', 'chaine', '0', 'Import positive unqualified cXML total delta', 0, 'current', 1),
			24 => array('LMDBREXELPUNCHOUT_CXML_UNQUALIFIED_DELTA_FK_PRODUCT', 'chaine', '0', 'Optional product/service for positive unqualified cXML total delta', 0, 'current', 1),
			25 => array('LMDBREXELPUNCHOUT_CXML_UNQUALIFIED_DELTA_VAT_RATE', 'chaine', '', 'Optional VAT rate for positive unqualified cXML total delta', 0, 'current', 1),
		);

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array(
			0 => array(
				'label' => 'LmdbRexelPunchoutCronCleanupLabel',
				'jobtype' => 'method',
				'class' => '/lmdbrexelpunchout/class/lmdbrexelpunchoutcron.class.php',
				'objectname' => 'LmdbRexelPunchoutCron',
				'method' => 'runCleanup',
				'parameters' => '',
				'comment' => 'LmdbRexelPunchoutCronCleanupComment',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("lmdbrexelpunchout")',
				'priority' => 50,
			),
		);

		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbRexelPunchoutRightUse';
		$this->rights[$r][4] = 'punchout';
		$this->rights[$r][5] = 'use';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbRexelPunchoutRightReadSessions';
		$this->rights[$r][4] = 'session';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbRexelPunchoutRightConfigure';
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = 'write';

		$this->menu = array();
	}

	/**
	 * Initialize module.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function init($options = '')
	{
		$sql = array();
		$result = $this->_load_tables('/lmdbrexelpunchout/sql/');
		if ($result < 0) {
			return -1;
		}
		if ($this->upgradeSchema() < 0) {
			return -1;
		}
		$this->initDefaultUnitMap();

		return $this->_init($sql, $options);
	}

	/**
	 * Remove module.
	 *
	 * Configuration constants are intentionally preserved.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function remove($options = '')
	{
		$sql = array();
		$declaredConstants = $this->const;
		$this->const = array();
		$result = $this->_remove($sql, $options);
		$this->const = $declaredConstants;

		return $result;
	}

	/**
	 * Insert default generic supplier unit mappings for the current entity.
	 *
	 * @return void
	 */
	private function initDefaultUnitMap()
	{
		global $conf;

		$units = array(
			'PCE' => 'Pièce',
			'EA' => 'Pièce',
			'BOX' => 'Boîte',
			'M' => 'Mètre',
			'L' => 'Litre',
		);

		foreach ($units as $code => $label) {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap (entity, supplier_unit, fk_unit, label, date_creation)';
			$sql .= ' SELECT '.((int) $conf->entity).", '".$this->db->escape($code)."', NULL, '".$this->db->escape($label)."', '".$this->db->idate(dol_now())."'";
			$sql .= ' WHERE NOT EXISTS (';
			$sql .= 'SELECT 1 FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap';
			$sql .= ' WHERE entity = '.((int) $conf->entity)." AND supplier_unit = '".$this->db->escape($code)."'";
			$sql .= ')';
			$this->db->query($sql);
		}
	}

	/**
	 * Upgrade existing module tables with columns added after initial release.
	 *
	 * @return int
	 */
	private function upgradeSchema()
	{
		$columnsByTable = array(
			'lmdbrexelpunchout_session' => array(
				'basket_payload' => 'mediumtext NULL',
			),
			'lmdbrexelpunchout_session_line' => array(
				'source_line_number' => 'varchar(64) NULL',
				'supplier_part_auxiliary_id' => 'varchar(255) NULL',
				'classification_domain' => 'varchar(64) NULL',
				'classification' => 'varchar(255) NULL',
				'tax_amount' => 'double(24,8) DEFAULT 0 NOT NULL',
				'tax_currency' => 'varchar(3) NULL',
			),
		);

		foreach ($columnsByTable as $table => $columns) {
			foreach ($columns as $column => $definition) {
				if ($this->addColumnIfMissing($table, $column, $definition) < 0) {
					return -1;
				}
			}
		}

		return 1;
	}

	/**
	 * Add a column if it does not already exist.
	 *
	 * @param string $table      Table name without prefix
	 * @param string $column     Column name
	 * @param string $definition SQL column definition
	 * @return int
	 */
	private function addColumnIfMissing($table, $column, $definition)
	{
		$tableName = MAIN_DB_PREFIX.$table;
		$sql = 'SHOW COLUMNS FROM '.$tableName." LIKE '".$this->db->escape($column)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		if ($this->db->num_rows($resql) > 0) {
			return 1;
		}

		$sql = 'ALTER TABLE '.$tableName.' ADD COLUMN '.$column.' '.$definition;
		return $this->db->query($sql) ? 1 : -1;
	}
}
