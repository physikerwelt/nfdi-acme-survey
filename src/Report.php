<?php

declare(strict_types=1);

namespace NfdiAcmeSurvey;

use RuntimeException;

/**
 * Writes the survey result as CSV files plus a run.json with provenance.
 */
final class Report
{
	public function __construct(private readonly string $dir)
	{
		if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
			throw new RuntimeException("Cannot create output directory $dir");
		}
	}

	/**
	 * @param list<array<string, mixed>> $consortia
	 * @param list<array<string, string>> $hosts
	 * @param array<string, mixed> $run
	 * @return list<string> written files
	 */
	public function write(array $consortia, array $hosts, array $run): array
	{
		$hostCount = array_count_values(array_column($hosts, 'qid'));
		$consortiaRows = [];
		foreach ($consortia as $c) {
			$consortiaRows[] = [
				'qid' => $c['qid'],
				'wikidata' => 'https://www.wikidata.org/wiki/' . $c['qid'],
				'label' => $c['label'],
				'role' => $c['role'],
				'websites' => implode(' ', $c['websites']),
				'domains' => implode(' ', array_map(
					static fn (string $d, string $s): string => $s === 'host' ? "$d (host only)" : $d,
					array_keys($c['domains']),
					$c['domains'],
				)),
				'hostnames' => $hostCount[$c['qid']] ?? 0,
			];
		}

		$summary = [];
		foreach ($consortia as $c) {
			$own = array_filter($hosts, static fn (array $h): bool => $h['qid'] === $c['qid']);
			$ok = array_filter($own, static fn (array $h): bool => $h['status'] === 'ok');
			$cas = array_count_values(array_column($ok, 'ca'));
			$le = $cas["Let's Encrypt"] ?? 0;
			$summary[] = [
				'qid' => $c['qid'],
				'label' => $c['label'],
				'role' => $c['role'],
				'hostnames' => count($own),
				'reachable' => count($ok),
				'lets_encrypt' => $le,
				'lets_encrypt_share' => count($ok) ? round($le / count($ok), 3) : '',
				'only_lets_encrypt' => count($ok) && $le === count($ok) ? 'yes' : 'no',
				'other_cas' => implode(' ', array_map(
					static fn (string $ca, int $n): string => "$ca:$n",
					array_keys(array_diff_key($cas, ["Let's Encrypt" => 0])),
					array_diff_key($cas, ["Let's Encrypt" => 0]),
				)),
			];
		}

		$ok = array_filter($hosts, static fn (array $h): bool => $h['status'] === 'ok');
		$byCa = array_count_values(array_column($ok, 'ca'));
		arsort($byCa);
		$issuers = [];
		foreach ($byCa as $ca => $n) {
			$issuers[] = ['ca' => $ca, 'hosts' => $n, 'share' => round($n / max(1, count($ok)), 3)];
		}

		$run['totals'] = [
			'organisations' => count($consortia),
			'consortia' => count(array_filter($consortia, static fn (array $c): bool => $c['role'] === 'consortium')),
			'hostnames' => count($hosts),
			'reachable' => count($ok),
			'by_ca' => $byCa,
			'consortia_only_lets_encrypt' => count(array_filter(
				$summary,
				static fn (array $s): bool => $s['role'] === 'consortium' && $s['only_lets_encrypt'] === 'yes',
			)),
		];

		return [
			$this->csv('organisations.csv', $consortiaRows),
			$this->csv('hosts.csv', $hosts),
			$this->csv('summary.csv', $summary),
			$this->csv('issuers.csv', $issuers),
			$this->json('run.json', $run),
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function csv(string $name, array $rows): string
	{
		$path = "$this->dir/$name";
		$fh = fopen($path, 'w');
		if ($rows) {
			fputcsv($fh, array_keys($rows[0]), escape: '');
			foreach ($rows as $row) {
				fputcsv($fh, array_values($row), escape: '');
			}
		}
		fclose($fh);
		return $path;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function json(string $name, array $data): string
	{
		$path = "$this->dir/$name";
		file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		return $path;
	}
}
