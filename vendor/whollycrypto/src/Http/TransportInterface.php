<?php

declare(strict_types=1);

namespace WhollyCrypto\PrestaShop\Sdk\Http;

use WhollyCrypto\PrestaShop\Sdk\Options;

interface TransportInterface
{
    public function send(
        #[\SensitiveParameter]
        Request $request,
        Options $options
    ): Response;
}
