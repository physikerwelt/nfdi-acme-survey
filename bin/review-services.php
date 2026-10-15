#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Review the candidates in services.json: move hosts that are not services
 * of the organisation to "excluded", each with the reason. Kept are hosts
 * offering data, software or a service of the organisation, run by
 * institutions in the NFDI.
 *
 * Usage: php bin/review-services.php --run=data/<date>
 *   --run  survey run; hosts already surveyed there are excluded as duplicates
 */

namespace NfdiAcmeSurvey;

/** Pattern on the hostname => reason for excluding it. */
const RULES = [
	'/(\.github\.io|\.readthedocs\.io|\.web\.app|\.pages\.rwth-aachen\.de|hypotheses\.org|pageflow\.io)$/'
		=> 'third-party hosting of docs, blogs or pages',
	'/^(pubs\.|journals\.|papers\.|iopscience\.|bdj\.pensoft|beck-online\.)/'
		=> 'publisher or journal',
	'/(khanacademy|harvard\.edu|mit\.edu|materialsproject\.org|fysik\.dtu\.dk|elixir-europe\.org|galaxyproject\.org|lifescience-ri\.eu|rightfield\.org\.uk|inkrement\.ai|embl\.de|openmicroscopy\.org)/'
		=> 'international or commercial service, not run in the NFDI',
	'/^(events\.|meetings\.|converia\.|lists\.|organisation\.|webstats\.|myjob\.|portal\.uni-|owncloud\.|websites\.fraunhofer|foerderportal\.|maps\.app\.goo\.gl|static\.scc\.kit\.edu)|emailsys|dsgvo/'
		=> 'events, mailing lists, administration or static assets of an institution',
	'/^hdl\.handle\.net$/'
		=> 'PID resolver',
	'/^(saxfdm\.de|www\.arthistoricum\.net|dgm\.inventum\.de|csmb\.hu-berlin\.de|isnr\.unileoben\.ac\.at|physics-of-life\.tu-dresden\.de|blog\.factgrid\.de)$/'
		=> 'other initiative or institute page, not a service of the organisation',
	'/^(git\.rwth-aachen\.de|gitlab\.git\.nrw|gitlab\.uni-freiburg\.de)$/'
		=> 'general code hosting of a university',
];

$options = getopt('', ['run:']);
$root = dirname(__DIR__);
if (!isset($options['run'])) {
	fwrite(STDERR, "usage: php bin/review-services.php --run=data/<date>\n");
	exit(2);
}

$surveyed = [];
$fh = fopen(rtrim($options['run'], '/') . '/hosts.csv', 'r');
$header = fgetcsv($fh, escape: '');
while ($row = fgetcsv($fh, escape: '')) {
	$row = array_combine($header, $row);
	// Runs before the source column existed contain only CT-log hosts.
	if (($row['source'] ?? 'ct-log') !== 'discovered') {
		$surveyed[$row['hostname']] = $row['organisation'];
	}
}
fclose($fh);

$data = json_decode((string)file_get_contents("$root/services.json"), true, 512, JSON_THROW_ON_ERROR);
$kept = $excluded = 0;
foreach ($data['services'] as &$entry) {
	$keep = [];
	$entry['excluded'] ??= [];
	foreach ($entry['hosts'] as $candidate) {
		$host = $candidate['host'];
		$reason = isset($surveyed[$host]) ? "already surveyed (CT log of {$surveyed[$host]})" : null;
		foreach (RULES as $pattern => $why) {
			$reason ??= preg_match($pattern, $host) ? $why : null;
		}
		if ($reason === null) {
			$keep[] = $candidate;
			$kept++;
		} else {
			$entry['excluded'][] = $candidate + ['reason' => $reason];
			$excluded++;
		}
	}
	$entry['hosts'] = $keep;
}
unset($entry);

$data['reviewed'] = gmdate('c');
$data['note'] = 'Candidates from Wikidata (P137/P361/P127/P749/P1269 with P856) and from links on the '
	. 'organisations\' websites, reviewed with bin/review-services.php. Kept: hosts offering data, software '
	. 'or a service of the organisation, run by institutions in the NFDI. Excluded hosts list the reason.';
file_put_contents("$root/services.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, "kept $kept, excluded $excluded\n");
foreach ($data['services'] as $entry) {
	fwrite(STDERR, sprintf("%-28s %2d: %s\n", mb_substr($entry['label'], 0, 28), count($entry['hosts']), implode(' ', array_column($entry['hosts'], 'host'))));
}
