# Intercom Integration

Shared Intercom package

## Features

- **Event Tracking**: Track user actions and behaviors
- **User Management**: Create and update user profiles
- **Company Management**: Manage tenant/organization data  
- **Async Processing**: Queue-based event processing for performance
- **Multi-tenant Support**: Automatic tenant context in events
- **Silent Failures**: Production-safe error handling
- **Batch Processing**: Efficient bulk event tracking

## Installation & Configuration

### 1. Copy Configuration
```bash
cp vendor/nuimarkets/laravel-shared-utils/config/intercom.php config/
```

### 2. Environment Variables
```env
INTERCOM_ENABLED=true
INTERCOM_TOKEN=your_intercom_access_token
INTERCOM_API_VERSION=2.13
INTERCOM_SERVICE_NAME=xyz
INTERCOM_TIMEOUT=10
INTERCOM_FAIL_SILENTLY=true
INTERCOM_BATCH_SIZE=50
INTERCOM_EVENT_PREFIX=nui
```

### 3. Service Registration
In `app/Providers/AppServiceProvider.php`:
```php
public function register(): void
{
    $this->app->singleton(\Nuimarkets\LaravelSharedUtils\Services\IntercomService::class);
}
```

### 4. Event Mapping
In `app/Providers/EventServiceProvider.php`:
```php
protected $listen = [
    \Nuimarkets\LaravelSharedUtils\Events\IntercomEvent::class => [
        \Nuimarkets\LaravelSharedUtils\Listeners\IntercomListener::class,
    ],
];
```

## Usage Examples

### Controller Integration
```php
use Nuimarkets\LaravelSharedUtils\Http\Controllers\Traits\TracksIntercomEvents;

class ProductController extends Controller
{
    use TracksIntercomEvents;
    
    public function show($id, Request $request)
    {
        $product = Product::find($id);
        
        // Track product view
        $this->trackProductView($id, [
            'name' => $product->name,
            'category' => $product->category->name,
            'price' => $product->price
        ], $request);
        
        return response()->json($product);
    }
    
    public function store(Request $request)
    {
        $product = Product::create($request->validated());
        
        // Track product creation
        $this->trackUserAction('created', 'product', $product->id, [
            'name' => $product->name
        ], $request);
        
        return response()->json($product, 201);
    }
}
```

### Direct Event Dispatching
```php
use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;

// Track custom events
event(new IntercomEvent(
    'user-123',
    'order_completed', 
    [
        'order_id' => 'ord-456',
        'total_amount' => 125.50,
        'items_count' => 3,
        'payment_method' => 'credit_card'
    ],
    'tenant-789'
));

// Track user registration
event(new IntercomEvent(
    'user-123',
    'user_registered',
    [
        'source' => 'web_signup',
        'plan' => 'premium'
    ]
));
```

### Service Direct Usage
```php
use Nuimarkets\LaravelSharedUtils\Services\IntercomService;

class OrderService
{
    public function __construct(
        private IntercomService $intercom
    ) {}
    
    public function processOrder($order)
    {
        // Process order logic...
        
        // Update user profile
        $this->intercom->createOrUpdateUser($order->user_id, [
            'email' => $order->user->email,
            'name' => $order->user->name,
            'last_order_amount' => $order->total,
            'total_orders' => $order->user->orders->count()
        ]);
        
        // Update company profile
        $this->intercom->createOrUpdateCompany($order->tenant_id, [
            'name' => $order->tenant->name,
            'monthly_revenue' => $order->tenant->monthly_revenue
        ]);
        
        // Track event
        $this->intercom->trackEvent($order->user_id, 'order_processed', [
            'order_id' => $order->id,
            'amount' => $order->total
        ]);
    }
}
```

### Batch Event Processing
```php
$events = [
    [
        'user_id' => 'user-1',
        'event' => 'product_viewed',
        'properties' => ['product_id' => 'prod-1']
    ],
    [
        'user_id' => 'user-2', 
        'event' => 'product_added_to_cart',
        'properties' => ['product_id' => 'prod-2', 'quantity' => 2]
    ]
];

$results = $intercomService->batchTrackEvents($events);
```

## Event Naming Convention

Events are automatically prefixed with your configured prefix and formatted:
- `product_viewed` → `myapp_product_viewed` (with `INTERCOM_EVENT_PREFIX=myapp`)
- `product_viewed` → `product_viewed` (with `INTERCOM_EVENT_PREFIX=` empty)

## Required Request Parameters

The controller trait methods expect these request parameters:
- `userID`: The user identifier
- `tenant_uuid`: The tenant/organization identifier (optional)

## Testing

The package includes comprehensive tests for all components. When testing in your service:

```php
// Mock Intercom in tests
Http::fake([
    'api.intercom.io/*' => Http::response(['status' => 'ok'], 200)
]);

Event::fake([IntercomEvent::class]);

// Your test code...

Event::assertDispatched(IntercomEvent::class);
```

## Error Handling

- **Silent Failures**: By default, Intercom errors won't break your application
- **Logging**: All failures are logged with context for debugging
- **Queue Resilience**: Failed jobs are logged but don't retry to prevent blocking
- **Disabled State**: When disabled, all operations return safely without API calls

## Best Practices

1. **Always use async processing**: Don't call IntercomService directly in request cycle
2. **Batch when possible**: Use `batchTrackEvents()` for multiple events
3. **Include context**: Add relevant metadata to event properties
4. **Test disabled state**: Ensure your app works when Intercom is disabled
5. **Monitor logs**: Watch for failures and API rate limiting

## Common Event Types

- `user_registered`, `user_login`, `user_logout`
- `product_viewed`, `product_searched`, `product_added_to_cart`
- `order_created`, `order_completed`, `order_cancelled`
- `feature_used`, `subscription_upgraded`, `support_ticket_created`