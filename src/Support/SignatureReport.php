<?php

declare(strict_types=1);

/**
 * Derafu: UBL - One Language for Every Business Document.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Ubl\Support;

use Derafu\Signature\Contract\SignatureValidationResultInterface;
use Derafu\Ubl\Exception\UblException;

/**
 * What the validation of the signatures of a UBL document found: the result of
 * each signature, and what the document does not follow of the UBL Digital
 * Signature Profile.
 */
final class SignatureReport
{
    /**
     * @param array<SignatureValidationResultInterface> $signatures One result
     * for each signature of the document.
     * @param list<UblException> $issues What the document does not follow of the
     * conformance of the profile that is required (what the profile says it
     * MUST), not what it recommends.
     */
    public function __construct(
        private readonly array $signatures,
        private readonly array $issues,
    ) {
    }

    /**
     * The result of each signature of the document.
     *
     * @return list<SignatureValidationResultInterface>
     */
    public function getSignatures(): array
    {
        return array_values($this->signatures);
    }

    /**
     * What the document does not follow of the conformance of the profile.
     *
     * @return list<UblException>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Indicates if every signature of the document is valid.
     */
    public function areSignaturesValid(): bool
    {
        foreach ($this->signatures as $signature) {
            if (!$signature->isValid()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indicates if the document follows the conformance of the profile.
     */
    public function isConformant(): bool
    {
        return $this->issues === [];
    }

    /**
     * Indicates if every signature is valid and the document follows the
     * conformance of the profile.
     */
    public function isValid(): bool
    {
        return $this->areSignaturesValid() && $this->isConformant();
    }
}
