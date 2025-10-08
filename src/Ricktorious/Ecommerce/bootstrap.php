<?php

declare(strict_types=1);

require_once __DIR__ . '/Core/BlockType.php';
require_once __DIR__ . '/Core/BlockRegistry.php';
require_once __DIR__ . '/Core/ContentManager.php';
require_once __DIR__ . '/Core/ExtensionInterface.php';
require_once __DIR__ . '/Core/AdhocApiRouter.php';
require_once __DIR__ . '/Core/ExtensionManager.php';
require_once __DIR__ . '/Core/Application.php';
require_once __DIR__ . '/Analytics/UserBehaviorTracker.php';
require_once __DIR__ . '/AI/PersonalizationEngine.php';
require_once __DIR__ . '/Catalog/Product.php';
require_once __DIR__ . '/Catalog/ProductRepository.php';
require_once __DIR__ . '/Checkout/Cart.php';
require_once __DIR__ . '/Checkout/CheckoutService.php';
require_once __DIR__ . '/Orders/OrderRepository.php';
require_once __DIR__ . '/Orders/OrderProcessor.php';
require_once __DIR__ . '/CRM/CustomerProfile.php';
require_once __DIR__ . '/CRM/CustomerRepository.php';
require_once __DIR__ . '/CRM/InteractionRepository.php';
require_once __DIR__ . '/CRM/CRMService.php';
require_once __DIR__ . '/Shipping/ShippingService.php';
require_once __DIR__ . '/User/User.php';
require_once __DIR__ . '/User/UserRepository.php';
require_once __DIR__ . '/User/UserService.php';
require_once __DIR__ . '/POS/PointOfSaleService.php';
require_once __DIR__ . '/Extensions/CoreContentExtension.php';
require_once __DIR__ . '/Extensions/CommerceExtension.php';
require_once __DIR__ . '/Extensions/OperationsExtension.php';
require_once __DIR__ . '/Extensions/FulfillmentExtension.php';
require_once __DIR__ . '/Extensions/UserManagementExtension.php';
