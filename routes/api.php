<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\MarketplaceWebhookController;
use App\Http\Controllers\Api\MerchantAddressController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\RefurbedCatalogController;
use App\Http\Controllers\Api\RefurbedWebhookController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\MarketplacePushPreferenceController;
use App\Http\Controllers\Api\SellerProfileController;
use App\Http\Controllers\Api\ShippingProfileController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SynchronizationIssueController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/health', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'CelleXa API is running',
    ]);
});

Route::post('/contact', ContactController::class)->middleware('throttle:10,1');

Route::post(
    '/webhooks/refurbed/orders/{connection}/{token}',
    RefurbedWebhookController::class
)->middleware('throttle:120,1')->name('webhooks.refurbed.orders');

Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/register', 'register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', 'me');
        Route::patch('/profile', 'updateProfile');
        Route::get('/profile/avatar', 'avatar');
        Route::post('/profile/avatar', 'updateAvatar');
        Route::delete('/profile/avatar', 'deleteAvatar');
        Route::post('/logout', 'logout');
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->middleware('role:super_admin,admin')->name('dashboard');
    Route::apiResource('users', UserController::class)->middleware('role:super_admin,admin');
    Route::get('/roles', RoleController::class)->middleware('role:super_admin,admin');
    Route::get('/my-subscription', [SubscriptionController::class, 'mine']);
    Route::patch('/my-subscription', [SubscriptionController::class, 'updateMine']);
    Route::get('/available-plans', [PlanController::class, 'available']);
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::apiResource('plans', PlanController::class);
        Route::apiResource('subscriptions', SubscriptionController::class);
    });

    Route::prefix('marketplaces')
        ->middleware('role:super_admin,admin')
        ->controller(MarketplaceController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');

            Route::prefix('{marketplace}')->group(function () {
                Route::get('/', 'show')->name('show');
                Route::post('/sync', 'sync')->name('sync.store');
                Route::get('/sync/{syncRun}', 'syncRun')->whereNumber('syncRun')->name('sync.show');

                Route::prefix('connection')->name('connection.')->group(function () {
                    Route::post('/options', 'connectionOptions')->name('options');
                    Route::post('/', 'connect')->name('store');
                    Route::patch('/', 'updateConnection')->name('update');
                    Route::post('/test', 'testConnection')->name('test');
                    Route::delete('/', 'disconnect')->name('destroy');
                    Route::get('/webhook', [MarketplaceWebhookController::class, 'show'])->name('webhook.show');
                    Route::post('/webhook', [MarketplaceWebhookController::class, 'store'])->name('webhook.store');
                });
            });
        });

    Route::prefix('orders')
        ->middleware('role:super_admin,admin')
        ->name('orders.')
        ->controller(OrderController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/returns', 'returnIndex')->name('returns.all');
            Route::get('/{order}', 'show')->whereNumber('order')->name('show');
            Route::patch('/{order}/status', 'updateStatus')->whereNumber('order')->name('status.update');
            Route::post('/{order}/refresh', 'refresh')->whereNumber('order')->name('refresh');
            Route::get('/{order}/returns', 'returns')->whereNumber('order')->name('returns.index');
            Route::post('/{order}/refund/calculate', 'calculateRefund')->whereNumber('order')->name('refund.calculate');
            Route::post('/{order}/refund', 'refund')->whereNumber('order')->name('refund.store');
            Route::get('/{order}/invoice', 'invoice')->whereNumber('order')->name('invoice');
            Route::get('/{order}/invoice/custom', 'customInvoice')->whereNumber('order')->name('invoice.custom');
            Route::post('/{order}/shipping-label', 'createShippingLabel')->whereNumber('order')->name('shipping-label.create');
            Route::get('/{order}/merchant-addresses', 'merchantAddresses')->whereNumber('order')->name('merchant-addresses.index');
            Route::patch('/{order}/edit-fields', 'updateEditFields')->whereNumber('order')->name('edit-fields.update');
            Route::patch('/{order}/address/{type}', 'updateAddress')->whereNumber('order')->name('address.update');
            Route::post('/{order}/invoice', 'uploadInvoice')->whereNumber('order')->name('invoice.upload');
            Route::patch('/{order}/items/{item}/shipping', 'updateItemShipping')->whereNumber(['order', 'item'])->name('items.shipping.update');
            Route::post('/{order}/email', 'sendBuyerEmail')->whereNumber('order')->name('email.send');
        });

    Route::prefix('inventory')->middleware('role:super_admin,admin')->controller(InventoryController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/sync', 'sync');
        Route::get('/diagnostics', 'diagnostics');
        Route::get('/instance', 'instance');
        Route::post('/offers', 'store');
        Route::get('/offers/{offerId}', 'show');
        Route::patch('/offers/{offerId}', 'update');
        Route::delete('/offers/{offerId}', 'destroy');
        Route::post('/import', 'import');
        Route::post('/bulk', 'bulk');
    });
    Route::prefix('refurbed-catalog')
        ->middleware('role:super_admin,admin')
        ->controller(RefurbedCatalogController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/imports', 'store');
            Route::post('/imports/chunks', 'storeChunk');
            Route::get('/imports/{catalogImport}', 'show')->whereNumber('catalogImport');
            Route::get('/items', 'search');
        });
    Route::prefix('shipping-profiles')->middleware('role:super_admin,admin')->controller(ShippingProfileController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/sync', 'sync');
        Route::get('/carriers', 'carriers');
        Route::post('/', 'store');
        Route::patch('/{profileId}', 'update');
        Route::delete('/{profileId}', 'destroy');
    });
    Route::prefix('merchant-addresses')->middleware('role:super_admin,admin')->controller(MerchantAddressController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/sync', 'sync')->middleware('throttle:6,1');
        Route::post('/', 'store');
        Route::patch('/{address}', 'update')->whereNumber('address');
        Route::delete('/{address}', 'destroy')->whereNumber('address');
    });
    Route::prefix('synchronization-issues')->middleware('role:super_admin,admin')->controller(SynchronizationIssueController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/{issue}/retry', 'retry');
    });
    Route::prefix('seller-profile')
        ->middleware('role:super_admin,admin')
        ->controller(SellerProfileController::class)
        ->group(function () {
            Route::get('/', 'show');
            Route::patch('/', 'update');
            Route::get('/logo', 'logo');
            Route::post('/logo', 'updateLogo');
            Route::delete('/logo', 'deleteLogo');
        });
    Route::prefix('push-preferences')
        ->middleware('role:super_admin,admin')
        ->controller(MarketplacePushPreferenceController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/run', 'run');
            Route::put('/{marketplace}', 'update')->where('marketplace', 'refurbed|back-market-eu');
        });
});
