<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle;

use Symfony\Component\HttpFoundation\Request;

interface SpanAttributeExtractorInterface
{
    /** @return array<string, mixed> */
    public function fromRequest(Request $request): array;

    /** @return array<string, mixed> */
    public function fromMessageBody(?string $body): array;
}
