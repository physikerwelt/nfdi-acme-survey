<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Finds service hosts of an organisation outside its own domains: items on
 * Wikidata that belong to it, and hosts linked from its website's service
 * pages. Many consortia run services under institutional domains.
 */
final class Discovery
{
	/** Paths on the consortium website that likely list services. */
	private const SERVICE_PAGES = '/servic|dienst|tool|angebot|portal|resourc|ressourc|infrastruktur|infrastructure|software|repositor|data|daten|catalog|katalog/i';

	/** Linked hosts that are never a consortium's own service. */
	private const IGNORE = '/(^|\.)(google|gstatic|googleapis|youtube|youtu\.be|vimeo|twitter|x\.com|facebook|instagram|linkedin|xing|mastodon|bsky|nfdi\.social|fedihum|github\.com|gitlab\.com|zenodo\.org|doi\.org|orcid\.org|wikipedia\.org|wikidata\.org|creativecommons\.org|w3\.org|schema\.org|dfg\.de|bmbf\.de|bmftr\.bund\.de|gwk-bonn\.de|nfdi\.de|base4nfdi\.de|matomo|cookiebot|usercentrics|zoom\.us|webex|meet\.|eventbrite|indico|doodle|dfn\.de|apple\.com|spotify|acast|soundcloud|europa\.eu|eosc-portal|rd-alliance|go-fair|researchgate|scholar|springer|elsevier|wiley|nature\.com|arxiv\.org|mailchi|newsletter|wordpress|jimdo|typo3|bootstrap|jquery|fonts|cdn|cloudflare|unpkg|jsdelivr)/i';

	private const MAX_PAGES = 15;

	public function __construct(private readonly Http $http)
	{
	}

	/**
	 * Hosts of Wikidata items operated by, owned by or part of the given items.
	 *
	 * @param list<string> $qids
	 * @return array<string, list<array{host: string, source: string, evidence: string}>> by QID
	 */
	public function fromWikidata(array $qids): array
	{
		$values = implode(' ', array_map(static fn (string $q): string => "wd:$q", $qids));
		$query = <<<SPARQL
			SELECT DISTINCT ?org ?s ?web WHERE {
			  VALUES ?org { $values }
			  ?s wdt:P137|wdt:P361|wdt:P127|wdt:P749|wdt:P1269 ?org .
			  ?s wdt:P856 ?web .
			}
			SPARQL;
		$url = 'https://query.wikidata.org/sparql?' . http_build_query(['query' => $query]);
		$data = $this->http->getJson($url, ['Accept' => 'application/sparql-results+json']);
		$found = [];
		foreach ($data['results']['bindings'] as $row) {
			$host = strtolower((string)parse_url($row['web']['value'], PHP_URL_HOST));
			if ($host !== '') {
				$found[basename($row['org']['value'])][] = [
					'host' => $host,
					'source' => 'wikidata',
					'evidence' => basename($row['s']['value']),
				];
			}
		}
		return $found;
	}

	/**
	 * Hosts linked from the website and its service-like pages.
	 *
	 * Hosts under the organisation's own domains are included: with wildcard
	 * certificates, the CT logs do not name them.
	 *
	 * @param list<string> $websites
	 * @param list<string> $ownDomains hosts under these are always candidates
	 * @param list<string> $names name tokens of the organisation (see nameTokens())
	 * @return list<array{host: string, source: string, evidence: string}>
	 */
	public function fromWebsite(array $websites, array $ownDomains, array $names = []): array
	{
		$queue = $websites;
		$seen = [];
		$found = [];
		while ($queue && count($seen) < self::MAX_PAGES) {
			$page = array_shift($queue);
			if (isset($seen[$page])) {
				continue;
			}
			$seen[$page] = true;
			[$status, $html] = $this->http->request('GET', $page, ['Accept' => 'text/html'], null, 30);
			if ($status !== 200 || $html === '') {
				continue;
			}
			$pageHost = (string)parse_url($page, PHP_URL_HOST);
			preg_match_all('/href\s*=\s*["\']([^"\'#]+)/i', $html, $m);
			foreach (array_unique($m[1]) as $href) {
				$url = $this->absolute($href, $page);
				$host = strtolower((string)parse_url($url, PHP_URL_HOST));
				if ($host === '' || !str_starts_with($url, 'http')) {
					continue;
				}
				if ($host === $pageHost) {
					if (preg_match(self::SERVICE_PAGES, (string)parse_url($url, PHP_URL_PATH))) {
						$queue[] = strtok($url, '?');
					}
					continue;
				}
				if (!$this->covered($host, $ownDomains) && (preg_match(self::IGNORE, $host) || !$this->serviceLike($host, $names))) {
					continue;
				}
				$found[$host] ??= ['host' => $host, 'source' => 'website', 'evidence' => $page];
			}
			usleep(300000);
		}
		return array_values($found);
	}

	/**
	 * Name tokens of an organisation for recognising its hosts: the label
	 * ("DataPLANT" -> "dataplant", "Text+" -> "text") and the first label of
	 * its own domains ("text-plus.org" -> "text-plus"). Tokens that would
	 * match unrelated hosts are dropped.
	 *
	 * @param list<string> $ownDomains
	 * @return list<string>
	 */
	public static function nameTokens(string $label, array $ownDomains): array
	{
		$generic = ['text', 'data', 'web', 'www', 'base', 'nfdi'];
		$tokens = [preg_replace('/[^a-z0-9]/', '', strtolower($label))];
		foreach ($ownDomains as $domain) {
			$tokens[] = explode('.', $domain)[0];
		}
		return array_values(array_unique(array_filter(
			$tokens,
			static fn (string $t): bool => strlen($t) >= 4 && !in_array($t, $generic, true),
		)));
	}

	/**
	 * Partner institutions are linked by their home page (www.uni-x.de),
	 * services by a dedicated subdomain (terminology.tib.eu). Hosts named
	 * after NFDI or after the organisation are kept either way.
	 *
	 * @param list<string> $names
	 */
	private function serviceLike(string $host, array $names): bool
	{
		foreach (['nfdi', ...$names] as $name) {
			if (str_contains($host, $name)) {
				return true;
			}
		}
		$labels = explode('.', $host);
		return count($labels) >= 3 && $labels[0] !== 'www';
	}

	/**
	 * @param list<string> $domains
	 */
	private function covered(string $host, array $domains): bool
	{
		foreach ($domains as $domain) {
			if ($host === $domain || str_ends_with($host, ".$domain")) {
				return true;
			}
		}
		return false;
	}

	private function absolute(string $href, string $base): string
	{
		$href = html_entity_decode(trim($href));
		if (preg_match('#^https?://#i', $href)) {
			return $href;
		}
		if (str_starts_with($href, '//')) {
			return 'https:' . $href;
		}
		$scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
		$host = parse_url($base, PHP_URL_HOST);
		if (str_starts_with($href, '/')) {
			return "$scheme://$host$href";
		}
		return preg_match('#^[a-z]+:#i', $href) ? $href : "$scheme://$host/" . ltrim($href, './');
	}
}
