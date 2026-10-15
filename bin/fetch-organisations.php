#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build organisations.json: the NFDI consortia from Wikidata plus the
 * association/directorate and Base4NFDI. The consortium list is final, so this
 * runs once and the result is committed; the survey only reads the file.
 *
 * Usage: php bin/fetch-organisations.php [--out=organisations.json]
 */

namespace NfdiAcmeSurvey;

require __DIR__ . '/../src/autoload.php';

/**
 * Organisations surveyed besides the consortia, with domains that are theirs
 * but not their official website on Wikidata.
 */
const CENTRAL = [
	// NFDI e.V. and its directorate; nfdi.social is the NFDI Mastodon instance.
	'Q61658497' => ['role' => 'directorate', 'extra_domains' => ['nfdi.social']],
	// Basic services for NFDI; the services themselves have no Wikidata items.
	'Q113544452' => ['role' => 'basic-services', 'extra_domains' => []],
];

/**
 * Own domains of consortia that are not their official website (P856) on
 * Wikidata, taken from the consortium pages on nfdi.de (October 2026).
 */
const EXTRA_DOMAINS = [
	'Q98380336' => ['nfdi4plants.org'], // DataPLANT: services run under .org, P856 is nfdi4plants.de
	'Q108542373' => ['fairmat-nfdi.eu'], // FAIRmat: P856 is a page on fair-di.eu
	'Q98380337' => ['ghga.de'], // GHGA: P856 is ghga.dkfz.de
	'Q108327788' => ['mardi4nfdi.org'], // MaRDI
	'Q98380340' => ['nfdi4society.de'], // KonsortSWD, listed by nfdi.de as NFDI4Society
];

$options = getopt('', ['out:']);
$root = dirname(__DIR__);
$out = $options['out'] ?? "$root/organisations.json";

$http = new Http("$root/cache/organisations");
$wikidata = new Wikidata($http);
$gepris = new Gepris($http);
$organisations = [];
foreach ($wikidata->consortia() as $item) {
	// Base4NFDI is typed as a consortium on Wikidata; it is listed below.
	if (!isset(CENTRAL[$item['qid']])) {
		$organisations[] = $item + ['role' => 'consortium', 'extra_domains' => EXTRA_DOMAINS[$item['qid']] ?? []];
	}
}
foreach ($wikidata->items(array_keys(CENTRAL)) as $item) {
	$organisations[] = $item + CENTRAL[$item['qid']];
}

// Cross-check with GEPRIS, the DFG project database: title, funding programme
// and the website the project registered there.
foreach ($organisations as &$o) {
	$o['gepris'] = $o['gepris'] !== '' ? $gepris->project($o['gepris']) : null;
	$hosts = array_map(static fn (string $w): string => preg_replace('/^www\./', '', (string)parse_url($w, PHP_URL_HOST)), $o['websites']);
	$geprisHost = preg_replace('/^www\./', '', (string)parse_url($o['gepris']['website'] ?? '', PHP_URL_HOST));
	if ($o['gepris'] === null && $o['role'] !== 'directorate') {
		fwrite(STDERR, "warning: {$o['qid']} {$o['label']} has no GEPRIS project (P4870)\n");
	} elseif ($geprisHost !== '' && !in_array($geprisHost, [...$hosts, ...$o['extra_domains']], true)) {
		fwrite(STDERR, "warning: {$o['qid']} {$o['label']}: GEPRIS website $geprisHost is not P856 on Wikidata; added\n");
		$o['extra_domains'][] = $geprisHost;
	}
}
unset($o);

$consortia = count(array_filter($organisations, static fn (array $o): bool => $o['role'] === 'consortium'));
fwrite(STDERR, "$consortia consortia, " . (count($organisations) - $consortia) . " central organisations\n");
foreach ($organisations as $o) {
	if (!$o['websites']) {
		fwrite(STDERR, "warning: {$o['qid']} {$o['label']} has no official website (P856) on Wikidata\n");
	}
}

file_put_contents($out, json_encode([
	'fetched' => gmdate('c'),
	'source' => 'Wikidata: instances of ' . Wikidata::NFDI_CONSORTIUM . ' (accepted NFDI consortium) plus '
		. implode(', ', array_keys(CENTRAL)) . '; official websites from P856; cross-checked with GEPRIS (P4870)',
	'organisations' => $organisations,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, "wrote $out\n");
