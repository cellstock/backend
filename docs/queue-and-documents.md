# Queue and document processing

Immediate actions: order slip PDF generation and preview run in the browser from the selected order. Invoice URL retrieval runs through the authenticated order endpoint. POST /api/merchant-addresses/sync fetches only the authenticated user's connected Refurbed account; it is rate-limited and serialized per connection. Pending address edits are preserved.

Keep queued: SyncMarketplace (bulk order reads), SyncRefurbedReferenceData (addresses, returns, shipping profiles across accounts), ImportRefurbedCatalog (large files), ProcessRefurbedOrderNotification (acknowledge webhooks promptly), PushMerchantAddress (retryable remote writes), ProcessMarketplaceOperation (remote mutations and shipping labels), RequestRefurbedWebhookActivation (email delivery). Do not change QUEUE_CONNECTION to sync in production.

Reference sync is unique per connection. Use a shared persistent cache for uniqueness and locks across workers. Schedule commands every minute; command frequencies remain in routes/console.php. Run workers for notifications,marketplaces,default; run imports separately so long catalog imports cannot block interactive jobs. Start with limited worker concurrency and monitor provider rate limits, queue age and failures. Do not start one worker per registered user.

The import job timeout is 7200 seconds, so the database retry_after must be greater than 7200 (for example DB_QUEUE_RETRY_AFTER=7260). A managed worker process is preferable for long jobs; shared-hosting process limits still apply. Use a PHP CLI version compatible with Composer (this project requires >=8.4.1), and restart workers after deployment.

Merchant addresses: Inventory > Merchant addresses > Fetch from Refurbed. Uses MerchantService/ListMerchantAddresses. GET /api/merchant-addresses reads stored records. Address creation/update/deletion remains queued for reliable remote writes. This is distinct from the buyer shipping/billing address on an order.

Document preview uses the browser PDF viewer with its print/download toolbar and an Open document fallback. Slips contain order/customer/item/address data. Invoice preview uses the actual remote or uploaded PDF; an unavailable invoice produces an error, not a fabricated bill. Shipping-label preview requires an existing carrier label and is not a substitute for purchasing/creating one. Provider frame restrictions may require opening remote PDFs in a new tab.
