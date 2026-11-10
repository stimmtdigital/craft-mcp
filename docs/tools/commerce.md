# Commerce Tools

Read products and orders from Craft Commerce. These tools exist only while Commerce is installed and enabled. Lite edition.

Products and orders are admin-only: they need a Craft admin user and, over HTTP, the `mcp:admin` scope. Order statuses and product types are configuration and need only `mcp:read`.

---

## stimmt_commerce_products_list

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `type` | string | null | Product type handle; `stimmt_commerce_product_types_list` reports them |
| `limit` | int | 20 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip |

Returns the paginated envelope from the [Tools Reference](README.md#response-shape).

---

## stimmt_commerce_product_get

One product by id, with its variants.

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Product id |

Returns `success` and `product`: `id`, `title`, `slug`, `typeHandle`, `status`, `defaultVariantId`, the dates, and `variants` (each with `id`, `sku`, `title`, `price`, `salePrice`, `stock`, `hasUnlimitedStock`, `minQty`, `maxQty`, `isDefault` and dimensions).

---

## stimmt_commerce_orders_list

Completed orders only, so carts never appear.

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `status` | string | null | Order status handle; `stimmt_commerce_order_statuses_list` reports them |
| `limit` | int | 20 | Rows to return, 1 or more |
| `offset` | int | 0 | Rows to skip |

Returns the paginated envelope.

---

## stimmt_commerce_order_get

One order, by id or by number. Pass one of the two.

| Name | Type | Description |
|------|------|-------------|
| `id` | int | Order id |
| `number` | string | The order's full number, not the short number the control panel shows |

Returns `success` and `order`: number, reference, email, status, totals (price, paid, tax, shipping, discount), currencies, `lineItems`, billing and shipping address (name, locality, country code), and dates.

---

## stimmt_commerce_order_statuses_list

All configured order statuses. No parameters.

---

## stimmt_commerce_product_types_list

All configured product types. No parameters.
