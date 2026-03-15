<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Messenger;

interface NameExtractorInterface
{
    public function execute($message): string;
}
