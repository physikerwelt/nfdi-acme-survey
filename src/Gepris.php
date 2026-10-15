<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

/**
 * Project data from GEPRIS, the DFG's project database, via the JSON backend
 * of its web application.
 */
final class Gepris
{
	private const ENDPOINT = 'https://gepris.dfg.de/backend/project/get';

	public function __construct(private readonly Http $http)
	{
	}

	/**
	 * @return array{id: string, title: string, programme: string, term: string, website: string}|null
	 */
	public function project(string $id): ?array
	{
		$data = $this->http->getJson(self::ENDPOINT . '?' . http_build_query(['id' => $id, 'ranking' => 0]));
		$p = $data['data'] ?? null;
		if (!$p) {
			return null;
		}
		preg_match('/href="([^"]+)"/', (string)($p['PRJ_WEBSEITE'] ?? ''), $m);
		return [
			'id' => $id,
			'title' => (string)($p['PRJ_TITEL_EN'] ?? $p['PRJ_TITEL'] ?? ''),
			'programme' => (string)($p['PRJ_VERFAHREN_KEY'] ?? ''),
			'term' => (string)($p['PRJ_FOERDERZEITRAUM_EN'] ?? ''),
			'website' => $m[1] ?? '',
		];
	}
}
