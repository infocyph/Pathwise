<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\StreamHandler\Concerns;

use Infocyph\Pathwise\Exceptions\UploadException;
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;

trait UploadTrustPolicyConcern
{
    private const int STRICT_MAX_CHUNK_COUNT = 1000;

    private const int STRICT_MAX_CHUNK_SIZE = 8 * 1024 * 1024;

    private UploadTrustProfile $trustProfile = UploadTrustProfile::STANDARD;

    public function getTrustProfile(): UploadTrustProfile
    {
        return $this->trustProfile;
    }

    public function setTrustProfile(UploadTrustProfile $profile): void
    {
        $this->trustProfile = $profile;
        if ($profile !== UploadTrustProfile::UNTRUSTED_DATA) {
            return;
        }

        if ($this->maxChunkCount === 0) {
            $this->maxChunkCount = self::STRICT_MAX_CHUNK_COUNT;
        }
        if ($this->maxChunkSize === 0) {
            $this->maxChunkSize = self::STRICT_MAX_CHUNK_SIZE;
        }

        $this->namingStrategy = 'hash';
        $this->strictContentTypeValidation = true;
    }

    private function assertStrictChunkLimitsConfigured(): void
    {
        if (
            $this->isStrictUntrustedProfile()
            && ($this->maxChunkCount <= 0 || $this->maxChunkSize <= 0)
        ) {
            throw new UploadException('Strict untrusted uploads require finite chunk count and size limits.');
        }
    }

    private function isStrictUntrustedProfile(): bool
    {
        return $this->trustProfile === UploadTrustProfile::UNTRUSTED_DATA;
    }

    private function publishedDirectoryMode(): ?int
    {
        return $this->isStrictUntrustedProfile() ? 0700 : null;
    }

    private function publishedFileMode(): ?int
    {
        return $this->isStrictUntrustedProfile() ? 0600 : null;
    }
}
