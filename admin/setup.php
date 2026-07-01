<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Rexel Punchout setup page.
 */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
	$res = include '../../../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/lmdbrexelpunchout.lib.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutconfig.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutsecurity.class.php';

$langs->loadLangs(array('admin', 'companies', 'products', 'lmdbrexelpunchout@lmdbrexelpunchout'));

if (!$user->admin && !LmdbRexelPunchoutSecurity::canConfigure($user)) {
	accessforbidden();
}

$form = new Form($db);
$action = GETPOST('action', 'aZ09');
$rowid = GETPOSTINT('rowid');
$setupUrl = dol_buildpath('/lmdbrexelpunchout/admin/setup.php', 1);

if ($action === 'save_settings') {
	if (!LmdbRexelPunchoutSecurity::checkToken()) {
		accessforbidden('Bad token');
	}

	$settings = array(
		'FK_SOC' => (string) GETPOSTINT('LMDBREXELPUNCHOUT_FK_SOC'),
		'CXML_URL' => GETPOST('LMDBREXELPUNCHOUT_CXML_URL', 'restricthtml'),
		'CXML_CUSTOMER_DOMAIN' => GETPOST('LMDBREXELPUNCHOUT_CXML_CUSTOMER_DOMAIN', 'restricthtml'),
		'CXML_CUSTOMER_IDENTITY' => GETPOST('LMDBREXELPUNCHOUT_CXML_CUSTOMER_IDENTITY', 'restricthtml'),
		'CXML_SENDER_DOMAIN' => GETPOST('LMDBREXELPUNCHOUT_CXML_SENDER_DOMAIN', 'restricthtml'),
		'CXML_SENDER_IDENTITY' => GETPOST('LMDBREXELPUNCHOUT_CXML_SENDER_IDENTITY', 'restricthtml'),
		'CXML_SUPPLIER_DOMAIN' => GETPOST('LMDBREXELPUNCHOUT_CXML_SUPPLIER_DOMAIN', 'restricthtml'),
		'CXML_SUPPLIER_IDENTITY' => GETPOST('LMDBREXELPUNCHOUT_CXML_SUPPLIER_IDENTITY', 'restricthtml'),
		'CXML_MODE' => strtolower(GETPOST('LMDBREXELPUNCHOUT_CXML_MODE', 'alpha')),
		'CXML_LANG' => GETPOST('LMDBREXELPUNCHOUT_CXML_LANG', 'alphanohtml'),
		'CXML_SHIPPING_FK_PRODUCT' => (string) GETPOSTINT('LMDBREXELPUNCHOUT_CXML_SHIPPING_FK_PRODUCT'),
		'CXML_SHIPPING_VAT_RATE' => trim(GETPOST('LMDBREXELPUNCHOUT_CXML_SHIPPING_VAT_RATE', 'alphanohtml')),
		'OPEN_MODE' => strtolower(GETPOST('LMDBREXELPUNCHOUT_OPEN_MODE', 'alpha')),
		'CURRENCY' => strtoupper(GETPOST('LMDBREXELPUNCHOUT_CURRENCY', 'alpha')),
		'DEFAULT_VAT' => GETPOST('LMDBREXELPUNCHOUT_DEFAULT_VAT', 'alphanohtml'),
		'PRODUCT_REF_PREFIX' => GETPOST('LMDBREXELPUNCHOUT_PRODUCT_REF_PREFIX', 'alphanohtml'),
		'TOKEN_TTL' => (string) max(1, GETPOSTINT('LMDBREXELPUNCHOUT_TOKEN_TTL')),
		'RETENTION_DAYS' => (string) max(1, GETPOSTINT('LMDBREXELPUNCHOUT_RETENTION_DAYS')),
	);

	if (!in_array($settings['CXML_MODE'], array('test', 'production'), true)) {
		$settings['CXML_MODE'] = 'production';
	}
	if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $settings['CXML_LANG'])) {
		$settings['CXML_LANG'] = 'en-US';
	}
	if (!in_array($settings['OPEN_MODE'], array('iframe', 'popup', 'newtab'), true)) {
		$settings['OPEN_MODE'] = 'popup';
	}
	if (!preg_match('/^[A-Z]{3}$/', $settings['CURRENCY'])) {
		$settings['CURRENCY'] = 'EUR';
	}
	if ($settings['DEFAULT_VAT'] !== '' && !is_numeric(str_replace(',', '.', $settings['DEFAULT_VAT']))) {
		$settings['DEFAULT_VAT'] = '20';
	}
	if ($settings['PRODUCT_REF_PREFIX'] === '') {
		$settings['PRODUCT_REF_PREFIX'] = 'REXEL-';
	}
	if ($settings['CXML_SHIPPING_VAT_RATE'] !== '' && !is_numeric(str_replace(',', '.', $settings['CXML_SHIPPING_VAT_RATE']))) {
		$settings['CXML_SHIPPING_VAT_RATE'] = '';
	}

	foreach ($settings as $key => $value) {
		LmdbRexelPunchoutConfig::set($db, $key, $value);
	}

	$cxmlSecret = GETPOST('LMDBREXELPUNCHOUT_CXML_SHARED_SECRET', 'restricthtml');
	if ($cxmlSecret !== '') {
		LmdbRexelPunchoutConfig::setSecret($db, 'CXML_SHARED_SECRET', $cxmlSecret);
	}

	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$setupUrl);
	exit;
}

if ($action === 'save_unitmap') {
	if (!LmdbRexelPunchoutSecurity::checkToken()) {
		accessforbidden('Bad token');
	}

	$supplierUnit = strtoupper(trim(GETPOST('supplier_unit', 'alphanohtml')));
	$fkUnit = GETPOSTINT('fk_unit');
	$label = GETPOST('label', 'restricthtml');
	if ($supplierUnit === '') {
		setEventMessages($langs->trans('LmdbRexelPunchoutUnitCodeRequired'), null, 'errors');
	} elseif ($rowid > 0) {
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap SET';
		$sql .= " supplier_unit = '".$db->escape($supplierUnit)."'";
		$sql .= ', fk_unit = '.($fkUnit > 0 ? (int) $fkUnit : 'NULL');
		$sql .= ", label = '".$db->escape($label)."'";
		$sql .= ' WHERE rowid = '.((int) $rowid).' AND entity = '.((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		}
	} else {
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap (entity, supplier_unit, fk_unit, label)';
		$sql .= ' VALUES ('.((int) $conf->entity).", '".$db->escape($supplierUnit)."', ".($fkUnit > 0 ? (int) $fkUnit : 'NULL').", '".$db->escape($label)."')";
		$resql = $db->query($sql);
		if (!$resql) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		}
	}
	header('Location: '.$setupUrl);
	exit;
}

if ($action === 'delete_unitmap') {
	if (!LmdbRexelPunchoutSecurity::checkToken()) {
		accessforbidden('Bad token');
	}
	if ($rowid > 0) {
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap WHERE rowid = '.((int) $rowid).' AND entity = '.((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		}
	}
	header('Location: '.$setupUrl);
	exit;
}

llxHeader('', $langs->trans('LmdbRexelPunchoutSetup'));
lmdbrexelpunchoutPrintAdminHeader('settings');

$openModeOptions = array(
	'popup' => $langs->trans('LmdbRexelPunchoutOpenPopup'),
	'newtab' => $langs->trans('LmdbRexelPunchoutOpenNewTab'),
	'iframe' => $langs->trans('LmdbRexelPunchoutOpenIframe'),
);
$cxmlModeOptions = array(
	'production' => $langs->trans('Production'),
	'test' => $langs->trans('Test'),
);
$shippingProductOptions = getProductServiceOptions($db);

print '<form method="POST" action="'.$setupUrl.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_settings">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbRexelPunchoutGeneralSettings').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('LmdbRexelPunchoutProtocol').'</td><td><span class="badge badge-status4">cXML</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutSupplier').'</td><td>';
if (method_exists($form, 'select_company')) {
	print $form->select_company(LmdbRexelPunchoutConfig::getInt('FK_SOC'), 'LMDBREXELPUNCHOUT_FK_SOC', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
} else {
	print '<input class="flat maxwidth100" type="text" name="LMDBREXELPUNCHOUT_FK_SOC" value="'.LmdbRexelPunchoutConfig::getInt('FK_SOC').'">';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutOpenMode').'</td><td>'.$form->selectarray('LMDBREXELPUNCHOUT_OPEN_MODE', $openModeOptions, LmdbRexelPunchoutConfig::getOpenMode(), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Currency').'</td><td><input class="flat width50" name="LMDBREXELPUNCHOUT_CURRENCY" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getExpectedCurrency()).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DefaultVATRate').'</td><td><input class="flat width50" name="LMDBREXELPUNCHOUT_DEFAULT_VAT" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('DEFAULT_VAT', '20')).'"> %</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCreateProducts').'</td><td>'.(function_exists('ajax_constantonoff') ? ajax_constantonoff('LMDBREXELPUNCHOUT_CREATE_PRODUCTS', array(), null, 0, 0, 0, 2, 0, 1) : $langs->trans(LmdbRexelPunchoutConfig::getInt('CREATE_PRODUCTS', 1) ? 'Yes' : 'No')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutAllowZeroPrice').'</td><td>'.(function_exists('ajax_constantonoff') ? ajax_constantonoff('LMDBREXELPUNCHOUT_ALLOW_ZERO_PRICE') : $langs->trans(LmdbRexelPunchoutConfig::getInt('ALLOW_ZERO_PRICE', 0) ? 'Yes' : 'No')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutProductRefPrefix').'</td><td><input class="flat minwidth100" name="LMDBREXELPUNCHOUT_PRODUCT_REF_PREFIX" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('PRODUCT_REF_PREFIX', 'REXEL-')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutTokenTtl').'</td><td><input class="flat width50" name="LMDBREXELPUNCHOUT_TOKEN_TTL" value="'.LmdbRexelPunchoutConfig::getInt('TOKEN_TTL', 30).'"> '.$langs->trans('Minutes').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutRetentionDays').'</td><td><input class="flat width50" name="LMDBREXELPUNCHOUT_RETENTION_DAYS" value="'.LmdbRexelPunchoutConfig::getInt('RETENTION_DAYS', 30).'"> '.$langs->trans('days').'</td></tr>';

print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbRexelPunchoutCxmlSettings').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlUrl').'</td><td><input class="flat minwidth500" name="LMDBREXELPUNCHOUT_CXML_URL" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_URL')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlSharedSecret').'</td><td><input class="flat minwidth200" type="password" autocomplete="new-password" name="LMDBREXELPUNCHOUT_CXML_SHARED_SECRET" value="" placeholder="'.(LmdbRexelPunchoutConfig::getSecret('CXML_SHARED_SECRET') !== '' ? dol_escape_htmltag($langs->trans('LmdbRexelPunchoutSecretAlreadySaved')) : '').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlCustomerDomain').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_CUSTOMER_DOMAIN" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_DOMAIN')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlCustomerIdentity').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_CUSTOMER_IDENTITY" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_IDENTITY')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlSenderDomain').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_SENDER_DOMAIN" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_SENDER_DOMAIN')).'"> <span class="opacitymedium">'.$langs->trans('LmdbRexelPunchoutCxmlSenderFallbackHelp').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlSenderIdentity').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_SENDER_IDENTITY" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_SENDER_IDENTITY')).'"> <span class="opacitymedium">'.$langs->trans('LmdbRexelPunchoutCxmlSenderFallbackHelp').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlSupplierDomain').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_SUPPLIER_DOMAIN" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_SUPPLIER_DOMAIN')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlSupplierIdentity').'</td><td><input class="flat minwidth200" name="LMDBREXELPUNCHOUT_CXML_SUPPLIER_IDENTITY" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_SUPPLIER_IDENTITY')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Language').'</td><td><input class="flat maxwidth100" name="LMDBREXELPUNCHOUT_CXML_LANG" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_LANG', 'en-US')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Mode').'</td><td>'.$form->selectarray('LMDBREXELPUNCHOUT_CXML_MODE', $cxmlModeOptions, LmdbRexelPunchoutConfig::getString('CXML_MODE', 'production'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth150').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlImportShipping').'</td><td>'.(function_exists('ajax_constantonoff') ? ajax_constantonoff('LMDBREXELPUNCHOUT_CXML_IMPORT_SHIPPING', array(), null, 0, 0, 0, 2, 0, 1) : $langs->trans(LmdbRexelPunchoutConfig::getInt('CXML_IMPORT_SHIPPING', 1) ? 'Yes' : 'No')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlShippingProduct').'</td><td>'.$form->selectarray('LMDBREXELPUNCHOUT_CXML_SHIPPING_FK_PRODUCT', $shippingProductOptions, LmdbRexelPunchoutConfig::getInt('CXML_SHIPPING_FK_PRODUCT'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300').' <span class="opacitymedium">'.$langs->trans('LmdbRexelPunchoutCxmlShippingProductHelp').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbRexelPunchoutCxmlShippingVatRate').'</td><td><input class="flat width50" name="LMDBREXELPUNCHOUT_CXML_SHIPPING_VAT_RATE" value="'.dol_escape_htmltag(LmdbRexelPunchoutConfig::getString('CXML_SHIPPING_VAT_RATE')).'"> % <span class="opacitymedium">'.$langs->trans('LmdbRexelPunchoutCxmlShippingVatRateHelp').'</span></td></tr>';
print '</table>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

if (function_exists('ajax_combobox')) {
	foreach (array('LMDBREXELPUNCHOUT_FK_SOC', 'LMDBREXELPUNCHOUT_OPEN_MODE', 'LMDBREXELPUNCHOUT_CXML_MODE', 'LMDBREXELPUNCHOUT_CXML_SHIPPING_FK_PRODUCT') as $htmlname) {
		ajax_combobox($htmlname);
	}
}

print '<br>';
print load_fiche_titre($langs->trans('LmdbRexelPunchoutUnitMapping'), '', '');
renderUnitMapTable($db, $form);

print dol_get_fiche_end();
llxFooter();

/**
 * Render unit mapping table.
 *
 * @param DoliDB $db   Database handler
 * @param Form   $form Form helper
 * @return void
 */
function renderUnitMapTable($db, $form)
{
	global $conf, $langs;

	$unitOptions = getUnitOptions($db);
	$sql = 'SELECT rowid, supplier_unit, fk_unit, label FROM '.MAIN_DB_PREFIX.'lmdbrexelpunchout_unitmap';
	$sql .= ' WHERE entity = '.((int) $conf->entity);
	$sql .= ' ORDER BY supplier_unit ASC';
	$resql = $db->query($sql);

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('LmdbRexelPunchoutUnitCode').'</th><th>'.$langs->trans('Unit').'</th><th>'.$langs->trans('Label').'</th><th></th></tr>';
	if ($resql && $db->num_rows($resql) > 0) {
		while ($obj = $db->fetch_object($resql)) {
			print '<tr class="oddeven">';
			print '<form method="POST" action="'.dol_buildpath('/lmdbrexelpunchout/admin/setup.php', 1).'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="save_unitmap">';
			print '<input type="hidden" name="rowid" value="'.((int) $obj->rowid).'">';
			print '<td><input class="flat maxwidth100" name="supplier_unit" value="'.dol_escape_htmltag($obj->supplier_unit).'"></td>';
			print '<td>'.$form->selectarray('fk_unit', $unitOptions, (int) $obj->fk_unit, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td>';
			print '<td><input class="flat minwidth300" name="label" value="'.dol_escape_htmltag($obj->label).'"></td>';
			print '<td class="right"><input class="button button-save small" type="submit" value="'.$langs->trans('Save').'"> ';
			print '<a class="button button-delete small" href="'.dol_buildpath('/lmdbrexelpunchout/admin/setup.php', 1).'?action=delete_unitmap&rowid='.((int) $obj->rowid).'&token='.newToken().'">'.$langs->trans('Delete').'</a></td>';
			print '</form>';
			print '</tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('Add').'</td></tr>';
	print '<tr class="oddeven">';
	print '<form method="POST" action="'.dol_buildpath('/lmdbrexelpunchout/admin/setup.php', 1).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save_unitmap">';
	print '<td><input class="flat maxwidth100" name="supplier_unit" value=""></td>';
	print '<td>'.$form->selectarray('fk_unit', $unitOptions, 0, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td>';
	print '<td><input class="flat minwidth300" name="label" value=""></td>';
	print '<td class="right"><input class="button button-add small" type="submit" value="'.$langs->trans('Add').'"></td>';
	print '</form>';
	print '</tr>';
	print '</table>';

	if (function_exists('ajax_combobox')) {
		ajax_combobox('fk_unit');
	}
}

/**
 * Load purchasable products/services for cXML shipping mapping.
 *
 * @param DoliDB $db Database handler
 * @return array<int,string>
 */
function getProductServiceOptions($db)
{
	global $conf, $langs;

	$options = array();
	$entitySql = function_exists('getEntity') ? getEntity('product') : (string) ((int) $conf->entity);
	$sql = 'SELECT rowid, ref, label, fk_product_type FROM '.MAIN_DB_PREFIX.'product';
	$sql .= ' WHERE entity IN ('.$entitySql.')';
	$sql .= ' AND tobuy = 1';
	$sql .= ' ORDER BY ref ASC';

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$typeLabel = ((int) $obj->fk_product_type === 1) ? $langs->trans('Service') : $langs->trans('Product');
			$options[(int) $obj->rowid] = $obj->ref.' - '.$obj->label.' ('.$typeLabel.')';
		}
	} elseif (function_exists('dol_syslog')) {
		dol_syslog('lmdbrexelpunchout getProductServiceOptions SQL error: '.$db->lasterror(), LOG_ERR);
	}

	return $options;
}

/**
 * Load Dolibarr units.
 *
 * @param DoliDB $db Database handler
 * @return array<int,string>
 */
function getUnitOptions($db)
{
	global $langs;

	$options = array();
	$sql = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.'c_units WHERE active = 1 ORDER BY sortorder, label';
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$options[(int) $obj->rowid] = $obj->code.' - '.$langs->trans($obj->label);
		}
	}

	return $options;
}
