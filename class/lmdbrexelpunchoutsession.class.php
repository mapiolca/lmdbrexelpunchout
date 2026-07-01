<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * \file        class/lmdbrexelpunchoutsession.class.php
 * \ingroup     lmdbrexelpunchout
 * \brief       Punchout session object.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/lmdbrexelpunchoutsecurity.class.php';

/**
 * Punchout session.
 */
class LmdbRexelPunchoutSession extends CommonObject
{
	public $module = 'lmdbrexelpunchout';
	public $element = 'lmdbrexelpunchout_session';
	public $table_element = 'lmdbrexelpunchout_session';
	public $picto = 'technic';
	public $ismultientitymanaged = 1;

	const STATUS_CREATED = 'created';
	const STATUS_SENT = 'sent';
	const STATUS_RETURNED = 'returned';
	const STATUS_IMPORTED = 'imported';
	const STATUS_EXPIRED = 'expired';
	const STATUS_ERROR = 'error';

	public $id;
	public $rowid;
	public $entity;
	public $token_hash;
	public $fk_commandefourn;
	public $fk_soc;
	public $fk_user;
	public $protocol;
	public $status;
	public $datec;
	public $tms;
	public $date_validity;
	public $date_return;
	public $date_import;
	public $raw_payload;
	public $normalized_payload;
	public $basket_payload;
	public $import_log;
	public $error_message;
	public $import_count;
	public $ip_address;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create a session for a supplier order.
	 *
	 * @param CommandeFournisseur $order    Supplier order
	 * @param User                $user     User
	 * @param string              $rawToken Public token
	 * @return int
	 */
	public function createFromOrder($order, $user, $rawToken)
	{
		global $conf;

		$this->entity = (int) $conf->entity;
		$this->token_hash = LmdbRexelPunchoutSecurity::hashToken($rawToken);
		$this->fk_commandefourn = (int) $order->id;
		$this->fk_soc = (int) $order->socid;
		$this->fk_user = (int) $user->id;
		$this->protocol = 'CXML';
		$this->status = self::STATUS_CREATED;
		$this->datec = dol_now();
		$this->date_validity = dol_now() + (max(1, LmdbRexelPunchoutConfig::getInt('TOKEN_TTL', 30)) * 60);
		$this->ip_address = dol_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$this->table_element.' (';
		$sql .= 'entity, token_hash, fk_commandefourn, fk_soc, fk_user, protocol, status, datec, date_validity, ip_address';
		$sql .= ') VALUES (';
		$sql .= ((int) $this->entity);
		$sql .= ", '".$this->db->escape($this->token_hash)."'";
		$sql .= ', '.((int) $this->fk_commandefourn);
		$sql .= ', '.((int) $this->fk_soc);
		$sql .= ', '.((int) $this->fk_user);
		$sql .= ", '".$this->db->escape($this->protocol)."'";
		$sql .= ", '".$this->db->escape($this->status)."'";
		$sql .= ", '".$this->db->idate($this->datec)."'";
		$sql .= ", '".$this->db->idate($this->date_validity)."'";
		$sql .= ", '".$this->db->escape($this->ip_address)."'";
		$sql .= ')';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$this->table_element);
		$this->rowid = $this->id;

		return $this->id;
	}

	/**
	 * Fetch session by id.
	 *
	 * @param int $id Row id
	 * @return int
	 */
	public function fetch($id)
	{
		$sql = 'SELECT rowid, entity, token_hash, fk_commandefourn, fk_soc, fk_user, protocol, status, datec, tms, date_validity, date_return, date_import, raw_payload, normalized_payload, basket_payload, import_log, error_message, import_count, ip_address';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' WHERE rowid = '.((int) $id);

		return $this->fetchSessionFromSql($sql);
	}

	/**
	 * Fetch session by public token and entity.
	 *
	 * @param string $rawToken Public token
	 * @param int    $entity   Entity
	 * @return int
	 */
	public function fetchByToken($rawToken, $entity)
	{
		$sql = 'SELECT rowid, entity, token_hash, fk_commandefourn, fk_soc, fk_user, protocol, status, datec, tms, date_validity, date_return, date_import, raw_payload, normalized_payload, basket_payload, import_log, error_message, import_count, ip_address';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE token_hash = '".$this->db->escape(LmdbRexelPunchoutSecurity::hashToken($rawToken))."'";
		$sql .= ' AND entity = '.((int) $entity);

		return $this->fetchSessionFromSql($sql);
	}

	/**
	 * Fetch session from a prepared SQL query.
	 *
	 * @param string $sql SQL query
	 * @return int
	 */
	private function fetchSessionFromSql($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		foreach ($obj as $key => $value) {
			$this->{$key} = $value;
		}
		$this->id = (int) $obj->rowid;
		$this->rowid = $this->id;

		return 1;
	}

	/**
	 * Check expiration.
	 *
	 * @return bool
	 */
	public function isExpired()
	{
		return !empty($this->date_validity) && $this->db->jdate($this->date_validity) < dol_now();
	}

	/**
	 * Update status.
	 *
	 * @param string $status       New status
	 * @param string $errorMessage Optional error
	 * @return int
	 */
	public function setStatus($status, $errorMessage = '')
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = '".$this->db->escape($status)."'";
		if ($errorMessage !== '') {
			$sql .= ", error_message = '".$this->db->escape($errorMessage)."'";
		}
		$sql .= ' WHERE rowid = '.((int) $this->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->status = $status;
		$this->error_message = $errorMessage;

		return 1;
	}

	/**
	 * Store returned payload and normalized lines.
	 *
	 * @param string             $rawPayload Raw payload
	 * @param array<int,array<string,mixed>> $lines Normalized lines
	 * @param array<string,mixed>|null $basketPayload Structured basket metadata
	 * @return int
	 */
	public function storeReturn($rawPayload, $lines, $basketPayload = null)
	{
		$this->db->begin();

		$basketPayloadJson = $basketPayload !== null ? json_encode($basketPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = '".self::STATUS_RETURNED."'";
		$sql .= ", date_return = '".$this->db->idate(dol_now())."'";
		$sql .= ", raw_payload = '".$this->db->escape($rawPayload)."'";
		$sql .= ", normalized_payload = '".$this->db->escape(json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."'";
		$sql .= $basketPayloadJson !== null ? ", basket_payload = '".$this->db->escape($basketPayloadJson)."'" : ', basket_payload = NULL';
		$sql .= ' WHERE rowid = '.((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_session_line WHERE fk_session = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$lineIndex = 0;
		foreach ($lines as $line) {
			$lineIndex++;
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbrexelpunchout_session_line (';
			$sql .= 'entity, fk_session, line_index, vendor_ref, external_id, label, description, qty, unit_code, price, price_unit, unit_price_ht, currency, leadtime_days, vat_rate, source_line_number, supplier_part_auxiliary_id, classification_domain, classification, tax_amount, tax_currency';
			$sql .= ') VALUES (';
			$sql .= ((int) $this->entity);
			$sql .= ', '.((int) $this->id);
			$sql .= ', '.((int) $lineIndex);
			$sql .= ", '".$this->db->escape((string) ($line['vendor_ref'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['external_id'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['label'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['description'] ?? ''))."'";
			$sql .= ', '.price2num((float) ($line['qty'] ?? 0), 'MS');
			$sql .= ", '".$this->db->escape((string) ($line['unit'] ?? ''))."'";
			$sql .= ', '.price2num((float) ($line['price'] ?? 0), 'MU');
			$sql .= ', '.price2num((float) ($line['price_unit'] ?? 1), 'MS');
			$sql .= ', '.price2num((float) ($line['unit_price_ht'] ?? 0), 'MU');
			$sql .= ", '".$this->db->escape((string) ($line['currency'] ?? ''))."'";
			$sql .= ', '.((int) ($line['leadtime_days'] ?? 0));
			$sql .= ', '.price2num((float) ($line['vat_rate'] ?? 0), 'MT');
			$sql .= ", '".$this->db->escape((string) ($line['source_line_number'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['supplier_part_auxiliary_id'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['classification_domain'] ?? ''))."'";
			$sql .= ", '".$this->db->escape((string) ($line['classification'] ?? ''))."'";
			$sql .= ', '.price2num((float) ($line['tax_amount'] ?? 0), 'MU');
			$sql .= ", '".$this->db->escape((string) ($line['tax_currency'] ?? ''))."'";
			$sql .= ')';

			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		$this->status = self::STATUS_RETURNED;
		$this->raw_payload = $rawPayload;
		$this->normalized_payload = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->basket_payload = $basketPayloadJson;

		return 1;
	}

	/**
	 * Fetch normalized lines.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchLines()
	{
		$lines = array();
		$sql = 'SELECT rowid, entity, fk_session, line_index, vendor_ref, external_id, label, description, qty, unit_code, fk_unit, price, price_unit, unit_price_ht, currency, leadtime_days, vat_rate, source_line_number, supplier_part_auxiliary_id, classification_domain, classification, tax_amount, tax_currency, fk_product, fk_product_fournisseur_price, fk_commandefourndet, warning';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_session_line';
		$sql .= ' WHERE fk_session = '.((int) $this->id);
		$sql .= ' ORDER BY line_index ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $lines;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$lines[] = (array) $obj;
		}

		return $lines;
	}

	/**
	 * Update a normalized line after import.
	 *
	 * @param int    $lineId            Line id
	 * @param int    $fkProduct         Product id
	 * @param int    $fkSupplierPrice   Supplier price id
	 * @param int    $fkSupplierOrderLn Supplier order line id
	 * @param int    $fkUnit            Unit id
	 * @param string $warning           Warning
	 * @return int
	 */
	public function updateLineImport($lineId, $fkProduct, $fkSupplierPrice, $fkSupplierOrderLn, $fkUnit, $warning = '')
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbrexelpunchout_session_line SET';
		$sql .= ' fk_product = '.($fkProduct > 0 ? (int) $fkProduct : 'NULL');
		$sql .= ', fk_product_fournisseur_price = '.($fkSupplierPrice > 0 ? (int) $fkSupplierPrice : 'NULL');
		$sql .= ', fk_commandefourndet = '.($fkSupplierOrderLn > 0 ? (int) $fkSupplierOrderLn : 'NULL');
		$sql .= ', fk_unit = '.($fkUnit > 0 ? (int) $fkUnit : 'NULL');
		$sql .= ", warning = '".$this->db->escape($warning)."'";
		$sql .= ' WHERE rowid = '.((int) $lineId);
		$sql .= ' AND fk_session = '.((int) $this->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Mark imported.
	 *
	 * @param array<string,mixed> $summary Import summary
	 * @return int
	 */
	public function markImported($summary)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = '".self::STATUS_IMPORTED."'";
		$sql .= ", date_import = '".$this->db->idate(dol_now())."'";
		$sql .= ', import_count = '.((int) ($summary['lines_added'] ?? 0));
		$sql .= ", import_log = '".$this->db->escape(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."'";
		$sql .= ' WHERE rowid = '.((int) $this->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->status = self::STATUS_IMPORTED;
		$this->import_log = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->import_count = (int) ($summary['lines_added'] ?? 0);

		return 1;
	}

	/**
	 * Expire old sessions.
	 *
	 * @return int Number of updated rows when known
	 */
	public function expireOldSessions()
	{
		global $conf;

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = '".self::STATUS_EXPIRED."'";
		$sql .= " WHERE status IN ('".self::STATUS_CREATED."', '".self::STATUS_SENT."')";
		$sql .= ' AND entity = '.((int) $conf->entity);
		$sql .= " AND date_validity < '".$this->db->idate(dol_now())."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return method_exists($this->db, 'affected_rows') ? (int) $this->db->affected_rows($resql) : 1;
	}

	/**
	 * Purge old payloads but keep session audit rows.
	 *
	 * @return int
	 */
	public function purgeOldPayloads()
	{
		global $conf;

		$days = max(1, LmdbRexelPunchoutConfig::getInt('RETENTION_DAYS', 30));
		$limit = dol_now() - ($days * 86400);

		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' SET raw_payload = NULL, normalized_payload = NULL, basket_payload = NULL';
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= " AND datec < '".$this->db->idate($limit)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return method_exists($this->db, 'affected_rows') ? (int) $this->db->affected_rows($resql) : 1;
	}
}
