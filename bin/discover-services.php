#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Find service hosts outside the organisations' own domains and write them
 * to services.json for review. The survey probes these hosts as well.
 *
 * Usage: php bin/discover-services.php --run=data/<date> [--below=10]
 *   --run    survey run whose summary.csv selects the organisations
 *   --below  only organisations with fewer hostnames than this (default 10)
 */

namespace NfdiAcmeSurvey;

require __DIR__ . '/../src/autoload.php';

$options = getopt('', ['run:', 'below:']);
$root = dirname(__DIR__);
if (!isset($options['run'])) {
	fwrite(STDERR, "usage: php bin/discover-services.php --run=data/<date> [--below=10]\n");
	exit(2);
}
$below = (int)($options['below'] ?? 10);

$counts = [];
$fh = fopen(rtrim($options['run'], '/') . '/summary.csv', 'r');
$header = fgetcsv($fh, escape: '');
while ($row = fgetcsv($fh, escape: '')) {
	$row = array_combine($header, $row);
	$counts[$row['qid']] = (int)$row['hostnames'];
}
fclose($fh);

$organisations = json_decode((string)file_get_contents("$root/organisations.json"), true, 512, JSON_THROW_ON_ERROR)['organisations'];
$selected = array_values(array_filter($organisations, static fn (array $o): bool => ($counts[$o['qid']] ?? 0) < $below));
fwrite(STDERR, count($selected) . " organisations with fewer than $below hostnames\n");

$discovery = new Discovery(new Http("$root/cache/discovery"));
$byWikidata = $discovery->fromWikidata(array_column($selected, 'qid'));

$services = [];
foreach ($selected as $o) {
	$own = array_map(static fn (string $w): string => preg_replace('/^www\./', '', (string)parse_url($w, PHP_URL_HOST)), $o['websites']);
	$own = [...$own, ...$o['extra_domains']];
	$candidates = [];
	foreach ([...($byWikidata[$o['qid']] ?? []), ...$discovery->fromWebsite($o['websites'], $own, Discovery::nameTokens($o['label'], $own))] as $c) {
		$candidates[$c['host']] ??= $c;
	}
	ksort($candidates);
	$services[$o['qid']] = ['label' => $o['label'], 'hosts' => array_values($candidates)];
	fwrite(STDERR, sprintf("%-28s %3d candidate hosts\n", $o['label'], count($candidates)));
}

file_put_contents("$root/services.json", json_encode([
	'discovered' => gmdate('c'),
	'note' => 'Candidates from Wikidata (P137/P361/P127/P749/P1269 with P856) and from links on the '
		. 'organisations\' websites. Review and delete entries that are not services of the organisation.',
	'services' => $services,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, "wrote $root/services.json\n");
