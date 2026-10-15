<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\http\fetch\FetchRefused;
use stimmt\craft\Mcp\http\fetch\SafeFetch;

/**
 * The bytes of a file handed to upload_asset.
 *
 * WHY two inputs: MCP has no binary channel, and a model cannot realistically
 * put a megabyte image into a tool call as base64, so a URL the server fetches
 * is the useful path for generated images. base64 stays for the small files
 * (an SVG, an icon) that have no URL. Both answer to the same cap.
 *
 * @author stimmt.digital
 */
final readonly class AssetIntake {
    public function __construct(
        private SafeFetch $fetch,
        private int $maxBytes,
    ) {
    }

    public function bytes(?string $url, ?string $data): string {
        if (($url === null) === ($data === null)) {
            throw new ToolCallException('Pass exactly one of url (a file on the public internet, fetched by the server) or data (a small file as base64).');
        }

        $bytes = $url !== null ? $this->fetched($url) : $this->decoded((string) $data);
        if ($bytes === '') {
            throw new ToolCallException('The file is empty; there is nothing to upload.');
        }

        return $bytes;
    }

    private function fetched(string $url): string {
        try {
            return $this->fetch->fetch($url)->body;
        } catch (FetchRefused $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }

    private function decoded(string $data): string {
        // A data: URI is what a model most often has in hand; its prefix is
        // not part of the base64.
        $encoded = (string) preg_replace(['/^data:[^,]*,/', '/\s+/'], '', $data);

        // Checked before decoding, so an oversized payload is not decoded
        // just to be refused.
        if (intdiv(strlen($encoded) * 3, 4) > $this->maxBytes + 2) {
            throw $this->tooLarge();
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new ToolCallException('data is not valid base64. Pass the file base64-encoded, optionally as a data: URI.');
        }

        if (strlen($bytes) > $this->maxBytes) {
            throw $this->tooLarge();
        }

        return $bytes;
    }

    private function tooLarge(): ToolCallException {
        return new ToolCallException("The file is larger than the {$this->maxBytes}-byte limit (the assetUploadMaxBytes setting).");
    }
}
