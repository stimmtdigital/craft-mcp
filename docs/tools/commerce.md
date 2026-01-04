# Commerce Tools

Commerce tools provide access to Craft Commerce data—products, orders, and their associated configurations. These tools are only available when Craft Commerce is installed and enabled.

> **Note:** These tools are conditionally registered. If Commerce isn't installed, they won't appear in the tool list.

## Products

Products in Commerce represent items for sale. Each product has one or more variants that define specific purchasable options with their own SKU, price, and inventory.

### list_products

List products with optional filtering by product type.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `type` | string | null | Filter by product type handle |
| `limit` | int | 20 | Maximum products to return |
| `offset` | int | 0 | Number of products to skip |

**Examples:**

```
# List all products
list_products

# Filter by product type
list_products type="clothing" limit=50

# Paginate through results
list_products limit=20 offset=40
```

**Response:**

```json
{
  "count": 10,
  "products": [
    {
      "id": 123,
      "title": "Classic T-Shirt",
      "slug": "classic-t-shirt",
      "typeHandle": "clothing",
      "status": "live",
      "variants": [
        {
          "id": 456,
          "sku": "TSHIRT-S",
          "price": 29.99,
          "stock": 50,
          "isDefault": true
        },
        {
          "id": 457,
          "sku": "TSHIRT-M",
          "price": 29.99,
          "stock": 35,
          "isDefault": false
        }
      ],
      "dateCreated": "2024-01-10 09:00:00",
      "dateUpdated": "2024-01-15 14:30:00"
    }
  ]
}
```

---

### get_product

Get detailed information about a single product, including complete variant data with dimensions and inventory settings.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | Yes | Product ID |

**Example:**

```
get_product id=123
```

**Response:**

```json
{
  "success": true,
  "product": {
    "id": 123,
    "title": "Classic T-Shirt",
    "slug": "classic-t-shirt",
    "typeHandle": "clothing",
    "status": "live",
    "defaultVariantId": 456,
    "variants": [
      {
        "id": 456,
        "sku": "TSHIRT-S",
        "title": "Small",
        "price": 29.99,
        "salePrice": 24.99,
        "stock": 50,
        "hasUnlimitedStock": false,
        "minQty": 1,
        "maxQty": 10,
        "isDefault": true,
        "weight": 0.2,
        "length": 30,
        "width": 25,
        "height": 2
      }
    ],
    "dateCreated": "2024-01-10 09:00:00",
    "dateUpdated": "2024-01-15 14:30:00",
    "postDate": "2024-01-10 12:00:00",
    "expiryDate": null
  }
}
```

The `salePrice` reflects any active sales or discounts applied to the variant.

---

## Orders

Orders represent completed (or in-progress) purchases. Each order contains line items, customer information, and payment details.

### list_orders

List completed orders with optional filtering by status.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `status` | string | null | Filter by order status handle |
| `limit` | int | 20 | Maximum orders to return |
| `offset` | int | 0 | Number of orders to skip |

**Examples:**

```
# List recent orders
list_orders limit=10

# Filter by status
list_orders status="shipped"

# Paginate
list_orders limit=20 offset=40
```

**Response:**

```json
{
  "count": 10,
  "orders": [
    {
      "id": 789,
      "number": "abc123def456",
      "shortNumber": "abc123",
      "reference": "1001",
      "email": "customer@example.com",
      "status": "processing",
      "totalPrice": 59.98,
      "totalPaid": 59.98,
      "currency": "USD",
      "itemCount": 2,
      "dateOrdered": "2024-01-15 10:30:00",
      "dateCreated": "2024-01-15 10:25:00"
    }
  ]
}
```

Only completed orders (where `isCompleted` is true) are returned. This excludes abandoned carts.

---

### get_order

Get complete details for a single order, including line items and addresses.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `id` | int | No | Order ID (takes precedence if both provided) |
| `number` | string | No | Full order number |

At least one parameter must be provided.

**Examples:**

```
# Get by ID
get_order id=789

# Get by order number
get_order number="abc123def456"
```

**Response:**

```json
{
  "success": true,
  "order": {
    "id": 789,
    "number": "abc123def456",
    "shortNumber": "abc123",
    "reference": "1001",
    "email": "customer@example.com",
    "status": "processing",
    "isCompleted": true,
    "totalPrice": 59.98,
    "totalPaid": 59.98,
    "totalTax": 4.80,
    "totalShippingCost": 5.99,
    "totalDiscount": 0,
    "currency": "USD",
    "paymentCurrency": "USD",
    "lineItems": [
      {
        "id": 1001,
        "description": "Classic T-Shirt (Small)",
        "sku": "TSHIRT-S",
        "qty": 2,
        "price": 29.99,
        "salePrice": 24.99,
        "total": 49.98
      }
    ],
    "billingAddress": {
      "fullName": "John Doe",
      "addressLine1": "123 Main Street",
      "locality": "New York",
      "countryCode": "US"
    },
    "shippingAddress": {
      "fullName": "John Doe",
      "addressLine1": "123 Main Street",
      "locality": "New York",
      "countryCode": "US"
    },
    "dateOrdered": "2024-01-15 10:30:00",
    "datePaid": "2024-01-15 10:31:00",
    "dateCreated": "2024-01-15 10:25:00",
    "dateUpdated": "2024-01-15 10:31:00"
  }
}
```

---

## Configuration

### list_order_statuses

List all order statuses configured in Commerce. Order statuses define the workflow stages an order moves through.

**Parameters:** None

**Example:**

```
list_order_statuses
```

**Response:**

```json
{
  "count": 4,
  "statuses": [
    {
      "id": 1,
      "uid": "a1b2c3d4-...",
      "name": "New",
      "handle": "new",
      "color": "green",
      "description": "Newly placed orders",
      "default": true,
      "sortOrder": 1
    },
    {
      "id": 2,
      "uid": "e5f6g7h8-...",
      "name": "Processing",
      "handle": "processing",
      "color": "blue",
      "description": "Orders being prepared",
      "default": false,
      "sortOrder": 2
    },
    {
      "id": 3,
      "uid": "i9j0k1l2-...",
      "name": "Shipped",
      "handle": "shipped",
      "color": "purple",
      "description": "Orders that have been shipped",
      "default": false,
      "sortOrder": 3
    }
  ]
}
```

The `default` status is automatically assigned to new orders.

---

### list_product_types

List all product types configured in Commerce. Product types define the structure and behavior of products.

**Parameters:** None

**Example:**

```
list_product_types
```

**Response:**

```json
{
  "count": 2,
  "productTypes": [
    {
      "id": 1,
      "uid": "m3n4o5p6-...",
      "name": "Clothing",
      "handle": "clothing",
      "hasDimensions": true,
      "maxVariants": null,
      "hasVariantTitleField": true
    },
    {
      "id": 2,
      "uid": "q7r8s9t0-...",
      "name": "Digital Downloads",
      "handle": "digital",
      "hasDimensions": false,
      "maxVariants": 1,
      "hasVariantTitleField": false
    }
  ]
}
```

Key fields:

- **hasDimensions**: Whether variants track weight/dimensions for shipping
- **maxVariants**: Limit on variants per product (null = unlimited)
- **hasVariantTitleField**: Whether variants have their own title field

## Error Handling

If Commerce isn't installed or enabled, all Commerce tools return:

```json
{
  "success": false,
  "error": "Craft Commerce is not installed or not enabled"
}
```

This allows AI assistants to gracefully handle installations without Commerce.
