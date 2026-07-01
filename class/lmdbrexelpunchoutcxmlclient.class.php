<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbrexelpunchoutconfig.class.php';

/**
 * cXML PunchOut setup client.
 */
class LmdbRexelPunchoutCxmlClient
{
	const CXML_VERSION = '1.2.008';
	const CXML_DTD = 'http://xml.cxml.org/schemas/cXML/1.2.008/cXML.dtd';

	/**
	 * Build cXML setup request.
	 *
	 * @param string $returnUrl   Browser return URL
	 * @param string $buyerCookie Buyer cookie
	 * @return string
	 */
	public function buildSetupRequest($returnUrl, $buyerCookie)
	{
		$payloadId = uniqid('lmdbrexelpunchout-', true).'@dolibarr';
		$timestamp = gmdate('Y-m-d\TH:i:s\Z');

		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$doc->appendChild($doc->implementation->createDocumentType('cXML', '', self::CXML_DTD));

		$cxml = $doc->createElement('cXML');
		$cxml->setAttribute('version', self::CXML_VERSION);
		$cxml->setAttribute('payloadID', $payloadId);
		$cxml->setAttribute('timestamp', $timestamp);
		$cxml->setAttribute('xml:lang', LmdbRexelPunchoutConfig::getString('CXML_LANG', 'en-US'));
		$doc->appendChild($cxml);

		$header = $cxml->appendChild($doc->createElement('Header'));
		$this->appendCredential($doc, $header, 'From', LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_DOMAIN'), LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_IDENTITY'));
		$this->appendCredential($doc, $header, 'To', LmdbRexelPunchoutConfig::getString('CXML_SUPPLIER_DOMAIN'), LmdbRexelPunchoutConfig::getString('CXML_SUPPLIER_IDENTITY'));
		$sender = $header->appendChild($doc->createElement('Sender'));
		$senderDomain = LmdbRexelPunchoutConfig::getString('CXML_SENDER_DOMAIN');
		if ($senderDomain === '') {
			$senderDomain = LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_DOMAIN');
		}
		$senderIdentity = LmdbRexelPunchoutConfig::getString('CXML_SENDER_IDENTITY');
		if ($senderIdentity === '') {
			$senderIdentity = LmdbRexelPunchoutConfig::getString('CXML_CUSTOMER_IDENTITY');
		}
		$senderCredential = $this->appendCredentialContent($doc, $sender, $senderDomain, $senderIdentity);
		$secret = $senderCredential->appendChild($doc->createElement('SharedSecret'));
		$secret->appendChild($doc->createTextNode(LmdbRexelPunchoutConfig::getSecret('CXML_SHARED_SECRET')));
		$sender->appendChild($doc->createElement('UserAgent', 'Dolibarr Rexel Punchout'));

		$request = $cxml->appendChild($doc->createElement('Request'));
		$request->setAttribute('deploymentMode', LmdbRexelPunchoutConfig::getString('CXML_MODE', 'production') === 'test' ? 'test' : 'production');
		$punchout = $request->appendChild($doc->createElement('PunchOutSetupRequest'));
		$punchout->setAttribute('operation', 'create');
		$buyerCookieNode = $punchout->appendChild($doc->createElement('BuyerCookie'));
		$buyerCookieNode->appendChild($doc->createTextNode($buyerCookie));
		$browserPost = $punchout->appendChild($doc->createElement('BrowserFormPost'));
		$browserPostUrl = $browserPost->appendChild($doc->createElement('URL'));
		$browserPostUrl->appendChild($doc->createTextNode($returnUrl));
		$supplierSetup = $punchout->appendChild($doc->createElement('SupplierSetup'));
		$supplierSetupUrl = $supplierSetup->appendChild($doc->createElement('URL'));
		$supplierSetupUrl->appendChild($doc->createTextNode(LmdbRexelPunchoutConfig::getString('CXML_URL')));

		return (string) $doc->saveXML();
	}

	/**
	 * Send setup request and return StartPage URL.
	 *
	 * @param string $returnUrl   Browser return URL
	 * @param string $buyerCookie Buyer cookie
	 * @return string
	 */
	public function getStartPageUrl($returnUrl, $buyerCookie)
	{
		if (!function_exists('curl_init')) {
			throw new RuntimeException('cURL is required for cXML Punchout setup');
		}

		$xml = $this->buildSetupRequest($returnUrl, $buyerCookie);
		$ch = curl_init(LmdbRexelPunchoutConfig::getString('CXML_URL'));
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8', 'Accept: text/xml, application/xml'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		$redirectUrl = defined('CURLINFO_REDIRECT_URL') ? (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL) : '';
		curl_close($ch);

		if ($response === false || $response === '' || $httpCode < 200 || $httpCode >= 300) {
			$this->logSetupFailure($httpCode, $contentType, $effectiveUrl, $redirectUrl, $error, is_string($response) ? $response : '', $xml);
			throw new RuntimeException('cXML Punchout setup failed'.($error !== '' ? ': '.$error : ''));
		}

		return $this->parseStartPageUrl($response, $httpCode, $contentType, $effectiveUrl, $redirectUrl, $xml);
	}

	/**
	 * Parse cXML setup response.
	 *
	 * @param string $response     cXML response
	 * @param int    $httpCode     HTTP status code
	 * @param string $contentType  Response content type
	 * @param string $effectiveUrl Effective URL
	 * @param string $redirectUrl  Redirect URL
	 * @param string $request      Raw request
	 * @return string
	 */
	public function parseStartPageUrl($response, $httpCode = 200, $contentType = '', $effectiveUrl = '', $redirectUrl = '', $request = '')
	{
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($response, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			$this->logSetupFailure($httpCode, $contentType, $effectiveUrl, $redirectUrl, '', $response, $request);
			throw new RuntimeException('Invalid cXML setup response');
		}

		$xpath = new DOMXPath($doc);
		$status = $xpath->query('//*[local-name()="Status"]')->item(0);
		if ($status instanceof DOMElement && $status->hasAttribute('code')) {
			$statusCode = (int) $status->getAttribute('code');
			if ($statusCode >= 400) {
				$statusText = $status->hasAttribute('text') ? trim($status->getAttribute('text')) : '';
				if ($statusText === '') {
					$statusText = trim($status->textContent);
				}
				$this->logSetupFailure($httpCode, $contentType, $effectiveUrl, $redirectUrl, '', $response, $request);
				throw new RuntimeException('cXML setup rejected: '.$statusCode.($statusText !== '' ? ' '.$statusText : ''));
			}
		}

		$url = $xpath->query('//*[local-name()="StartPage"]/*[local-name()="URL"]')->item(0);
		if (!$url || trim($url->textContent) === '') {
			$this->logSetupFailure($httpCode, $contentType, $effectiveUrl, $redirectUrl, '', $response, $request);
			throw new RuntimeException('cXML setup response has no StartPage URL');
		}

		return trim($url->textContent);
	}

	/**
	 * Append cXML credential.
	 *
	 * @param DOMDocument $doc      Document
	 * @param DOMNode     $parent   Parent
	 * @param string      $nodeName Node name
	 * @param string      $domain   Domain
	 * @param string      $identity Identity
	 * @return void
	 */
	private function appendCredential($doc, $parent, $nodeName, $domain, $identity)
	{
		$node = $parent->appendChild($doc->createElement($nodeName));
		$this->appendCredentialContent($doc, $node, $domain, $identity);
	}

	/**
	 * Append cXML credential content.
	 *
	 * @param DOMDocument $doc      Document
	 * @param DOMNode     $parent   Parent
	 * @param string      $domain   Domain
	 * @param string      $identity Identity
	 * @return DOMElement
	 */
	private function appendCredentialContent($doc, $parent, $domain, $identity)
	{
		$credential = $doc->createElement('Credential');
		$parent->appendChild($credential);
		$credential->setAttribute('domain', $domain);
		$identityNode = $credential->appendChild($doc->createElement('Identity'));
		$identityNode->appendChild($doc->createTextNode($identity));

		return $credential;
	}

	/**
	 * Log a setup failure without exposing credentials.
	 *
	 * @param int    $httpCode     HTTP status code
	 * @param string $contentType  Response content type
	 * @param string $effectiveUrl Effective URL
	 * @param string $redirectUrl  Redirect URL
	 * @param string $curlError    cURL error
	 * @param string $response     Raw response
	 * @param string $request      Raw request
	 * @return void
	 */
	private function logSetupFailure($httpCode, $contentType, $effectiveUrl, $redirectUrl, $curlError, $response, $request = '')
	{
		$status = $this->extractStatusSummary($response);
		$redactedResponse = $this->redactCxmlSecrets($response);
		$excerpt = function_exists('dol_substr') ? dol_substr($redactedResponse, 0, 1000) : substr($redactedResponse, 0, 1000);
		$excerpt = trim((string) preg_replace('/\s+/', ' ', $excerpt));
		$redactedRequest = $this->redactCxmlSecrets($request);
		$requestExcerpt = function_exists('dol_substr') ? dol_substr($redactedRequest, 0, 1500) : substr($redactedRequest, 0, 1500);
		$requestExcerpt = trim((string) preg_replace('/\s+/', ' ', $requestExcerpt));
		$message = 'lmdbrexelpunchout cXML setup failed';
		$message .= ' http_status='.(int) $httpCode;
		$message .= ' content_type='.$contentType;
		$message .= ' effective_url='.$effectiveUrl;
		if ($redirectUrl !== '') {
			$message .= ' redirect_url='.$redirectUrl;
		}
		if ($status !== '') {
			$message .= ' cxml_status='.$status;
		}
		if ($curlError !== '') {
			$message .= ' curl_error='.$curlError;
		}
		if ($excerpt !== '') {
			$message .= ' response_excerpt='.$excerpt;
		}
		if ($requestExcerpt !== '') {
			$message .= ' request_excerpt='.$requestExcerpt;
		}

		if (function_exists('dol_syslog')) {
			dol_syslog($message, defined('LOG_ERR') ? LOG_ERR : 3);
		}
	}

	/**
	 * Extract cXML status details from a response.
	 *
	 * @param string $response Raw response
	 * @return string
	 */
	private function extractStatusSummary($response)
	{
		if (trim($response) === '') {
			return '';
		}

		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $doc->loadXML($response, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return '';
		}

		$xpath = new DOMXPath($doc);
		$status = $xpath->query('//*[local-name()="Status"]')->item(0);
		if (!$status instanceof DOMElement) {
			return '';
		}

		$parts = array();
		if ($status->hasAttribute('code')) {
			$parts[] = 'code='.$status->getAttribute('code');
		}
		if ($status->hasAttribute('text')) {
			$parts[] = 'text='.$status->getAttribute('text');
		}
		$content = trim($status->textContent);
		if ($content !== '') {
			$parts[] = 'message='.$content;
		}

		return implode(' ', $parts);
	}

	/**
	 * Redact cXML secrets from logs.
	 *
	 * @param string $content Raw content
	 * @return string
	 */
	private function redactCxmlSecrets($content)
	{
		$content = (string) preg_replace(
			array(
				'~<SharedSecret\b[^>]*>.*?</SharedSecret>~is',
				'~<BuyerCookie\b[^>]*>.*?</BuyerCookie>~is',
				'~([?&](?:amp;)?token=)[^&<\s]+~i',
			),
			array(
				'<SharedSecret>[REDACTED]</SharedSecret>',
				'<BuyerCookie>[REDACTED]</BuyerCookie>',
				'${1}[REDACTED]',
			),
			$content
		);

		return $content;
	}
}
