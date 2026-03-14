<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Messenger;

interface NameExtractorInterface
{
    public function execute($message): string;
}
