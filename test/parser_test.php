<?php
/**
 * Lightweight cXML parser examples.
 *
 * This test is intentionally self-contained and does not mutate Dolibarr data.
 */

if (!function_exists('price2num')) {
	/**
	 * @param mixed  $value Value
	 * @param string $mode  Mode
	 * @return string
	 */
	function price2num($value, $mode = '')
	{
		return (string) $value;
	}
}
if (!function_exists('dol_trunc')) {
	/**
	 * @param string $value  Text
	 * @param int    $length Max length
	 * @return string
	 */
	function dol_trunc($value, $length)
	{
		return substr($value, 0, $length);
	}
}
if (!function_exists('getDolGlobalString')) {
	/**
	 * @param string $key     Constant key
	 * @param string $default Default value
	 * @return string
	 */
	function getDolGlobalString($key, $default = '')
	{
		$defaults = array(
			'LMDBREXELPUNCHOUT_DEFAULT_VAT' => '20',
			'LMDBREXELPUNCHOUT_CURRENCY' => 'EUR',
			'LMDBREXELPUNCHOUT_CXML_CUSTOMER_DOMAIN' => 'NetworkID',
			'LMDBREXELPUNCHOUT_CXML_CUSTOMER_IDENTITY' => 'buyer',
			'LMDBREXELPUNCHOUT_CXML_SUPPLIER_DOMAIN' => 'DUNS',
			'LMDBREXELPUNCHOUT_CXML_SUPPLIER_IDENTITY' => 'supplier',
			'LMDBREXELPUNCHOUT_CXML_SENDER_DOMAIN' => 'NetworkID',
			'LMDBREXELPUNCHOUT_CXML_SENDER_IDENTITY' => 'sender',
			'LMDBREXELPUNCHOUT_CXML_SHARED_SECRET' => 'secret',
			'LMDBREXELPUNCHOUT_CXML_URL' => 'https://example.invalid/cxml',
			'LMDBREXELPUNCHOUT_CXML_MODE' => 'test',
			'LMDBREXELPUNCHOUT_CXML_LANG' => 'en-US',
		);

		return $defaults[$key] ?? $default;
	}
}
if (!function_exists('getDolGlobalInt')) {
	/**
	 * @param string $key     Constant key
	 * @param int    $default Default value
	 * @return int
	 */
	function getDolGlobalInt($key, $default = 0)
	{
		return (int) getDolGlobalString($key, (string) $default);
	}
}

require_once __DIR__.'/../class/lmdbrexelpunchoutparser.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutcxmlclient.class.php';
require_once __DIR__.'/../class/lmdbrexelpunchoutcxmlpayload.class.php';

$parser = new LmdbRexelPunchoutParser();

$cxml = '<?xml version="1.0"?><cXML><Message><PunchOutOrderMessage><BuyerCookie>buyer-cookie</BuyerCookie><PunchOutOrderMessageHeader><Total><Money currency="EUR">19.40</Money></Total><ShipTo><Address addressID="ADDR1"><Name xml:lang="fr">Adresse principale</Name><PostalAddress><DeliverTo>Magasin</DeliverTo><Street>4 RUE ALFRED KASTLER</Street><Street>Bâtiment A</Street><City>MIOS</City><State>Gironde</State><PostalCode>33380</PostalCode><Country isoCountryCode="FR">France</Country></PostalAddress></Address></ShipTo><Shipping><Money currency="EUR">5.00</Money><Description xml:lang="fr">Transport</Description></Shipping><Tax><Money currency="EUR">3.40</Money><Description xml:lang="fr">TVA</Description></Tax><Extrinsic name="ecoContribution">1,23 EUR</Extrinsic></PunchOutOrderMessageHeader><ItemIn quantity="2" lineNumber="10"><ItemID><SupplierPartID>0890108715063</SupplierPartID><SupplierPartAuxiliaryID>AUX-1</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">7.00</Money></UnitPrice><Description xml:lang="fr"><ShortName>Disjoncteur</ShortName>Disjoncteur modulaire 10A</Description><UnitOfMeasure>EA</UnitOfMeasure><Classification domain="UNSPSC">39121603</Classification></ItemDetail><Tax><Money currency="EUR">2.80</Money><TaxDetail category="FullTax" percentageRate="20.000"></TaxDetail></Tax></ItemIn></PunchOutOrderMessage></Message></cXML>';

$cxmlLines = $parser->parseCxml($cxml);
if (count($cxmlLines) !== 1 || $cxmlLines[0]['vendor_ref'] !== '0890108715063' || abs($cxmlLines[0]['unit_price_ht'] - 7.0) > 0.000001) {
	throw new RuntimeException('cXML parser line test failed');
}
if ($cxmlLines[0]['source_line_number'] !== '10' || $cxmlLines[0]['supplier_part_auxiliary_id'] !== 'AUX-1') {
	throw new RuntimeException('cXML line metadata parser test failed');
}
if ($cxmlLines[0]['classification_domain'] !== 'UNSPSC' || $cxmlLines[0]['classification'] !== '39121603') {
	throw new RuntimeException('cXML classification parser test failed');
}
if (abs($cxmlLines[0]['tax_amount'] - 2.8) > 0.000001 || $cxmlLines[0]['tax_currency'] !== 'EUR') {
	throw new RuntimeException('cXML line tax parser test failed');
}

$cxmlItemOut = str_replace(array('<ItemIn ', '</ItemIn>'), array('<ItemOut ', '</ItemOut>'), $cxml);
$cxmlItemOutLines = $parser->parseCxml($cxmlItemOut);
if (count($cxmlItemOutLines) !== 1 || $cxmlItemOutLines[0]['vendor_ref'] !== '0890108715063') {
	throw new RuntimeException('cXML ItemOut parser test failed');
}

$cxmlBuyerPart = str_replace('<SupplierPartID>0890108715063</SupplierPartID>', '<SupplierPartID></SupplierPartID><BuyerPartID>BUY-0890108715063</BuyerPartID>', $cxml);
$cxmlBuyerPartLines = $parser->parseCxml($cxmlBuyerPart);
if (count($cxmlBuyerPartLines) !== 1 || $cxmlBuyerPartLines[0]['vendor_ref'] !== 'BUY-0890108715063') {
	throw new RuntimeException('cXML fallback reference parser test failed');
}

$cxmlBasket = $parser->parseCxmlBasket($cxml);
if (count($cxmlBasket['lines']) !== 1 || abs($cxmlBasket['header']['shipping']['amount'] - 5.0) > 0.000001 || $cxmlBasket['header']['shipping']['description'] !== 'Transport') {
	throw new RuntimeException('cXML basket shipping test failed');
}
if (abs($cxmlBasket['header']['deee']['amount'] - 1.23) > 0.000001 || $cxmlBasket['header']['deee']['currency'] !== 'EUR' || empty($cxmlBasket['header']['deee']['has_value'])) {
	throw new RuntimeException('cXML basket DEEE test failed');
}
if (abs($cxmlBasket['header']['total']['amount'] - 19.4) > 0.000001 || abs($cxmlBasket['header']['tax']['amount'] - 3.4) > 0.000001) {
	throw new RuntimeException('cXML basket total/tax test failed');
}
if ($cxmlBasket['header']['ship_to']['zip'] !== '33380' || $cxmlBasket['header']['ship_to']['town'] !== 'MIOS' || $cxmlBasket['header']['ship_to']['country_code'] !== 'FR') {
	throw new RuntimeException('cXML ShipTo parser test failed');
}
if ($cxmlBasket['header']['ship_to']['address'] !== "4 RUE ALFRED KASTLER\nBâtiment A") {
	throw new RuntimeException('cXML ShipTo street parser test failed');
}

$cxmlNoShipping = str_replace('<Shipping><Money currency="EUR">5.00</Money><Description xml:lang="fr">Transport</Description></Shipping>', '', $cxml);
$noShippingBasket = $parser->parseCxmlBasket($cxmlNoShipping);
if (!empty($noShippingBasket['header']['shipping']['has_value']) || abs($noShippingBasket['header']['shipping']['amount']) > 0.000001) {
	throw new RuntimeException('cXML no shipping parser test failed');
}

$cxmlNoDeee = str_replace('<Extrinsic name="ecoContribution">1,23 EUR</Extrinsic>', '', $cxml);
$noDeeeBasket = $parser->parseCxmlBasket($cxmlNoDeee);
if (!empty($noDeeeBasket['header']['deee']['has_value']) || abs($noDeeeBasket['header']['deee']['amount']) > 0.000001) {
	throw new RuntimeException('cXML no DEEE parser test failed');
}

$client = new LmdbRexelPunchoutCxmlClient();
$setupXml = $client->buildSetupRequest('https://dolibarr.example/custom/lmdbrexelpunchout/public/return_cxml.php?entity=1&token=test', 'buyer-cookie');
$setupDoc = new DOMDocument();
if (!$setupDoc->loadXML($setupXml)) {
	throw new RuntimeException('cXML setup request is not valid XML');
}
$setupXPath = new DOMXPath($setupDoc);
if ($setupDoc->doctype === null || $setupDoc->doctype->systemId !== LmdbRexelPunchoutCxmlClient::CXML_DTD) {
	throw new RuntimeException('cXML setup request missing expected DTD');
}
if ($setupDoc->documentElement === null || $setupDoc->documentElement->getAttribute('version') !== LmdbRexelPunchoutCxmlClient::CXML_VERSION) {
	throw new RuntimeException('cXML setup request missing expected version');
}
if ($setupXPath->query('/cXML/Header/Sender/Credential/SharedSecret')->length !== 1) {
	throw new RuntimeException('cXML setup request missing Sender Credential SharedSecret');
}
if ($setupXPath->query('/cXML/Request/PunchOutSetupRequest/BrowserFormPost/URL')->item(0)->textContent !== 'https://dolibarr.example/custom/lmdbrexelpunchout/public/return_cxml.php?entity=1&token=test') {
	throw new RuntimeException('cXML setup request missing BrowserFormPost URL');
}
if ($setupXPath->query('/cXML/Request/PunchOutSetupRequest/BuyerCookie')->item(0)->textContent !== 'buyer-cookie') {
	throw new RuntimeException('cXML setup request missing BuyerCookie');
}

$acceptedSetupXml = '<?xml version="1.0"?><cXML><Response><Status code="200" text="OK"/><PunchOutSetupResponse><StartPage><URL>https://shop.example/start</URL></StartPage></PunchOutSetupResponse></Response></cXML>';
if ($client->parseStartPageUrl($acceptedSetupXml, 200, 'text/xml', 'https://example.invalid/cxml') !== 'https://shop.example/start') {
	throw new RuntimeException('cXML setup response StartPage parser test failed');
}

$rejectedSetupXml = '<?xml version="1.0"?><cXML><Response><Status code="401" text="Unauthorized"/></Response></cXML>';
try {
	$client->parseStartPageUrl($rejectedSetupXml, 200, 'text/xml', 'https://example.invalid/cxml');
	throw new RuntimeException('cXML rejected setup response test failed');
} catch (RuntimeException $exception) {
	if (strpos($exception->getMessage(), 'cXML setup rejected: 401 Unauthorized') === false) {
		throw $exception;
	}
}

$urlencodedPayload = LmdbRexelPunchoutCxmlPayload::extract(array('cXML-urlencoded' => $cxml));
if ($urlencodedPayload !== $cxml) {
	throw new RuntimeException('cXML-urlencoded return extraction failed');
}

$base64Payload = LmdbRexelPunchoutCxmlPayload::extract(array('CXML-BASE64' => base64_encode($cxml)));
if ($base64Payload !== $cxml) {
	throw new RuntimeException('cXML-base64 return extraction failed');
}

$base64FirstPayload = LmdbRexelPunchoutCxmlPayload::extract(array('cXML-urlencoded' => '<invalid/>', 'cXML-base64' => base64_encode($cxml)));
if ($base64FirstPayload !== $cxml) {
	throw new RuntimeException('cXML-base64 return extraction priority failed');
}

echo "cXML parser examples OK\n";
