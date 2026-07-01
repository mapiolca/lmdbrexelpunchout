<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';

/**
 * Parse cXML PunchOutOrderMessage payloads into normalized line structures.
 */
class LmdbRexelPunchoutParser
{
	/**
	 * Parse cXML payload and return normalized lines.
	 *
	 * @param string $xml Raw cXML
	 * @return array<int,array<string,mixed>>
	 */
	public function parseCxml($xml)
	{
		$basket = $this->parseCxmlBasket($xml);
		return $basket['lines'];
	}

	/**
	 * Parse cXML payload with order header metadata.
	 *
	 * @param string $xml Raw cXML
	 * @return array{header:array<string,mixed>,lines:array<int,array<string,mixed>>}
	 */
	public function parseCxmlBasket($xml)
	{
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			throw new InvalidArgumentException('Invalid cXML payload');
		}

		$xpath = new DOMXPath($doc);
		$headerNode = $xpath->query('//*[local-name()="PunchOutOrderMessageHeader"]')->item(0);
		$header = $this->parseCxmlHeader($xpath, $headerNode);
		$items = $xpath->query('//*[local-name()="ItemIn"]');
		$lines = array();

		foreach ($items as $item) {
			$vendorRef = $this->xpathText($xpath, './/*[local-name()="SupplierPartID"]', $item);
			if ($vendorRef === '') {
				continue;
			}

			$moneyNode = $xpath->query('.//*[local-name()="UnitPrice"]//*[local-name()="Money"]', $item)->item(0);
			$currency = $moneyNode instanceof DOMElement && $moneyNode->hasAttribute('currency') ? strtoupper($moneyNode->getAttribute('currency')) : LmdbRexelPunchoutConfig::getExpectedCurrency();
			$price = $moneyNode ? self::toFloat($moneyNode->textContent) : 0.0;
			$shortName = $this->xpathText($xpath, './/*[local-name()="ShortName"]', $item);
			$description = $this->xpathText($xpath, './/*[local-name()="Description"]', $item);
			$taxNode = $xpath->query('.//*[local-name()="TaxDetail"]', $item)->item(0);
			$vatRate = LmdbRexelPunchoutConfig::getFloat('DEFAULT_VAT', 20.0);
			if ($taxNode instanceof DOMElement && $taxNode->hasAttribute('percentageRate')) {
				$vatRate = self::toFloat($taxNode->getAttribute('percentageRate'));
			}
			$lineTax = $this->parseMoney($xpath, './/*[local-name()="Tax"]/*[local-name()="Money"]', $item);
			$classificationNode = $xpath->query('.//*[local-name()="Classification"]', $item)->item(0);
			$classificationDomain = $classificationNode instanceof DOMElement && $classificationNode->hasAttribute('domain') ? $classificationNode->getAttribute('domain') : '';
			$sourceLineNumber = $item instanceof DOMElement && $item->hasAttribute('lineNumber') ? $item->getAttribute('lineNumber') : '';

			$lines[] = array(
				'vendor_ref' => trim($vendorRef),
				'external_id' => trim($vendorRef),
				'label' => $shortName !== '' ? $shortName : $this->truncate($description, 255),
				'description' => $description,
				'qty' => self::toFloat(($item instanceof DOMElement && $item->hasAttribute('quantity')) ? $item->getAttribute('quantity') : 1),
				'unit' => $this->xpathText($xpath, './/*[local-name()="UnitOfMeasure"]', $item),
				'price' => $price,
				'price_unit' => 1,
				'unit_price_ht' => $price,
				'currency' => $currency,
				'leadtime_days' => 0,
				'vat_rate' => $vatRate,
				'source_line_number' => trim($sourceLineNumber),
				'supplier_part_auxiliary_id' => $this->xpathText($xpath, './/*[local-name()="SupplierPartAuxiliaryID"]', $item),
				'classification_domain' => trim($classificationDomain),
				'classification' => $classificationNode ? trim($classificationNode->textContent) : '',
				'tax_amount' => (float) $lineTax['amount'],
				'tax_currency' => (string) $lineTax['currency'],
			);
		}

		return array(
			'header' => $header,
			'lines' => $this->aggregateLines($lines),
		);
	}

	/**
	 * Aggregate identical basket lines.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines
	 * @return array<int,array<string,mixed>>
	 */
	public function aggregateLines($lines)
	{
		$aggregated = array();
		foreach ($lines as $line) {
			$key = implode('|', array(
				(string) $line['vendor_ref'],
				(string) $line['unit'],
				price2num((float) $line['unit_price_ht'], 'MU'),
				price2num((float) $line['vat_rate'], 'MT'),
				(string) $line['currency'],
			));

			if (!isset($aggregated[$key])) {
				$aggregated[$key] = $line;
				continue;
			}

			$aggregated[$key]['qty'] += (float) $line['qty'];
			$aggregated[$key]['tax_amount'] = (float) ($aggregated[$key]['tax_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0);
			foreach (array('source_line_number', 'supplier_part_auxiliary_id') as $field) {
				$aggregated[$key][$field] = $this->mergeTextValues((string) ($aggregated[$key][$field] ?? ''), (string) ($line[$field] ?? ''));
			}
			foreach (array('classification_domain', 'classification', 'tax_currency') as $field) {
				if (empty($aggregated[$key][$field]) && !empty($line[$field])) {
					$aggregated[$key][$field] = $line[$field];
				}
			}
		}

		return array_values($aggregated);
	}

	/**
	 * Convert scalar value to float.
	 *
	 * @param mixed $value Value
	 * @return float
	 */
	public static function toFloat($value)
	{
		$value = trim((string) $value);
		$value = str_replace(array(' ', ','), array('', '.'), $value);
		return is_numeric($value) ? (float) $value : 0.0;
	}

	/**
	 * Read XPath text.
	 *
	 * @param DOMXPath $xpath XPath object
	 * @param string   $query Query
	 * @param DOMNode  $ctx   Context
	 * @return string
	 */
	private function xpathText($xpath, $query, $ctx)
	{
		$node = $xpath->query($query, $ctx)->item(0);
		return $node ? trim($node->textContent) : '';
	}

	/**
	 * Parse cXML order header.
	 *
	 * @param DOMXPath     $xpath      XPath object
	 * @param DOMNode|null $headerNode Header node
	 * @return array<string,mixed>
	 */
	private function parseCxmlHeader($xpath, $headerNode)
	{
		if (!$headerNode) {
			return array(
				'total' => $this->emptyMoney(),
				'shipping' => $this->emptyMoney(false),
				'tax' => $this->emptyMoney(),
				'ship_to' => array(),
			);
		}

		$total = $this->parseMoney($xpath, './*[local-name()="Total"]/*[local-name()="Money"]', $headerNode);
		$shipping = $this->parseMoney($xpath, './*[local-name()="Shipping"]/*[local-name()="Money"]', $headerNode);
		$shipping['description'] = $this->xpathText($xpath, './*[local-name()="Shipping"]/*[local-name()="Description"]', $headerNode);
		$tax = $this->parseMoney($xpath, './*[local-name()="Tax"]/*[local-name()="Money"]', $headerNode);
		$tax['description'] = $this->xpathText($xpath, './*[local-name()="Tax"]/*[local-name()="Description"]', $headerNode);

		return array(
			'total' => $total,
			'shipping' => $shipping,
			'tax' => $tax,
			'ship_to' => $this->parseShipTo($xpath, $headerNode),
		);
	}

	/**
	 * Parse cXML Money node.
	 *
	 * @param DOMXPath $xpath XPath object
	 * @param string   $query Query
	 * @param DOMNode  $ctx   Context
	 * @return array{amount:float,currency:string,has_value:bool}
	 */
	private function parseMoney($xpath, $query, $ctx)
	{
		$node = $xpath->query($query, $ctx)->item(0);
		if (!$node) {
			return $this->emptyMoney(false);
		}

		$currency = $node instanceof DOMElement && $node->hasAttribute('currency') ? strtoupper($node->getAttribute('currency')) : LmdbRexelPunchoutConfig::getExpectedCurrency();

		return array(
			'amount' => self::toFloat($node->textContent),
			'currency' => $currency,
			'has_value' => true,
		);
	}

	/**
	 * Return an empty Money structure.
	 *
	 * @param bool $hasValue Whether the empty value is explicit
	 * @return array{amount:float,currency:string,has_value:bool}
	 */
	private function emptyMoney($hasValue = false)
	{
		return array(
			'amount' => 0.0,
			'currency' => LmdbRexelPunchoutConfig::getExpectedCurrency(),
			'has_value' => $hasValue,
		);
	}

	/**
	 * Parse cXML ShipTo address as metadata.
	 *
	 * @param DOMXPath $xpath XPath object
	 * @param DOMNode  $ctx   Context
	 * @return array<string,string>
	 */
	private function parseShipTo($xpath, $ctx)
	{
		$addressNode = $xpath->query('./*[local-name()="ShipTo"]/*[local-name()="Address"]', $ctx)->item(0);
		if (!$addressNode) {
			return array();
		}

		$streets = array();
		$streetNodes = $xpath->query('.//*[local-name()="PostalAddress"]/*[local-name()="Street"]', $addressNode);
		foreach ($streetNodes as $streetNode) {
			$street = trim($streetNode->textContent);
			if ($street !== '') {
				$streets[] = $street;
			}
		}

		$countryNode = $xpath->query('.//*[local-name()="PostalAddress"]/*[local-name()="Country"]', $addressNode)->item(0);
		$countryCode = $countryNode instanceof DOMElement && $countryNode->hasAttribute('isoCountryCode') ? strtoupper($countryNode->getAttribute('isoCountryCode')) : '';

		return array(
			'address_id' => $addressNode instanceof DOMElement && $addressNode->hasAttribute('addressID') ? $addressNode->getAttribute('addressID') : '',
			'name' => $this->xpathText($xpath, './*[local-name()="Name"]', $addressNode),
			'deliver_to' => $this->xpathText($xpath, './/*[local-name()="PostalAddress"]/*[local-name()="DeliverTo"]', $addressNode),
			'address' => implode("\n", $streets),
			'town' => $this->xpathText($xpath, './/*[local-name()="PostalAddress"]/*[local-name()="City"]', $addressNode),
			'state' => $this->xpathText($xpath, './/*[local-name()="PostalAddress"]/*[local-name()="State"]', $addressNode),
			'zip' => $this->xpathText($xpath, './/*[local-name()="PostalAddress"]/*[local-name()="PostalCode"]', $addressNode),
			'country_code' => $countryCode,
			'country_label' => $countryNode ? trim($countryNode->textContent) : '',
		);
	}

	/**
	 * Merge two comma-separated metadata values without duplicates.
	 *
	 * @param string $current Current value
	 * @param string $new     New value
	 * @return string
	 */
	private function mergeTextValues($current, $new)
	{
		$values = array();
		foreach (array_merge(explode(',', $current), explode(',', $new)) as $value) {
			$value = trim($value);
			if ($value !== '' && !in_array($value, $values, true)) {
				$values[] = $value;
			}
		}

		return implode(', ', $values);
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
