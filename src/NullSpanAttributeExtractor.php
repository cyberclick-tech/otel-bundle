<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle;

use Symfony\Component\HttpFoundation\Request;

final class NullSpanAttributeExtractor implements SpanAttributeExtractorInterface
{
    public function fromRequest(Request $request): array
    {
        return [];
    }

    public function fromMessageBody(?string $body): array
    {
        return [];
    }
}
