<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\contracts\ConditionalToolProvider;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\SafeExecution;

/**
 * Commerce tools for Craft CMS.
 *
 * These tools are only registered if Craft Commerce is installed.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class CommerceTools implements ConditionalToolProvider {
    /**
     * Check if Commerce plugin is available.
     *
     * Uses cached plugin state first (fast), falls back to project config
     * to detect plugins installed after MCP server start.
     */
    public static function isAvailable(): bool {
        if (!class_exists(Commerce::class)) {
            return false;
        }

        $plugins = Craft::$app->getPlugins();

        // Fast path: plugin was loaded at Craft bootstrap
        if ($plugins->isPluginEnabled('commerce')) {
            return true;
        }

        // Check project config (reads from YAML, detects post-boot installs)
        $config = Craft::$app->getProjectConfig()->get('plugins.commerce');
        $enabledInConfig = $config !== null && ($config['enabled'] ?? false) === true;

        if (!$enabledInConfig) {
            return false;
        }

        // Plugin is enabled in config but not loaded - try reloading plugins
        $plugins->loadPlugins();

        return $plugins->isPluginEnabled('commerce');
    }

    /**
     * List products from Commerce.
     */
    #[McpTool(
        name: 'list_products',
        description: 'List products from Craft Commerce. Filter by product type handle.',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function listProducts(
        ?string $type = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($type, $limit, $offset): array {
            $this->assertCommerceAvailable();

            $query = Product::find();

            if ($type !== null) {
                $query->type($type);
            }

            $query->limit($limit)->offset($offset);
            $products = $query->all();

            $result = array_map(
                $this->serializeProductSummary(...),
                $products,
            );

            return [
                'count' => count($result),
                'products' => $result,
            ];
        });
    }

    /**
     * Get a single product by ID.
     */
    #[McpTool(
        name: 'get_product',
        description: 'Get detailed information about a single Commerce product by ID',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function getProduct(int $id, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id): array {
            $this->assertCommerceAvailable();

            $commerce = Commerce::getInstance();
            $product = $commerce->getProducts()->getProductById($id);

            if ($product === null) {
                throw new ToolCallException("Product with ID {$id} not found");
            }

            $variants = [];
            foreach ($product->getVariants() as $variant) {
                $variants[] = [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'title' => $variant->title,
                    'price' => $variant->price,
                    'salePrice' => $variant->getSalePrice(),
                    'stock' => $variant->stock,
                    'hasUnlimitedStock' => $variant->hasUnlimitedStock,
                    'minQty' => $variant->minQty,
                    'maxQty' => $variant->maxQty,
                    'isDefault' => $variant->isDefault,
                    'weight' => $variant->weight,
                    'length' => $variant->length,
                    'width' => $variant->width,
                    'height' => $variant->height,
                ];
            }

            return [
                'success' => true,
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'slug' => $product->slug,
                    'typeHandle' => $product->getType()->handle,
                    'status' => $product->getStatus(),
                    'defaultVariantId' => $product->defaultVariantId,
                    'variants' => $variants,
                    'dateCreated' => $product->dateCreated?->format('Y-m-d H:i:s'),
                    'dateUpdated' => $product->dateUpdated?->format('Y-m-d H:i:s'),
                    'postDate' => $product->postDate?->format('Y-m-d H:i:s'),
                    'expiryDate' => $product->expiryDate?->format('Y-m-d H:i:s'),
                ],
            ];
        });
    }

    /**
     * List orders from Commerce.
     */
    #[McpTool(
        name: 'list_orders',
        description: 'List orders from Craft Commerce. Filter by status handle.',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function listOrders(
        ?string $status = null,
        int $limit = 20,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($status, $limit, $offset): array {
            $this->assertCommerceAvailable();

            $query = Order::find();

            // Only completed orders by default
            $query->isCompleted(true);

            if ($status !== null) {
                $query->orderStatus($status);
            }

            $query->limit($limit)->offset($offset)->orderBy('dateOrdered DESC');
            $orders = $query->all();

            $result = [];
            foreach ($orders as $order) {
                $result[] = [
                    'id' => $order->id,
                    'number' => $order->number,
                    'shortNumber' => $order->shortNumber,
                    'reference' => $order->reference,
                    'email' => $order->email,
                    'status' => $order->getOrderStatus()?->handle,
                    'totalPrice' => $order->totalPrice,
                    'totalPaid' => $order->totalPaid,
                    'currency' => $order->currency,
                    'itemCount' => count($order->getLineItems()),
                    'dateOrdered' => $order->dateOrdered?->format('Y-m-d H:i:s'),
                    'dateCreated' => $order->dateCreated?->format('Y-m-d H:i:s'),
                ];
            }

            return [
                'count' => count($result),
                'orders' => $result,
            ];
        });
    }

    /**
     * Get a single order by ID or number.
     */
    #[McpTool(
        name: 'get_order',
        description: 'Get detailed information about a single Commerce order by ID or order number',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function getOrder(?int $id = null, ?string $number = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($id, $number): array {
            $this->assertCommerceAvailable();

            if ($id === null && $number === null) {
                throw new ToolCallException('Either id or number must be provided');
            }

            $commerce = Commerce::getInstance();

            $order = $id !== null
                ? $commerce->getOrders()->getOrderById($id)
                : $commerce->getOrders()->getOrderByNumber($number);

            if ($order === null) {
                $identifier = $id !== null ? "ID {$id}" : "number '{$number}'";

                throw new ToolCallException("Order with {$identifier} not found");
            }

            // Get line items
            $lineItems = [];
            foreach ($order->getLineItems() as $item) {
                $lineItems[] = [
                    'id' => $item->id,
                    'description' => $item->description,
                    'sku' => $item->sku,
                    'qty' => $item->qty,
                    'price' => $item->price,
                    'salePrice' => $item->salePrice,
                    'total' => $item->total,
                ];
            }

            // Get addresses
            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();

            return [
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'shortNumber' => $order->shortNumber,
                    'reference' => $order->reference,
                    'email' => $order->email,
                    'status' => $order->getOrderStatus()?->handle,
                    'isCompleted' => $order->isCompleted,
                    'totalPrice' => $order->totalPrice,
                    'totalPaid' => $order->totalPaid,
                    'totalTax' => $order->totalTax,
                    'totalShippingCost' => $order->totalShippingCost,
                    'totalDiscount' => $order->totalDiscount,
                    'currency' => $order->currency,
                    'paymentCurrency' => $order->paymentCurrency,
                    'lineItems' => $lineItems,
                    'billingAddress' => $billingAddress ? [
                        'fullName' => $billingAddress->fullName,
                        'addressLine1' => $billingAddress->addressLine1,
                        'locality' => $billingAddress->locality,
                        'countryCode' => $billingAddress->countryCode,
                    ] : null,
                    'shippingAddress' => $shippingAddress ? [
                        'fullName' => $shippingAddress->fullName,
                        'addressLine1' => $shippingAddress->addressLine1,
                        'locality' => $shippingAddress->locality,
                        'countryCode' => $shippingAddress->countryCode,
                    ] : null,
                    'dateOrdered' => $order->dateOrdered?->format('Y-m-d H:i:s'),
                    'datePaid' => $order->datePaid?->format('Y-m-d H:i:s'),
                    'dateCreated' => $order->dateCreated?->format('Y-m-d H:i:s'),
                    'dateUpdated' => $order->dateUpdated?->format('Y-m-d H:i:s'),
                ],
            ];
        });
    }

    /**
     * List order statuses.
     */
    #[McpTool(
        name: 'list_order_statuses',
        description: 'List all order statuses configured in Craft Commerce',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function listOrderStatuses(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $this->assertCommerceAvailable();

            $commerce = Commerce::getInstance();
            $statuses = $commerce->getOrderStatuses()->getAllOrderStatuses();

            $result = [];
            foreach ($statuses as $status) {
                $result[] = [
                    'id' => $status->id,
                    'uid' => $status->uid,
                    'name' => $status->name,
                    'handle' => $status->handle,
                    'color' => $status->color,
                    'description' => $status->description,
                    'default' => $status->default,
                    'sortOrder' => $status->sortOrder,
                ];
            }

            return [
                'count' => count($result),
                'statuses' => $result,
            ];
        });
    }

    /**
     * List product types.
     */
    #[McpTool(
        name: 'list_product_types',
        description: 'List all product types configured in Craft Commerce',
    )]
    #[McpToolMeta(category: ToolCategory::COMMERCE)]
    public function listProductTypes(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $this->assertCommerceAvailable();

            $commerce = Commerce::getInstance();
            $types = $commerce->getProductTypes()->getAllProductTypes();

            $result = [];
            foreach ($types as $type) {
                $result[] = [
                    'id' => $type->id,
                    'uid' => $type->uid,
                    'name' => $type->name,
                    'handle' => $type->handle,
                    'hasDimensions' => $type->hasDimensions,
                    'maxVariants' => $type->maxVariants,
                    'hasVariantTitleField' => $type->hasVariantTitleField,
                ];
            }

            return [
                'count' => count($result),
                'productTypes' => $result,
            ];
        });
    }

    /**
     * Serialize a product for list view.
     *
     * @return array<string, mixed>
     */
    private function serializeProductSummary(Product $product): array {
        $variants = array_map(
            fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock' => $variant->stock,
                'isDefault' => $variant->isDefault,
            ],
            $product->getVariants(),
        );

        return [
            'id' => $product->id,
            'title' => $product->title,
            'slug' => $product->slug,
            'typeHandle' => $product->getType()->handle,
            'status' => $product->getStatus(),
            'variants' => $variants,
            'dateCreated' => $product->dateCreated?->format('Y-m-d H:i:s'),
            'dateUpdated' => $product->dateUpdated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Assert Commerce is available, throw exception if not.
     *
     * @throws ToolCallException
     */
    private function assertCommerceAvailable(): void {
        if (!self::isAvailable()) {
            throw new ToolCallException('Craft Commerce is not installed or not enabled');
        }
    }
}
