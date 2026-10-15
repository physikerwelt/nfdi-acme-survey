<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Connects to a host on port 443 and reports the certificate it serves.
 * Verification is off on purpose: we want to record what is served,
 * including expired or self-signed certificates.
 */
final class TlsProbe
{
	public function __construct(private readonly float $timeout = 8.0)
	{
	}

	/**
	 * @return array{status: string, issuer_org: string, issuer_cn: string, not_after: string}
	 */
	public function probe(string $host): array
	{
		$result = ['status' => '', 'issuer_org' => '', 'issuer_cn' => '', 'not_after' => ''];
		if (gethostbyname($host) === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
			$result['status'] = 'no-dns';
			return $result;
		}
		$context = stream_context_create(['ssl' => [
			'capture_peer_cert' => true,
			'verify_peer' => false,
			'verify_peer_name' => false,
			'peer_name' => $host,
			'SNI_enabled' => true,
		]]);
		$socket = @stream_socket_client("ssl://$host:443", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
		if ($socket === false) {
			$result['status'] = 'unreachable';
			return $result;
		}
		$params = stream_context_get_params($socket);
		fclose($socket);
		$certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
		if ($certificate === false) {
			$result['status'] = 'no-certificate';
			return $result;
		}
		$result['status'] = 'ok';
		$result['issuer_org'] = (string)($certificate['issuer']['O'] ?? '');
		$result['issuer_cn'] = (string)($certificate['issuer']['CN'] ?? '');
		$result['not_after'] = gmdate('Y-m-d', (int)$certificate['validTo_time_t']);
		return $result;
	}
}
