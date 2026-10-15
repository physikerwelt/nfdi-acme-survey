<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Which certificate authorities do the NFDI consortia rely on?
 *
 * 1. Organisations (consortia, directorate, Base4NFDI) with their websites,
 *    from organisations.json (built once from Wikidata).
 * 2. Hostnames per organisation domain from certificate transparency logs.
 * 3. The certificate each host serves right now.
 */
final class Survey
{
	/** Issuer organisations grouped under a short CA name. */
	private const CA_NAMES = [
		"Let's Encrypt" => "Let's Encrypt",
		'Hellenic Academic and Research Institutions CA' => 'HARICA',
		'DigiCert Inc' => 'DigiCert',
		'ZeroSSL GmbH' => 'ZeroSSL',
		'Sectigo Limited' => 'Sectigo',
		'GEANT Vereniging' => 'GEANT TCS',
		'Google Trust Services' => 'Google Trust Services',
	];

	/** @var callable(string): void */
	private $log;

	public function __construct(
		private readonly CertificateTransparency $ct,
		private readonly TlsProbe $probe,
		?callable $log = null,
	) {
		$this->log = $log ?? static function (string $line): void {
		};
	}

	/**
	 * @param list<array{qid: string, label: string, role: string, websites: list<string>, extra_domains: list<string>}> $consortia
	 * @param array<string, list<string>> $services additional service hosts by QID (services.json)
	 * @return array{consortia: list<array<string, mixed>>, hosts: list<array<string, string>>}
	 */
	public function run(array $consortia, array $services = []): array
	{
		($this->log)(count($consortia) . ' organisations');

		// Domain to enumerate per consortium. A website on its own host
		// (www.nfdi4chem.de) stands for the whole domain; a website that is a
		// path on a shared host (an institute page) only for that one host.
		$domains = [];
		$websiteHosts = [];
		foreach ($consortia as &$consortium) {
			$consortium['domains'] = [];
			foreach ($consortium['websites'] as $website) {
				$host = strtolower((string)parse_url($website, PHP_URL_HOST));
				if ($host === '') {
					continue;
				}
				$path = trim((string)parse_url($website, PHP_URL_PATH), '/');
				$domain = preg_replace('/^www\./', '', $host);
				// The website itself is always probed, even if the CT logs
				// have nothing for the domain.
				$websiteHosts[$domain][] = $host;
				$scope = $path === '' ? 'domain' : 'host';
				$consortium['domains'][$domain] = $scope;
				$domains[$domain] = ['qid' => $consortium['qid'], 'scope' => $scope];
			}
			foreach ($consortium['extra_domains'] as $domain) {
				$consortium['domains'][$domain] = 'domain';
				$domains[$domain] = ['qid' => $consortium['qid'], 'scope' => 'domain'];
			}
		}
		unset($consortium);

		// hostname => [qid, domain, source]
		$owner = [];
		foreach ($domains as $domain => $info) {
			$names = $info['scope'] === 'domain' ? $this->ct->hostnames($domain) : [$domain];
			$names = array_unique([...$names, ...($websiteHosts[$domain] ?? [])]);
			($this->log)(sprintf('%-28s %4d hostnames', $domain, count($names)));
			foreach ($names as $name) {
				$d = $this->longestSuffixDomain($name, array_keys($domains)) ?? $domain;
				$owner[$name] = [$domains[$d]['qid'], $d, 'ct-log'];
			}
		}
		// Services found elsewhere (bin/discover-services.php). A host under an
		// organisation's own domain belongs to that organisation, even if it
		// was found on another one's website.
		foreach ($services as $qid => $hosts) {
			foreach ($hosts as $name) {
				$d = $this->longestSuffixDomain($name, array_keys($domains));
				$owner[$name] ??= $d !== null ? [$domains[$d]['qid'], $d, 'discovered'] : [$qid, '', 'discovered'];
			}
		}
		ksort($owner);

		$byQid = array_column($consortia, null, 'qid');
		$hosts = [];
		$total = count($owner);
		$i = 0;
		foreach ($owner as $name => [$qid, $domain, $source]) {
			$i++;
			$probe = $this->probe->probe($name);
			$hosts[] = [
				'hostname' => $name,
				'qid' => $qid,
				'organisation' => $byQid[$qid]['label'],
				'role' => $byQid[$qid]['role'],
				'domain' => $domain,
				'source' => $source,
				'status' => $probe['status'],
				'ca' => $probe['status'] === 'ok' ? $this->caName($probe['issuer_org']) : '',
				'issuer_org' => $probe['issuer_org'],
				'issuer_cn' => $probe['issuer_cn'],
				'not_after' => $probe['not_after'],
			];
			if ($i % 25 === 0 || $i === $total) {
				($this->log)("probed $i/$total hosts");
			}
		}
		return ['consortia' => $consortia, 'hosts' => $hosts];
	}

	public function caName(string $issuerOrg): string
	{
		// Certificates without an issuer organisation are typically self-signed
		// defaults of a reverse proxy (e.g. "TRAEFIK DEFAULT CERT").
		return self::CA_NAMES[$issuerOrg] ?? ($issuerOrg !== '' ? $issuerOrg : 'no issuer organisation');
	}

	/**
	 * @param list<string> $domains
	 */
	private function longestSuffixDomain(string $host, array $domains): ?string
	{
		$best = null;
		foreach ($domains as $domain) {
			if (($host === $domain || str_ends_with($host, ".$domain"))
				&& ($best === null || strlen($domain) > strlen($best))) {
				$best = $domain;
			}
		}
		return $best;
	}
}
