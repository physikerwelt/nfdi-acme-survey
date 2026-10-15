<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Lists hostnames under a domain that have a currently valid certificate,
 * using the crt.sh certificate transparency search.
 */
final class CertificateTransparency
{
	private const ENDPOINT = 'https://crt.sh/';

	public function __construct(private readonly Http $http)
	{
	}

	/**
	 * Hostnames covered by unexpired certificates for $domain and its subdomains.
	 * Wildcard entries and e-mail addresses are dropped, since they name no
	 * single host that could be probed.
	 *
	 * @return list<string>
	 */
	public function hostnames(string $domain): array
	{
		$url = self::ENDPOINT . '?' . http_build_query([
			'q' => '%.' . $domain,
			'exclude' => 'expired',
			'output' => 'json',
		]);
		$names = [$domain => true];
		foreach ($this->http->getJson($url, [], true, 180) ?? [] as $certificate) {
			foreach (preg_split('/\s+/', strtolower($certificate['name_value'])) as $name) {
				if ($name !== '' && !str_contains($name, '*') && !str_contains($name, '@')) {
					$names[$name] = true;
				}
			}
		}
		$hosts = array_keys($names);
		sort($hosts);
		return $hosts;
	}
}
