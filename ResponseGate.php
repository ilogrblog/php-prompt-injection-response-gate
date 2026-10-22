<?php
declare(strict_types=1);

/** Educational response boundary, PHP 8.1+. No semantic injection detection. */
final class ResponseGate
{
    private const ABSTAIN_MESSAGE = 'No answer could be supported from the available help articles.';

    /**
     * $siteId and $manifest must come from the application, never model output.
     * $currentSource(siteId, sourceId) returns current revision and readable bool
     * for the authenticated user, or null on missing source. Errors must throw.
     * This callback is illustrative, not an implemented WordPress ACL adapter.
     */
    public static function render(
        string $json,
        int $siteId,
        array $manifest,
        callable $currentSource
    ): string {
        if ($siteId < 1 || count($manifest) > 20) {
            throw new RuntimeException('invalid_request_context');
        }

        // Validate server-owned request state before parsing untrusted output.
        foreach ($manifest as $id => $source) {
            $matches = [];
            if (!is_string($id)
                || preg_match(
                    '/\As(?<site>[1-9][0-9]*)-post(?<source>[1-9][0-9]*)-r(?<revision>[1-9][0-9]*)-c[1-9][0-9]*\z/D',
                    $id,
                    $matches
                ) !== 1
                || !is_array($source)
                || ($source['site_id'] ?? null) !== $siteId
                || !is_int($source['source_id'] ?? null)
                || $source['source_id'] < 1
                || !is_int($source['revision'] ?? null)
                || $source['revision'] < 1
                || (int) $matches['site'] !== $source['site_id']
                || (int) $matches['source'] !== $source['source_id']
                || (int) $matches['revision'] !== $source['revision']
            ) {
                throw new RuntimeException('invalid_manifest');
            }
        }

        if (strlen($json) > 32768) {
            throw new RuntimeException('response_too_large');
        }
        $response = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        if (!$response instanceof stdClass) {
            throw new RuntimeException('invalid_response');
        }
        $keys = array_keys(get_object_vars($response));
        sort($keys);
        if ($keys !== ['answer', 'outcome', 'source_ids']
            || !is_string($response->outcome)
            || !is_string($response->answer)
            || !is_array($response->source_ids)
            || !array_is_list($response->source_ids)
            || count($response->source_ids) > 20
        ) {
            throw new RuntimeException('invalid_response');
        }

        $escape = static fn(string $text): string => htmlspecialchars(
            $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'
        );

        if ($response->outcome === 'abstain') {
            if ($response->answer !== '' || $response->source_ids !== []) {
                throw new RuntimeException('invalid_response');
            }
            return '<div class="rag-answer"><p>' . $escape(self::ABSTAIN_MESSAGE) . '</p></div>';
        }
        if ($response->outcome !== 'answer'
            || preg_match('/\A[\p{Z}\s]*\z/u', $response->answer) === 1
            || preg_match('/[\x{00AD}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{E0000}-\x{E007F}]/u', $response->answer) === 1
            || strlen($response->answer) > 12000
            || count($response->source_ids) < 1
            || $manifest === []
        ) {
            throw new RuntimeException('invalid_response');
        }

        // Reject unusable citations before calling the policy adapter.
        $seen = [];
        foreach ($response->source_ids as $id) {
            if (!is_string($id) || !array_key_exists($id, $manifest) || isset($seen[$id])) {
                throw new RuntimeException('invalid_citation');
            }
            $seen[$id] = true;
        }

        // Check ALL supplied evidence. An edited or revoked uncited passage may
        // still have shaped the answer.
        $hasChanged = false;
        foreach ($manifest as $source) {
            $current = $currentSource($siteId, $source['source_id']);
            if (!is_array($current)
                || ($current['readable'] ?? null) !== true
            ) {
                throw new RuntimeException('evidence_unreadable');
            }
            if (($current['revision'] ?? null) !== $source['revision']) {
                $hasChanged = true;
            }
        }
        if ($hasChanged) {
            throw new RuntimeException('evidence_changed');
        }
        $items = array_map(static fn(string $id): string => '<li>' . $escape($id) . '</li>',
            $response->source_ids);
        // Text nodes only. No Markdown engine, links, resources or tools.
        return '<div class="rag-answer"><p>' . $escape($response->answer)
            . '</p><ul>' . implode('', $items) . '</ul></div>';
    }
}
