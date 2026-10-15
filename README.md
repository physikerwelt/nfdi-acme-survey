# nfdi-acme-survey

Which certificate authorities do the services of the NFDI consortia depend on?

Data: [10.5281/zenodo.23391370](https://doi.org/10.5281/zenodo.23391370) (run of 2026-10-15)

> **Status: alpha 01.** This is the first version. Expect problems, and do
> not rely on the results without reviewing the host lists (`hosts.csv`,
> `services.json`) by hand.

The survey runs in three steps:

1. **Organisations** from `organisations.json`: the 26 consortia (instances of
   [Q98270496](https://www.wikidata.org/wiki/Q98270496), accepted NFDI
   consortium), Base4NFDI ([Q113544452](https://www.wikidata.org/wiki/Q113544452))
   and NFDI e.V. with its directorate ([Q61658497](https://www.wikidata.org/wiki/Q61658497)),
   with their official websites (P856). The list is final, so it was built
   once with `bin/fetch-organisations.php` and committed. That script
   cross-checks every organisation with GEPRIS via its project ID (P4870):
   all 27 projects (26 consortia plus Base4NFDI) are in the DFG's NFDI
   programme. A few own domains missing on Wikidata are listed in the script.
2. **Hostnames** per organisation domain from certificate transparency logs
   ([crt.sh](https://crt.sh/), unexpired certificates for `%.<domain>`).
   A website on its own host (`www.nfdi4chem.de`) stands for the whole domain
   (`nfdi4chem.de`). A website that is a path on a shared host only counts
   that one host, so whole university domains are not swept in. The website
   hosts themselves are always included.
3. **Issuer** of the certificate each host serves right now, from a TLS
   handshake on port 443 with SNI. The certificate is recorded, not verified.

## How the data was generated

Each step is a script; its output is committed, so every decision is visible
in the history.

1. **Organisation list** (`organisations.json`, once, 2026-10-15):
   `php bin/fetch-organisations.php`
   - Wikidata SPARQL for all instances of Q98270496 with their official
     website (P856) and GEPRIS project ID (P4870).
   - Base4NFDI is typed as a consortium on Wikidata; it is listed with the
     role `basic-services` instead. NFDI e.V./directorate is added with the
     role `directorate` and its Mastodon instance `nfdi.social`.
   - Every GEPRIS ID is looked up in the DFG project database (JSON backend
     of gepris.dfg.de). All 26 consortia and Base4NFDI are projects in the
     NFDI programme; "NFDI4Society" is KonsortSWD (GEPRIS title "KonsortSWD -
     nfdi4society"). A website registered in GEPRIS but missing on Wikidata
     would be added automatically and reported.
   - Own domains missing on Wikidata are listed in `EXTRA_DOMAINS` in the
     script, each with a comment (from the consortium pages on nfdi.de).
2. **Service discovery** (`services.json`): `php bin/discover-services.php
   --run=data/<date>` for organisations with fewer than 10 hostnames in that
   run, because they run most services under institutional domains.
   - Wikidata items with an official website that are operated by, owned
     by or part of the organisation (P137, P127, P361, P749, P1269).
   - Links on the organisation's website and its service-like pages (paths
     with service, tool, portal, resource, data and similar; at most 15
     pages per organisation). A linked host is a candidate if it contains
     "nfdi" or a name token of the organisation, or if it is a subdomain
     other than `www` (a dedicated service host rather than a partner's
     home page). Social media, publishers, funders and similar are ignored.
   - `php bin/review-services.php --run=data/<date>` then moves candidates
     that are not a service of the organisation to an `excluded` list in
     `services.json`, each with its reason: hosts already surveyed, docs or
     page hosting (GitHub Pages, ReadTheDocs), publishers, international or
     commercial services, institutional events, lists and administration
     pages, PID resolvers, and other initiatives. Kept are hosts offering
     data, software or a service of the organisation, run by institutions in
     the NFDI. The rules are in the script; edit them to change the review.
3. **Survey run** (`data/<date>/`): `php bin/survey.php`
   - For each own domain, all hostnames with an unexpired certificate on
     crt.sh, plus the website hosts themselves (`source` = `ct-log`).
   - The hosts from `services.json` (`source` = `discovered`). A host under
     an organisation's own domain stays assigned to that organisation.
   - For each host, the certificate served on port 443 (TLS handshake with
     SNI, not verified) and its issuer.
4. **Publication**: `php bin/zenodo-upload.php --run=data/<date>` creates a
   Zenodo draft with the run's files. The code is cited by its Software
   Heritage ID, `swh:1:rev:` plus the `code_commit` that `run.json` records.
   The draft is reviewed and published by hand in the Zenodo web interface.

## Usage

Requires PHP 8.1+ with the curl and openssl extensions; no other dependencies.

```sh
php bin/survey.php                 # writes data/<today>/
php bin/survey.php --no-cache      # refetch crt.sh
php bin/fetch-organisations.php    # rebuild organisations.json (rarely needed)
php bin/discover-services.php --run=data/<date>   # candidates for services.json
php bin/review-services.php --run=data/<date>     # review them
```

Raw crt.sh responses are cached in `cache/<today>/`, so a run can be repeated
from the same inputs. Set `SURVEY_CONTACT` to a URL or e-mail address to
include it in the User-Agent, as Wikidata asks.

Output in `data/<date>/`:

| File | Content |
|---|---|
| `organisations.csv` | QID, label, role, websites, surveyed domains, hostname count |
| `hosts.csv` | hostname, organisation QID, label and role, source, status, CA, issuer, expiry |
| `summary.csv` | per organisation: reachable hosts, Let's Encrypt share, other CAs |
| `issuers.csv` | hosts per certificate authority |
| `run.json` | time, versions, sources and totals |

## Publishing on Zenodo

Create a personal access token on Zenodo (Applications) with only the
`deposit:write` scope; Zenodo shows it once. Then:

```sh
read -s ZENODO_TOKEN && export ZENODO_TOKEN
php bin/zenodo-upload.php --run=data/<date> --production
```

`read -s` keeps the token out of the shell history; delete it on Zenodo after
the upload. On macOS the token can instead be stored once in the Keychain,
where the script finds it when `ZENODO_TOKEN` is unset:
`security add-generic-password -s zenodo-deposit -a "$USER" -w` (asks for it). Without `--production` the script uses sandbox.zenodo.org, and
`--new-version-of=<record>` adds a version to an existing record.

The script creates a **draft** with the run's files and discards it again if
anything fails. The code is not uploaded: the record links the Software
Heritage ID of the run's `code_commit`, which must be pushed, and archived
via [Save Code Now](https://archive.softwareheritage.org/save/) on Software
Heritage. Metadata, including the
DFG funding of MaRDI, comes from `zenodo/metadata.json` (InvenioRDM format),
the description from `zenodo/description.html`. The script never publishes:
review the draft and publish it by hand in the Zenodo web interface.

## Limitations

- Services under institutional domains are not covered unless they are an
  organisation's official website.
- Hosts without publicly logged certificates do not appear in the CT logs.
- Certificates without an issuer organisation are reported as such; these are
  usually self-signed defaults of a reverse proxy.

## License

Code: Apache License 2.0 (see `LICENSE`). Data published on Zenodo: CC BY 4.0.
