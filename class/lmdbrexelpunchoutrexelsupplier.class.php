<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Rexel supplier helper.
 */

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/lmdbrexelpunchoutsecurity.class.php';

/**
 * Create or associate the official Rexel France supplier thirdparty.
 */
class LmdbRexelPunchoutRexelSupplier
{
	const NAME = 'REXEL FRANCE';
	const SIREN = '309304616';
	const SIRET = '30930461605851';
	const TVA_INTRA = 'FR26309304616';
	const APE = '4669A';
	const ADDRESS = '13 boulevard du Fort de Vaux CS 60002';
	const ZIP = '75838';
	const TOWN = 'Paris Cedex 17';
	const COUNTRY_CODE = 'FR';
	const PHONE = '01-55-50-00-00';
	const EMAIL = 'contact.rexel@rexel.fr';
	const URL = 'https://www.rexel.fr';

	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var array<int,string> */
	public $errors = array();

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
	 * Create or associate Rexel supplier for the current entity.
	 *
	 * Sources checked on 2026-07-01:
	 * - https://www.rexel.fr/frx/mentions-legales
	 * - https://annuaire-entreprises.data.gouv.fr/entreprise/309304616
	 *
	 * @param User $user User
	 * @return array{status:string,fk_soc:int,created:bool,updated:bool,ambiguous_ids?:array<int,int>}
	 */
	public function createOrAssociate($user)
	{
		$matches = $this->findMatchingThirdpartyIds();
		if ($this->error !== '') {
			return $this->errorResult('error');
		}
		if (count($matches) > 1) {
			return array(
				'status' => 'ambiguous',
				'fk_soc' => 0,
				'created' => false,
				'updated' => false,
				'ambiguous_ids' => $matches,
			);
		}

		if (count($matches) === 1) {
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch((int) $matches[0]) <= 0) {
				$this->error = $thirdparty->error ?: 'Unable to load Rexel thirdparty';
				$this->errors = is_array($thirdparty->errors) ? $thirdparty->errors : array();
				return $this->errorResult('error');
			}

			$updated = $this->ensureSupplierFlag($thirdparty, $user);
			if ($updated < 0) {
				return $this->errorResult('error');
			}

			LmdbRexelPunchoutConfig::set($this->db, 'FK_SOC', (string) $thirdparty->id);

			return array(
				'status' => $updated > 0 ? 'associated_updated' : 'associated',
				'fk_soc' => (int) $thirdparty->id,
				'created' => false,
				'updated' => $updated > 0,
			);
		}

		if (!LmdbRexelPunchoutSecurity::canManageThirdparties($user)) {
			$this->error = 'LmdbRexelPunchoutThirdpartyPermissionRequired';
			return $this->errorResult('permission');
		}

		$thirdpartyId = $this->createSupplier($user);
		if ($thirdpartyId <= 0) {
			return $this->errorResult('error');
		}

		LmdbRexelPunchoutConfig::set($this->db, 'FK_SOC', (string) $thirdpartyId);

		return array(
			'status' => 'created',
			'fk_soc' => $thirdpartyId,
			'created' => true,
			'updated' => false,
		);
	}

	/**
	 * Find existing Rexel-like thirdparty ids.
	 *
	 * @return array<int,int>
	 */
	private function findMatchingThirdpartyIds()
	{
		$ids = $this->fetchThirdpartyIdsByWhere("REPLACE(REPLACE(UPPER(t.tva_intra), ' ', ''), '.', '') = '".$this->db->escape(self::TVA_INTRA)."'"
			." OR REPLACE(REPLACE(UPPER(t.siren), ' ', ''), '.', '') = '".$this->db->escape(self::SIREN)."'"
			." OR REPLACE(REPLACE(UPPER(t.siret), ' ', ''), '.', '') = '".$this->db->escape(self::SIRET)."'");
		if ($this->error !== '' || !empty($ids)) {
			return $ids;
		}

		return $this->fetchThirdpartyIdsByWhere("UPPER(t.nom) = '".$this->db->escape(self::NAME)."'");
	}

	/**
	 * Fetch thirdparty ids with a controlled WHERE fragment.
	 *
	 * @param string $where SQL predicate without entity filter
	 * @return array<int,int>
	 */
	private function fetchThirdpartyIdsByWhere($where)
	{
		global $conf;

		$entitySql = function_exists('getEntity') ? getEntity('societe') : (string) ((int) $conf->entity);
		$sql = 'SELECT DISTINCT t.rowid FROM '.MAIN_DB_PREFIX.'societe AS t';
		$sql .= ' WHERE t.entity IN ('.$entitySql.')';
		$sql .= ' AND ('.$where.')';
		$sql .= ' ORDER BY t.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}

		$ids = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}

		return $ids;
	}

	/**
	 * Ensure an existing thirdparty is enabled as supplier.
	 *
	 * @param Societe $thirdparty Thirdparty
	 * @param User    $user       User
	 * @return int 1 updated, 0 unchanged, -1 error
	 */
	private function ensureSupplierFlag($thirdparty, $user)
	{
		if ((int) $thirdparty->fournisseur === 1) {
			return 0;
		}

		if (!LmdbRexelPunchoutSecurity::canManageThirdparties($user)) {
			$this->error = 'LmdbRexelPunchoutThirdpartyPermissionRequired';
			return -1;
		}

		$thirdparty->fournisseur = 1;
		if (empty($thirdparty->code_fournisseur)) {
			$thirdparty->code_fournisseur = 'auto';
		}

		$result = $thirdparty->update((int) $thirdparty->id, $user, 1, 0, 1);
		if ($result <= 0) {
			$this->error = $thirdparty->error ?: 'Unable to enable Rexel supplier flag';
			$this->errors = is_array($thirdparty->errors) ? $thirdparty->errors : array();
			return -1;
		}

		return 1;
	}

	/**
	 * Create the official Rexel France supplier thirdparty.
	 *
	 * @param User $user User
	 * @return int Thirdparty id, or -1
	 */
	private function createSupplier($user)
	{
		global $conf;

		$thirdparty = new Societe($this->db);
		$thirdparty->entity = (int) $conf->entity;
		$thirdparty->name = self::NAME;
		$thirdparty->nom = self::NAME;
		$thirdparty->client = 0;
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$thirdparty->status = 1;
		$thirdparty->address = self::ADDRESS;
		$thirdparty->zip = self::ZIP;
		$thirdparty->town = self::TOWN;
		$thirdparty->country_code = self::COUNTRY_CODE;
		$thirdparty->phone = self::PHONE;
		$thirdparty->email = self::EMAIL;
		$thirdparty->url = self::URL;
		$thirdparty->idprof1 = self::SIREN;
		$thirdparty->siren = self::SIREN;
		$thirdparty->idprof2 = self::SIRET;
		$thirdparty->siret = self::SIRET;
		$thirdparty->idprof3 = self::APE;
		$thirdparty->ape = self::APE;
		$thirdparty->tva_intra = self::TVA_INTRA;
		$thirdparty->tva_assuj = 1;

		$result = $thirdparty->create($user);
		if ($result <= 0) {
			$this->error = $thirdparty->error ?: 'Unable to create Rexel supplier thirdparty';
			$this->errors = is_array($thirdparty->errors) ? $thirdparty->errors : array();
			return -1;
		}

		return (int) $thirdparty->id;
	}

	/**
	 * Build an error result.
	 *
	 * @param string $status Status
	 * @return array{status:string,fk_soc:int,created:bool,updated:bool}
	 */
	private function errorResult($status)
	{
		return array(
			'status' => $status,
			'fk_soc' => 0,
			'created' => false,
			'updated' => false,
		);
	}
}
