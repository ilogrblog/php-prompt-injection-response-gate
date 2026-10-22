# PHP response gate: checking a model's answer before a page shows it

Companion code for the [iLogr](https://ilogr.com/) article **[Prompt Injection in CMS and RAG Systems](https://ilogr.com/prompt-injection-cms-rag-systems/)**.

`ResponseGate.php` is a small PHP class that sits between a language model and the page. It accepts one narrow JSON shape, checks every cited passage against the evidence the application supplied, asks a callback whether each source is still readable and unchanged, and returns an escaped, text-only HTML fragment. It throws on anything else. It is a teaching example for one boundary, not a prompt-injection filter: it cannot tell whether an answer is true.

> **Before you use it**
>
> This is example code for learning, provided as it is and without warranty of any kind. Use it entirely at your own risk. Read it before you run it, try it on a development machine or a staging copy and never on a live site, store or database, and take a backup before you change anything. It makes no network calls and writes no files, but your own integration will not be so limited. Nobody who wrote or published this code accepts any responsibility for loss, damage or other problems that come from using it.

## Requirements

- PHP 8.1 or later (the classes and functions it uses need 8.1). PHP 8.1 no longer receives security fixes, so use a [supported version](https://www.php.net/supported-versions.php), 8.2 or later, for anything beyond reading the code.
- Nothing else: no Composer packages, no extensions beyond the ones PHP ships with, no credentials, no network.
- Docker is optional, for the multi-version and sabotage scripts below.

## Run the checks

```bash
php check.php
```

Each check prints `PASS` or `FAIL`. The script exits with status 0 when every check passes, 1 when any fails, and 2 on a PHP older than 8.1. Notices, warnings and deprecations count as failures. Expected output ends with:

```text
46 checks passed. No model or WordPress integration evaluated.
```

Two optional Docker scripts run the same checks without installing PHP. Both mount the repository read-only, use containers with no network and leave nothing behind. They refuse to download a PHP image unless you pass `ALLOW_IMAGE_PULL=1`.

```bash
./bin/test-versions.sh
```

runs `php -l` and the checks on PHP 8.1 to 8.5, and confirms that PHP 7.4 stops with a clear message.

```bash
./bin/sabotage.sh
```

weakens a copy of `ResponseGate.php` in 27 different ways, one at a time, inside the container, and checks that `check.php` fails for every one. If a weakened copy still passes, a check is missing and the script says which.

## What the gate accepts

```json
{"outcome":"answer","answer":"Follow the recovery checklist.","source_ids":["s1-post842-r17-c3"]}
```

```json
{"outcome":"abstain","answer":"","source_ids":[]}
```

The model emits an empty answer for `abstain`; the application owns and renders the message "No answer could be supported from the available help articles." An answer needs at least one supplied evidence record.

The caller passes trusted request state:

```php
$manifest = [
    's1-post842-r17-c3' => [
        'site_id' => 1,
        'source_id' => 842,
        'revision' => 17,
    ],
];

// $currentSource must use the authenticated request's current permissions.
$html = ResponseGate::render($modelJson, 1, $manifest, $currentSource);
```

`ResponseGate.php` returns an HTML fragment after checking a bounded decoded response and current source state. On failure it throws. The integrating application must catch `Throwable`, log a non-sensitive internal failure and return a generic unavailable response. Never fall back to displaying the raw model output or include private error details in the response.

## Contracts

- The site ID and the whole evidence manifest are server-owned and tied to the authenticated request. They identify every source passage actually supplied to the model, including prior-turn evidence if conversation context is reused. The demonstration starts a fresh request without history.
- `currentSource(int $site, int $source)` must check current access for the authenticated principal and task collection, and return `['revision' => int, 'readable' => bool]`. Missing sources return `null`; backend failures throw. It must not use model-supplied identity or stale cached permission data.
- The revision is an application-owned version of the exact evidence used, including extraction and policy changes. WordPress does not provide this as a single integer covering all content, metadata, attachments and visibility changes. A real adapter must implement it.
- The evidence ID format is checked against its manifest entry: site, source and application revision must agree. The final `c` component names the extracted passage; this demonstration stores no separate chunk number.
- Initial retrieval authorisation must happen before any text reaches the model. This helper only adds a release-time check; it cannot undo a disclosure to a model provider.
- The helper does not revalidate source state for an abstain outcome, because that releases no evidence-derived text. Record the supplied evidence IDs as unvalidated for that outcome, and do not cache the abstention as current source state unless another component validates those versions.
- Checking current source state and releasing a response are not atomic. A production consistency policy must address revocation races, in-flight work and cached answers. A revoked source should block release; an ordinary edit can trigger one re-retrieval and regeneration before failing. A production adapter should batch or memoise current-state reads for several chunks from one source, without caching across requests.
- A valid evidence ID establishes membership in a manifest, not semantic support. The last check deliberately proves that false advice can pass.
- PHP's JSON decoder resolves duplicate object keys to a resulting value. This helper validates that decoded object, not duplicate-key uniqueness in the raw JSON. Do not add a second parser whose interpretation controls a separate action.
- The limits (32 KiB response, 12,000-byte answer, 20 citations, 20 evidence entries) are illustrative, not measured optima. Bound transport buffering before passing an API response to this function.
- The HTML is for a text-node rendering context only. Do not pass it through a Markdown converter, link previewer, linkifier or WordPress content-filter pipeline. Echo it from a dedicated template or component. It contains no URLs as attributes, no executable markup from model text and no tool execution.
- Answers that contain directional controls, soft hyphen, Mongolian vowel separator, zero-width space, word joiner, byte-order mark or any character in the Unicode tag block are rejected, because ordinary HTML escaping leaves them intact. The tag block can carry invisible text into stored history or a later model request. The rule keeps zero-width joiner and non-joiner for legitimate scripts and ZWJ emoji, but also rejects valid tag-sequence emoji such as subdivision flags. A production policy must test the languages and symbols it supports and may prefer visible replacement or normalisation.

The example does not implement ingestion, retrieval, model generation, semantic grounding, injection classification, caches, audit storage, browser integration, WordPress authentication, a write executor or an approval protocol. The article discusses these as architecture; they are not claimed as tested functionality.

## What is in this repository

| Path | What it is |
| --- | --- |
| `ResponseGate.php` | The gate: one static method, `ResponseGate::render()`. |
| `check.php` | 46 deterministic checks with constructed model outputs, including the case where bad advice with an allowed citation passes. |
| `bin/test-versions.sh` | Runs the checks on several PHP versions in Docker (read-only, no network). |
| `bin/sabotage.sh`, `bin/sabotage.php` | Weaken a copy of the gate and confirm that the checks fail. Container only; nothing on your machine changes. |
| `LICENSE` | MIT licence. |

## Tested

PHP 8.1.34, 8.2.35, 8.3.36, 8.4.27 and 8.5.12 (the official `php` Docker images, command-line build), all 46 checks passing. PHP 8.5 is the newest stable branch; PHP 8.6.0 RC3 also passes. PHP 7.4.33 stops with the version message. A later PHP release may print new deprecations; because they count as failures, the checks will say so.

## Licence

MIT, see `LICENSE`. The code uses no WordPress source, so the GPL does not apply here.

More code that accompanies iLogr guides: [github.com/ilogrblog](https://github.com/ilogrblog).
