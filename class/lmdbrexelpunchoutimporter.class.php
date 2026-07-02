<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/lmdbrexelpunchoutbasket.class.php';
require_once __DIR__.'/lmdbrexelpunchoutparser.class.php';
require_once __DIR__.'/lmdbrexelpunchoutsecurity.class.php';
require_once __DIR__.'/lmdbrexelpunchoutsession.class.php';

/**
 * Import normalized cXML Punchout lines into a Dolibarr supplier order.
 */
class LmdbRexelPunchoutImporter
{
	/** @var DoliDB */
	private $db;

	/** @var array<int,string> */
	public $warnings = array();

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
	 * Build a fresh import summary.
	 *
	 * @return array<string,mixed>
	 */
	private function newImportSummary()
	{
		return array(
			'lines_added' => 0,
			'shipping_lines_added' => 0,
			'shipping_detected' => false,
			'shipping_amount' => 0.0,
			'shipping_currency' => '',
			'shipping_skipped_reason' => '',
			'deee_lines_added' => 0,
			'deee_detected' => false,
			'deee_amount' => 0.0,
			'deee_currency' => '',
			'deee_skipped_reason' => '',
			'unqualified_delta_lines_added' => 0,
			'unqualified_delta_detected' => false,
			'unqualified_delta_amount' => 0.0,
			'unqualified_delta_currency' => '',
			'unqualified_delta_skipped_reason' => '',
			'products_created' => 0,
			'supplier_prices_updated' => 0,
			'warnings' => array(),
		);
	}

	/**
	 * Import a stored returned session transactionally.
	 *
	 * @param LmdbRexelPunchoutSession $session Session
	 * @param User                     $user    User
	 * @param array<int,string>        $manualProductRefs Manual product refs indexed by session line id
	 * @return array<string,mixed>
	 */
	public function importStoredSession($session, $user, $manualProductRefs = array())
	{
		if ($session->status !== LmdbRexelPunchoutSession::STATUS_RETURNED) {
			throw new RuntimeException('Punchout session is not importable');
		}

		$this->db->begin();
		try {
			$summary = $this->importSession($session, $user, $manualProductRefs);
			if ($session->markImported($summary) < 0) {
				throw new RuntimeException($session->error);
			}
			$this->db->commit();

			return $summary;
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Import a returned session.
	 *
	 * @param LmdbRexelPunchoutSession $session Session
	 * @param User                     $user    User
	 * @param array<int,string>        $manualProductRefs Manual product refs indexed by session line id
	 * @return array<string,mixed>
	 */
	public function importSession($session, $user, $manualProductRefs = array())
	{
		global $conf;

		$summary = $this->newImportSummary();

		$order = new CommandeFournisseur($this->db);
		if ($order->fetch((int) $session->fk_commandefourn) <= 0) {
			throw new RuntimeException('Supplier order not found');
		}
		$order->fetch_thirdparty();

		if ((int) $order->entity !== (int) $conf->entity) {
			throw new RuntimeException('Supplier order belongs to another entity');
		}
		if ((int) $order->socid !== (int) $session->fk_soc || (int) $session->fk_soc !== LmdbRexelPunchoutConfig::getInt('FK_SOC')) {
			throw new RuntimeException('Supplier order does not match configured Rexel supplier');
		}
		if ((int) $order->statut !== CommandeFournisseur::STATUS_DRAFT) {
			throw new RuntimeException('Supplier order is not draft');
		}

		$supplier = new Societe($this->db);
		if ($supplier->fetch((int) $session->fk_soc) <= 0) {
			throw new RuntimeException('Rexel supplier not found');
		}

		$lines = $session->fetchLines();
		if (empty($lines)) {
			throw new RuntimeException('No Punchout line to import');
		}

		foreach ($lines as $line) {
			$this->validateLine($line);
			$unitId = $this->findUnit((string) $line['unit_code'], (int) $session->entity);
			$warning = '';
			if (!empty($line['unit_code']) && $unitId <= 0) {
				$warning = 'UnitNotMapped';
				$summary['warnings'][] = $line['vendor_ref'].': UnitNotMapped';
			}

			$productRef = '';
			$productId = $this->findProductBySupplierRef((int) $session->fk_soc, (string) $line['vendor_ref']);
			if ($productId <= 0) {
				$productRef = $this->resolveProductRef($line, $manualProductRefs);
				$productId = $this->findProductByRef($productRef);
			}
			if ($productId <= 0) {
				if (!LmdbRexelPunchoutConfig::getInt('CREATE_PRODUCTS', 1)) {
					throw new RuntimeException('Product not found and product creation is disabled: '.$line['vendor_ref']);
				}
				$productId = $this->createProduct($line, $user, $productRef);
				$summary['products_created']++;
			}

			$supplierPriceId = $this->upsertSupplierPrice($productId, $supplier, $line, $user);
			$summary['supplier_prices_updated']++;

			$description = $this->buildLineDescription($line);
			$result = $order->addline(
				$description,
				(float) $line['unit_price_ht'],
				(float) $line['qty'],
				(float) $line['vat_rate'],
				0,
				0,
				$productId,
				$supplierPriceId,
				(string) $line['vendor_ref'],
				0,
				'HT',
				0,
				0,
				0,
				0,
				null,
				null,
				array(),
				$unitId > 0 ? $unitId : null
			);

			if ($result <= 0) {
				throw new RuntimeException($order->error ?: 'Unable to add supplier order line');
			}

			if ($session->updateLineImport((int) $line['rowid'], $productId, $supplierPriceId, (int) $result, $unitId, $warning) < 0) {
				throw new RuntimeException($session->error ?: 'Unable to update Punchout import line');
			}

			$summary['lines_added']++;
		}

		$this->importCxmlShippingLine($session, $order, $lines, $summary);
		$this->importCxmlDeeeLine($session, $order, $lines, $summary);
		$this->importCxmlUnqualifiedDeltaLine($session, $order, $lines, $summary);

		$order->update_price(1, 'auto', 0, $order->thirdparty);

		return $summary;
	}

	/**
	 * Check if an import line needs a manual product reference.
	 *
	 * @param int                 $supplierId Supplier id
	 * @param array<string,mixed> $line       Line
	 * @return bool
	 */
	public function lineNeedsManualProductRef($supplierId, $line)
	{
		if (LmdbRexelPunchoutConfig::getProductRefMode() !== LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_MANUAL) {
			return false;
		}
		if (!empty($line['fk_product']) && (int) $line['fk_product'] > 0) {
			return false;
		}

		return $this->findProductBySupplierRef($supplierId, (string) $line['vendor_ref']) <= 0;
	}

	/**
	 * Return session lines requiring a manual product reference.
	 *
	 * @param LmdbRexelPunchoutSession $session Session
	 * @return array<int,array<string,mixed>>
	 */
	public function getLinesNeedingManualProductRefs($session)
	{
		$manualLines = array();
		if (LmdbRexelPunchoutConfig::getProductRefMode() !== LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_MANUAL) {
			return $manualLines;
		}

		foreach ($session->fetchLines() as $line) {
			if ($this->lineNeedsManualProductRef((int) $session->fk_soc, $line)) {
				$manualLines[] = $line;
			}
		}

		return $manualLines;
	}

	/**
	 * Validate normalized line.
	 *
	 * @param array<string,mixed> $line Line
	 * @return void
	 */
	private function validateLine($line)
	{
		if ((string) $line['vendor_ref'] === '') {
			throw new RuntimeException('Missing Rexel supplier reference');
		}
		if ((float) $line['qty'] <= 0) {
			throw new RuntimeException('Invalid quantity for '.$line['vendor_ref']);
		}
		if ((float) $line['unit_price_ht'] <= 0 && !LmdbRexelPunchoutConfig::getInt('ALLOW_ZERO_PRICE', 0)) {
			throw new RuntimeException('Zero price refused for '.$line['vendor_ref']);
		}
		if (strtoupper((string) $line['currency']) !== LmdbRexelPunchoutConfig::getExpectedCurrency()) {
			throw new RuntimeException('Unexpected currency for '.$line['vendor_ref']);
		}
	}

	/**
	 * Find unit mapping.
	 *
	 * @param string $unitCode Unit code
	 * @param int    $entity   Entity
	 * @return int
	 */
	private function findUnit($unitCode, $entity)
	{
		if ($unitCode === '') {
			return 0;
		}

		$sql = 'SELECT fk_unit FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap';
		$sql .= " WHERE supplier_unit = '".$this->db->escape($unitCode)."'";
		$sql .= ' AND entity IN ('.((int) $entity).', 1)';
		$sql .= ' ORDER BY entity DESC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj && $obj->fk_unit > 0 ? (int) $obj->fk_unit : 0;
	}

	/**
	 * Find product by supplier reference.
	 *
	 * @param int    $supplierId Supplier id
	 * @param string $vendorRef  Supplier reference
	 * @return int
	 */
	private function findProductBySupplierRef($supplierId, $vendorRef)
	{
		$sql = 'SELECT pfp.fk_product';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price AS pfp';
		$sql .= ' WHERE pfp.entity IN ('.getEntity('productsupplierprice').')';
		$sql .= ' AND pfp.fk_soc = '.((int) $supplierId);
		$sql .= " AND pfp.ref_fourn = '".$this->db->escape($vendorRef)."'";
		$sql .= ' ORDER BY pfp.rowid DESC';
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->fk_product : 0;
	}

	/**
	 * Find product by Dolibarr reference.
	 *
	 * @param string $productRef Product reference
	 * @return int
	 */
	private function findProductByRef($productRef)
	{
		if ($productRef === '') {
			return 0;
		}

		$product = new Product($this->db);
		$result = $product->fetch(0, $productRef);
		return $result > 0 ? (int) $product->id : 0;
	}

	/**
	 * Create product.
	 *
	 * @param array<string,mixed> $line Line
	 * @param User                $user User
	 * @param string              $ref  Product reference
	 * @return int
	 */
	private function createProduct($line, $user, $ref)
	{
		global $conf;

		$product = new Product($this->db);
		$product->ref = $ref;
		$product->label = $this->truncate((string) $line['label'], 255);
		$product->description = (string) ($line['description'] ?: $line['label']);
		$product->type = 0;
		$product->status = 0;
		$product->status_buy = 1;
		$product->entity = (int) $conf->entity;

		$result = $product->create($user);
		if ($result <= 0) {
			throw new RuntimeException($product->error ?: 'Unable to create product');
		}

		return (int) $product->id;
	}

	/**
	 * Create/update supplier price.
	 *
	 * @param int                 $productId Product id
	 * @param Societe             $supplier  Supplier
	 * @param array<string,mixed> $line      Line
	 * @param User                $user      User
	 * @return int
	 */
	private function upsertSupplierPrice($productId, $supplier, $line, $user)
	{
		$productFourn = new ProductFournisseur($this->db);
		if ($productFourn->fetch($productId) <= 0) {
			throw new RuntimeException('Unable to load product supplier price object');
		}

		$qtyForSupplierPrice = max(1, (float) ($line['price_unit'] ?? 1));
		$priceForSupplierQty = (float) ($line['price'] ?? $line['unit_price_ht']);

		$result = $productFourn->update_buyprice(
			$qtyForSupplierPrice,
			$priceForSupplierQty,
			$user,
			'HT',
			$supplier,
			0,
			(string) $line['vendor_ref'],
			(float) $line['vat_rate'],
			0,
			0,
			0,
			0,
			(int) $line['leadtime_days'],
			'',
			array(),
			'',
			0,
			'HT',
			1,
			(string) $line['currency'],
			(string) ($line['description'] ?: $line['label'])
		);

		if ($result < 0) {
			throw new RuntimeException($productFourn->error ?: 'Unable to update supplier price');
		}

		return $this->findSupplierPriceId($productId, (int) $supplier->id, (string) $line['vendor_ref'], $qtyForSupplierPrice);
	}

	/**
	 * Find supplier price row id.
	 *
	 * @param int    $productId  Product id
	 * @param int    $supplierId Supplier id
	 * @param string $vendorRef  Supplier ref
	 * @param float  $qty        Min quantity
	 * @return int
	 */
	private function findSupplierPriceId($productId, $supplierId, $vendorRef, $qty)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
		$sql .= ' WHERE entity IN ('.getEntity('productsupplierprice').')';
		$sql .= ' AND fk_product = '.((int) $productId);
		$sql .= ' AND fk_soc = '.((int) $supplierId);
		$sql .= " AND ref_fourn = '".$this->db->escape($vendorRef)."'";
		$sql .= ' AND quantity = '.price2num($qty, 'MS');
		$sql .= ' ORDER BY rowid DESC';
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Resolve product reference for a missing Rexel product.
	 *
	 * @param array<string,mixed> $line              Line
	 * @param array<int,string>   $manualProductRefs Manual product refs indexed by session line id
	 * @return string
	 */
	private function resolveProductRef($line, $manualProductRefs)
	{
		$mode = LmdbRexelPunchoutConfig::getProductRefMode();
		if ($mode === LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_DOLIBARR) {
			return $this->buildDolibarrProductRef($line);
		}
		if ($mode === LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_SUPPLIER_REF) {
			return $this->buildSupplierProductRef((string) $line['vendor_ref']);
		}
		if ($mode === LmdbRexelPunchoutConfig::PRODUCT_REF_MODE_MANUAL) {
			$lineId = (int) ($line['rowid'] ?? 0);
			$manualRef = $lineId > 0 && isset($manualProductRefs[$lineId]) ? $manualProductRefs[$lineId] : '';
			return $this->validateManualProductRef($manualRef, (string) $line['vendor_ref']);
		}

		return $this->buildPrefixedProductRef((string) $line['vendor_ref']);
	}

	/**
	 * Build product reference with the configured prefix.
	 *
	 * @param string $vendorRef Supplier ref
	 * @return string
	 */
	private function buildPrefixedProductRef($vendorRef)
	{
		return $this->truncate(LmdbRexelPunchoutConfig::getString('PRODUCT_REF_PREFIX', 'REXEL-').LmdbRexelPunchoutSecurity::normalizeSupplierReference($vendorRef), 128);
	}

	/**
	 * Build product reference from the Rexel supplier ref.
	 *
	 * @param string $vendorRef Supplier ref
	 * @return string
	 */
	private function buildSupplierProductRef($vendorRef)
	{
		return $this->truncate(LmdbRexelPunchoutSecurity::normalizeSupplierReference($vendorRef), 128);
	}

	/**
	 * Build product reference with the native Dolibarr product numbering module.
	 *
	 * @param array<string,mixed> $line Line
	 * @return string
	 */
	private function buildDolibarrProductRef($line)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/modules/product/modules_product.class.php';

		$module = '';
		if (function_exists('getDolGlobalString')) {
			$module = getDolGlobalString('PRODUCT_CODEPRODUCT_ADDON', 'mod_codeproduct_leopard');
		} elseif (!empty($conf->global->PRODUCT_CODEPRODUCT_ADDON)) {
			$module = (string) $conf->global->PRODUCT_CODEPRODUCT_ADDON;
		}
		if ($module === '') {
			$module = 'mod_codeproduct_leopard';
		}
		if (substr($module, 0, 16) === 'mod_codeproduct_' && substr($module, -4) === '.php') {
			$module = substr($module, 0, -4);
		}

		$included = function_exists('dol_include_once') ? dol_include_once('/core/modules/product/'.$module.'.php') : include_once DOL_DOCUMENT_ROOT.'/core/modules/product/'.$module.'.php';
		if ($included <= 0 || !class_exists($module)) {
			throw new RuntimeException($this->trans('LmdbRexelPunchoutProductNumberingModuleUnavailable', 'Product numbering module is unavailable').': '.$module);
		}

		$product = new Product($this->db);
		$product->type = Product::TYPE_PRODUCT;
		$product->entity = (int) $conf->entity;
		$product->label = $this->truncate((string) $line['label'], 255);
		$product->description = (string) ($line['description'] ?: $line['label']);

		/** @var ModeleProductCode $numbering */
		$numbering = new $module();
		$nextRef = trim((string) $numbering->getNextValue($product, Product::TYPE_PRODUCT));
		if ($nextRef === '' || strpos($nextRef, 'Function_getNextValue') !== false) {
			throw new RuntimeException($this->trans('LmdbRexelPunchoutProductNumberingReturnedEmpty', 'Product numbering module did not return a usable reference').': '.$module);
		}

		return $this->truncate($nextRef, 128);
	}

	/**
	 * Validate a manually entered product reference.
	 *
	 * @param string $manualRef Manual product reference
	 * @param string $vendorRef Supplier reference
	 * @return string
	 */
	private function validateManualProductRef($manualRef, $vendorRef)
	{
		$manualRef = trim($manualRef);
		if ($manualRef === '') {
			throw new RuntimeException($this->trans('LmdbRexelPunchoutManualProductRefRequired', 'Product reference is required for Rexel reference '.$vendorRef, $vendorRef));
		}
		if (preg_match('/[<>]/', $manualRef)) {
			throw new RuntimeException($this->trans('LmdbRexelPunchoutManualProductRefInvalid', 'Product reference contains invalid characters for Rexel reference '.$vendorRef, $vendorRef));
		}

		return $this->truncate($manualRef, 128);
	}

	/**
	 * Build supplier order line description.
	 *
	 * @param array<string,mixed> $line Line
	 * @return string
	 */
	private function buildLineDescription($line)
	{
		$description = (string) ($line['label'] ?: $line['vendor_ref']);
		if (!empty($line['description']) && $line['description'] !== $description) {
			$description .= "\n".$line['description'];
		}

		return $description;
	}

	/**
	 * Add cXML shipping fees as a supplier order line when requested.
	 *
	 * @param LmdbRexelPunchoutSession       $session Session
	 * @param CommandeFournisseur            $order   Supplier order
	 * @param array<int,array<string,mixed>> $lines   Normalized item lines
	 * @param array<string,mixed>            $summary Import summary
	 * @return void
	 */
	private function importCxmlShippingLine($session, $order, $lines, &$summary)
	{
		$basket = $this->decodeBasketPayload($session);
		$shipping = isset($basket['header']) && is_array($basket['header']) && isset($basket['header']['shipping']) && is_array($basket['header']['shipping']) ? $basket['header']['shipping'] : array();
		$shippingDetected = !empty($shipping['has_value']) || array_key_exists('amount', $shipping) || array_key_exists('currency', $shipping);
		if (!$shippingDetected) {
			$summary['shipping_skipped_reason'] = 'not_present';
			return;
		}

		$shippingAmount = (float) ($shipping['amount'] ?? 0);
		$expectedCurrency = LmdbRexelPunchoutConfig::getExpectedCurrency();
		$shippingCurrency = strtoupper((string) ($shipping['currency'] ?? $expectedCurrency));
		$shippingVatRate = $this->getShippingVatRate($lines);

		$summary['shipping_detected'] = true;
		$summary['shipping_amount'] = $shippingAmount;
		$summary['shipping_currency'] = $shippingCurrency;

		if ($shippingCurrency !== $expectedCurrency) {
			throw new RuntimeException('Unexpected cXML shipping currency: '.$shippingCurrency.' (expected '.$expectedCurrency.')');
		}

		if ($shippingAmount <= 0) {
			$summary['shipping_skipped_reason'] = 'zero_amount';
			return;
		}

		if (!LmdbRexelPunchoutConfig::getInt('CXML_IMPORT_SHIPPING', 1)) {
			$summary['shipping_skipped_reason'] = 'disabled';
			$summary['warnings'][] = $this->trans('LmdbRexelPunchoutShippingImportDisabled', 'cXML shipping fees were not imported because the option is disabled');
			return;
		}

		$result = $this->addCxmlChargeLine(
			$order,
			$this->buildShippingDescription($shipping),
			$shippingAmount,
			$shippingVatRate,
			LmdbRexelPunchoutConfig::getInt('CXML_SHIPPING_FK_PRODUCT'),
			'Configured cXML shipping product/service not found: #',
			'Unable to add cXML shipping supplier order line'
		);

		$summary['lines_added']++;
		$summary['shipping_lines_added'] = 1;
		$summary['shipping_order_line_id'] = (int) $result;
		$summary['shipping_skipped_reason'] = '';
	}

	/**
	 * Add cXML DEEE ecocontribution as a supplier order line when requested.
	 * Add an optional unqualified positive delta as a supplier order line.
	 *
	 * @param LmdbRexelPunchoutSession       $session Session
	 * @param CommandeFournisseur            $order   Supplier order
	 * @param array<int,array<string,mixed>> $lines   Normalized item lines
	 * @param array<string,mixed>            $summary Import summary
	 * @return void
	 */
	private function importCxmlDeeeLine($session, $order, $lines, &$summary)
	{
		$basket = $this->decodeBasketPayload($session);
		$deee = isset($basket['header']) && is_array($basket['header']) && isset($basket['header']['deee']) && is_array($basket['header']['deee']) ? $basket['header']['deee'] : array();
		$deeeDetected = !empty($deee['has_value']) || array_key_exists('amount', $deee) || array_key_exists('currency', $deee);
		if (!$deeeDetected) {
			$summary['deee_skipped_reason'] = 'not_present';
			return;
		}

		$deeeAmount = (float) ($deee['amount'] ?? 0);
		$expectedCurrency = LmdbRexelPunchoutConfig::getExpectedCurrency();
		$deeeCurrency = strtoupper((string) ($deee['currency'] ?? $expectedCurrency));
		$deeeVatRate = $this->getDeeeVatRate($lines);

		$summary['deee_detected'] = true;
		$summary['deee_amount'] = $deeeAmount;
		$summary['deee_currency'] = $deeeCurrency;

		if ($deeeCurrency !== $expectedCurrency) {
			throw new RuntimeException('Unexpected cXML DEEE currency: '.$deeeCurrency.' (expected '.$expectedCurrency.')');
		}

		if ($deeeAmount <= 0) {
			$summary['deee_skipped_reason'] = 'zero_amount';
			return;
		}

		if (!LmdbRexelPunchoutConfig::getInt('CXML_IMPORT_DEEE', 1)) {
			$summary['deee_skipped_reason'] = 'disabled';
			$summary['warnings'][] = $this->trans('LmdbRexelPunchoutDeeeImportDisabled', 'cXML DEEE ecocontribution was not imported because the option is disabled');
	private function importCxmlUnqualifiedDeltaLine($session, $order, $lines, &$summary)
	{
		$basket = $this->decodeBasketPayload($session);
		$expectedCurrency = LmdbRexelPunchoutConfig::getExpectedCurrency();
		$delta = LmdbRexelPunchoutBasket::calculateUnqualifiedDelta($basket, $expectedCurrency);

		$summary['unqualified_delta_currency'] = $delta['currency'];
		$summary['unqualified_delta_amount'] = $delta['amount'];

		if ($delta['currency'] !== $expectedCurrency) {
			throw new RuntimeException('Unexpected cXML total currency: '.$delta['currency'].' (expected '.$expectedCurrency.')');
		}

		if ($delta['amount'] < LmdbRexelPunchoutBasket::MIN_UNQUALIFIED_DELTA) {
			$summary['unqualified_delta_skipped_reason'] = ((float) $delta['total_amount'] > 0 || (float) $delta['lines_amount'] > 0) ? 'zero_amount' : 'not_present';
			return;
		}

		$summary['unqualified_delta_detected'] = true;

		if ($this->hasPositiveExplicitCharge($basket, $summary)) {
			$summary['unqualified_delta_skipped_reason'] = 'explicit_charge_present';
			return;
		}

		if (!LmdbRexelPunchoutConfig::getInt('CXML_IMPORT_UNQUALIFIED_DELTA', 0)) {
			$summary['unqualified_delta_skipped_reason'] = 'disabled';
			$summary['warnings'][] = $this->trans('LmdbRexelPunchoutUnqualifiedDeltaImportDisabled', 'Unqualified cXML delta was not imported because the option is disabled');
			return;
		}

		$result = $this->addCxmlChargeLine(
			$order,
			$this->buildDeeeDescription($deee),
			$deeeAmount,
			$deeeVatRate,
			LmdbRexelPunchoutConfig::getInt('CXML_DEEE_FK_PRODUCT'),
			'Configured cXML DEEE product/service not found: #',
			'Unable to add cXML DEEE supplier order line'
		);

		$summary['lines_added']++;
		$summary['deee_lines_added'] = 1;
		$summary['deee_order_line_id'] = (int) $result;
		$summary['deee_skipped_reason'] = '';
			$this->buildUnqualifiedDeltaDescription($delta),
			(float) $delta['amount'],
			$this->getUnqualifiedDeltaVatRate($lines),
			LmdbRexelPunchoutConfig::getInt('CXML_UNQUALIFIED_DELTA_FK_PRODUCT'),
			'Configured unqualified cXML delta product/service not found: #',
			'Unable to add unqualified cXML delta supplier order line'
		);

		$summary['lines_added']++;
		$summary['unqualified_delta_lines_added'] = 1;
		$summary['unqualified_delta_order_line_id'] = (int) $result;
		$summary['unqualified_delta_skipped_reason'] = '';
	}

	/**
	 * Add a supplier order charge line.
	 *
	 * @param CommandeFournisseur $order                 Supplier order
	 * @param string              $description           Line description
	 * @param float               $amount                Amount without tax
	 * @param float               $vatRate               VAT rate
	 * @param int                 $productId             Optional product/service id
	 * @param string              $missingProductMessage Missing product error prefix
	 * @param string              $addlineErrorMessage   Addline error fallback
	 * @return int
	 */
	private function addCxmlChargeLine($order, $description, $amount, $vatRate, $productId, $missingProductMessage, $addlineErrorMessage)
	{
		if ($productId > 0) {
			$product = new Product($this->db);
			if ($product->fetch($productId) <= 0) {
				throw new RuntimeException($missingProductMessage.$productId);
			}
		}

		$result = $order->addline(
			$description,
			$amount,
			1,
			$vatRate,
			0,
			0,
			$productId > 0 ? $productId : 0,
			0,
			'',
			0,
			'HT',
			0,
			0,
			0,
			0,
			null,
			null,
			array(),
			null
		);

		if ($result <= 0) {
			throw new RuntimeException($order->error ?: $addlineErrorMessage);
		}

		return (int) $result;
	}

	/**
	 * Decode the stored cXML basket metadata.
	 *
	 * @param LmdbRexelPunchoutSession $session Session
	 * @return array<string,mixed>
	 */
	private function decodeBasketPayload($session)
	{
		$json = (string) ($session->basket_payload ?? '');
		if ($json === '') {
			return array();
		}

		$payload = json_decode($json, true);
		return is_array($payload) ? $payload : array();
	}

	/**
	 * Build the supplier order description for shipping fees.
	 *
	 * @param array<string,mixed> $shipping Shipping metadata
	 * @return string
	 */
	private function buildShippingDescription($shipping)
	{
		$label = $this->trans('LmdbRexelPunchoutShippingLineLabel', 'Frais de port Rexel');
		$description = trim((string) ($shipping['description'] ?? ''));
		if ($description !== '' && $description !== $label) {
			$label .= "\n".$description;
		}

		return $label;
	}

	/**
	 * Build the supplier order description for DEEE ecocontribution.
	 *
	 * @param array<string,mixed> $deee DEEE metadata
	 * @return string
	 */
	private function buildDeeeDescription($deee)
	{
		$label = $this->trans('LmdbRexelPunchoutDeeeLineLabel', 'Écocontribution DEEE Rexel');
		$description = trim((string) ($deee['description'] ?? ''));
		if ($description !== '' && $description !== $label) {
			$label .= "\n".$description;
		}

		return $label;
	 * Build the supplier order description for an unqualified cXML delta.
	 *
	 * @param array{detected:bool,amount:float,currency:string,total_amount:float,lines_amount:float} $delta Delta metadata
	 * @return string
	 */
	private function buildUnqualifiedDeltaDescription($delta)
	{
		$label = $this->trans('LmdbRexelPunchoutUnqualifiedDeltaLineLabel', 'Frais divers Rexel non ventilés');
		$origin = $this->trans('LmdbRexelPunchoutUnqualifiedDeltaLineOrigin', 'Montant calculé depuis l’écart entre le total cXML du panier et les lignes article.');

		return $label."\n".$origin;
	}

	/**
	 * Resolve VAT rate for cXML shipping fees.
	 *
	 * @param array<int,array<string,mixed>> $lines Normalized item lines
	 * @return float
	 */
	private function getShippingVatRate($lines)
	{
		$configuredVat = trim(LmdbRexelPunchoutConfig::getString('CXML_SHIPPING_VAT_RATE'));
		if ($configuredVat !== '') {
			return LmdbRexelPunchoutParser::toFloat($configuredVat);
		}

		return $this->getCommonVatRate($lines);
	}

	/**
	 * Resolve VAT rate for cXML DEEE ecocontribution.
	 * Resolve VAT rate for unqualified cXML delta fees.
	 *
	 * @param array<int,array<string,mixed>> $lines Normalized item lines
	 * @return float
	 */
	private function getDeeeVatRate($lines)
	{
		$configuredVat = trim(LmdbRexelPunchoutConfig::getString('CXML_DEEE_VAT_RATE'));
	private function getUnqualifiedDeltaVatRate($lines)
	{
		$configuredVat = trim(LmdbRexelPunchoutConfig::getString('CXML_UNQUALIFIED_DELTA_VAT_RATE'));
		if ($configuredVat !== '') {
			return LmdbRexelPunchoutParser::toFloat($configuredVat);
		}

		return $this->getCommonVatRate($lines);
	}

	/**
=======
	 * Check whether a positive explicit charge already exists in the cXML basket or summary.
	 *
	 * @param array<string,mixed> $basket  Structured basket metadata
	 * @param array<string,mixed> $summary Import summary
	 * @return bool
	 */
	private function hasPositiveExplicitCharge($basket, $summary)
	{
		if (LmdbRexelPunchoutBasket::hasPositiveExplicitCharge($basket)) {
			return true;
		}

		foreach (array('shipping', 'deee') as $prefix) {
			if ((int) ($summary[$prefix.'_lines_added'] ?? 0) > 0) {
				return true;
			}
			if ((float) ($summary[$prefix.'_amount'] ?? 0) >= LmdbRexelPunchoutBasket::MIN_UNQUALIFIED_DELTA) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the common line VAT rate, or the default VAT when lines differ.
	 *
	 * @param array<int,array<string,mixed>> $lines Normalized item lines
	 * @return float
	 */
	private function getCommonVatRate($lines)
	{
		$commonVat = null;
		foreach ($lines as $line) {
			if (!isset($line['vat_rate'])) {
				continue;
			}
			$vatRate = (float) $line['vat_rate'];
			if ($commonVat === null) {
				$commonVat = $vatRate;
				continue;
			}
			if (abs($commonVat - $vatRate) > 0.000001) {
				return LmdbRexelPunchoutConfig::getFloat('DEFAULT_VAT', 20.0);
			}
		}

		return $commonVat !== null ? $commonVat : LmdbRexelPunchoutConfig::getFloat('DEFAULT_VAT', 20.0);
	}

	/**
	 * Translate a string when Dolibarr languages are loaded.
	 *
	 * @param string $key      Translation key
	 * @param string $fallback Fallback text
	 * @param string $param1   Optional translation parameter
	 * @return string
	 */
	private function trans($key, $fallback, $param1 = '')
	{
		global $langs;

		if (is_object($langs) && method_exists($langs, 'trans')) {
			$translated = $param1 !== '' ? $langs->trans($key, $param1) : $langs->trans($key);
			if ($translated !== $key) {
				return $translated;
			}
		}

		return $fallback;
	}

	/**
	 * Truncate text with Dolibarr helper when available.
	 *
	 * @param string $value Text
	 * @param int    $limit Max length
	 * @return string
	 */
	private function truncate($value, $limit)
	{
		if (function_exists('dol_trunc')) {
			return dol_trunc($value, $limit);
		}

		return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
	}
}
