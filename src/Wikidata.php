<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Reads the NFDI consortia and their official websites from Wikidata.
 */
final class Wikidata
{
	/** "accepted NFDI consortium", the class of all funded consortia. */
	public const NFDI_CONSORTIUM = 'Q98270496';

	private const ENDPOINT = 'https://query.wikidata.org/sparql';

	public function __construct(private readonly Http $http)
	{
	}

	/**
	 * All consortia with their official websites (P856) and GEPRIS project IDs (P4870).
	 *
	 * @return list<array{qid: string, label: string, websites: list<string>, gepris: string}>
	 */
	public function consortia(): array
	{
		return $this->select('?c wdt:P31 wd:' . self::NFDI_CONSORTIUM . ' .');
	}

	/**
	 * Specific items with their official websites (P856).
	 *
	 * @param list<string> $qids
	 * @return list<array{qid: string, label: string, websites: list<string>, gepris: string}>
	 */
	public function items(array $qids): array
	{
		return $this->select('VALUES ?c { ' . implode(' ', array_map(static fn (string $q): string => "wd:$q", $qids)) . ' }');
	}

	/**
	 * @return list<array{qid: string, label: string, websites: list<string>, gepris: string}>
	 */
	private function select(string $pattern): array
	{
		$query = <<<SPARQL
			SELECT ?c ?cLabel (GROUP_CONCAT(DISTINCT STR(?web); separator=" ") AS ?websites) (SAMPLE(?g) AS ?gepris) WHERE {
			  $pattern
			  OPTIONAL { ?c wdt:P856 ?web }
			  OPTIONAL { ?c wdt:P4870 ?g }
			  SERVICE wikibase:label { bd:serviceParam wikibase:language "en,de". }
			}
			GROUP BY ?c ?cLabel
			ORDER BY ?cLabel
			SPARQL;
		$url = self::ENDPOINT . '?' . http_build_query(['query' => $query]);
		$data = $this->http->getJson($url, ['Accept' => 'application/sparql-results+json']);

		$consortia = [];
		foreach ($data['results']['bindings'] as $row) {
			$websites = array_values(array_filter(explode(' ', $row['websites']['value'] ?? '')));
			$consortia[] = [
				'qid' => basename($row['c']['value']),
				'label' => $row['cLabel']['value'],
				'websites' => $websites,
				'gepris' => $row['gepris']['value'] ?? '',
			];
		}
		return $consortia;
	}
}
