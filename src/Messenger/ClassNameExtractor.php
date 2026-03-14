<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Messenger;

final class ClassNameExtractor implements NameExtractorInterface
{
    public function execute($message): string
    {
        return $message::class;
    }
}
