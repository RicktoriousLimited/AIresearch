<?php

declare(strict_types=1);

namespace Ricktorious\Ecommerce\App;

interface ServiceProviderInterface
{
    public function register(ServiceContainer $container): void;
}
