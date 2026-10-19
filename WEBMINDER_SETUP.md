# Webminder Installation & Setup Guide

## Quick Start

### Step 1: Configure Environment Variables

Add to your `.env` file:

```bash
WEBMINDER_CLIENT_ID=your_client_id_from_panel
WEBMINDER_CLIENT_SECRET=your_secret_from_panel
WEBMINDER_BRAND_NAME=Ongoing
WEBMINDER_BRAND_URL=https://ongoing.es
```

Or copy from template:
```bash
cat .env.webminder.example >> .env
```

### Step 2: Add Middleware

Edit `app/Http/Kernel.php` and add to `$middleware` array:

```php
protected $middleware = [
    // ... existing middleware
    \App\Webminder\Http\Middleware\MaintenanceMode::class,
];
```

This intercepts all requests to show maintenance page when `maintenance_mode` is active.

### Step 3: Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=webminder-config
```

This creates `config/webminder.php` with all customizable options.

### Step 4: Clear Cache

```bash
php artisan config:clear
php artisan route:clear
```

## Verification

Test that routes are registered:

```bash
php artisan route:list | grep webminder
```

Expected output (11 routes):
```
POST   api/webminder/status
POST   api/webminder/sso
POST   api/webminder/updates
POST   api/webminder/plugins
POST   api/webminder/update
POST   api/webminder/disconnect
POST   api/webminder/maintenance-mode
POST   api/webminder/fix-security
POST   api/webminder/db-status
POST   api/webminder/db-clean
POST   api/webminder/site-health
GET    api/webminder/ping
```

Test ping endpoint (no auth required):

```bash
curl http://yourdomain.com/api/webminder/ping
```

Response:
```json
{
  "success": true,
  "timestamp": 1721648000
}
```

## Integration with Central Panel

The central Pymenetica panel communicates with this Webminder module via authenticated HMAC-SHA256 requests.

### Panel → Server Communication

1. Panel generates HMAC-SHA256 signature:
   ```
   signature = sha256=HMAC-SHA256(payload + timestamp, client_secret)
   ```

2. Panel sends request with headers:
   ```
   X-Webminder-Signature: sha256=<signature>
   X-Webminder-Timestamp: <unix_timestamp>
   ```

3. Server verifies signature using `VerifyWebminderSignature` middleware

4. Server responds with JSON status/action result

### Workflow Example: Remote Database Cleanup

1. **Panel initiates cleanup:**
   ```
   POST /api/webminder/db-clean
   Headers:
     X-Webminder-Signature: sha256=abc123...
     X-Webminder-Timestamp: 1721648000
   Body:
     {"clean_revisions": true, "optimize_tables": true}
   ```

2. **Server processes:**
   - Verifies signature ✓
   - Validates timestamp ✓
   - Executes cleanup operations
   - Logs actions

3. **Server responds:**
   ```json
   {
     "success": true,
     "cleaned": ["clean_revisions", "optimize_tables"],
     "deleted_revisions": 42,
     "optimized_tables": 8
   }
   ```

4. **Panel updates dashboard:**
   - Shows cleanup results
   - Logs activity
   - Updates site health metrics

## Maintenance Mode Workflow

### Activate Maintenance

1. Panel calls `POST /api/webminder/maintenance-mode` with `{"active": true}`
2. Server sets cache entry `webminder:maintenance_mode = true`
3. `MaintenanceMode` middleware intercepts all non-API requests
4. Users see 503 page; admins bypass normally
5. Panel can continue communicating via `/api/webminder/*`

### User Experience During Maintenance

```
Request comes in
    ↓
MaintenanceMode middleware checks
    ↓
Is it /api/* route? → YES: Allow through
    ↓
Is user authenticated admin? → YES: Allow through
    ↓
Is maintenance_mode active? → YES: Return 503 with maintenance page
    ↓
Users see:
- HTTP Status: 503 Service Unavailable
- Message: "En mantenimiento, volvemos en breve"
- Retry-After: 3600 (1 hour)
- No-cache headers (prevents stale cache)
```

### Deactivate Maintenance

1. Panel calls `POST /api/webminder/maintenance-mode` with `{"active": false}`
2. Server clears cache entry
3. All users can access site normally

## Security Considerations

### Request Validation

- **Signature verification:** HMAC-SHA256 on payload + timestamp
- **Timestamp tolerance:** 900 seconds (15 min) to prevent replay attacks
- **No body tampering:** Any change to request body invalidates signature
- **Constant-time comparison:** Using `hash_equals()` to prevent timing attacks

### Best Practices

1. **Keep credentials secret:**
   - Don't expose `WEBMINDER_CLIENT_SECRET` in logs/frontend
   - Rotate credentials periodically
   - Use strong secrets (recommended 32+ chars)

2. **Monitor requests:**
   - Check `storage/logs/laravel.log` for signature failures
   - Unusual access patterns may indicate attacks

3. **Limit operations:**
   - Database cleanup is throttled (500 posts max per request)
   - Prevents timeouts and resource exhaustion
   - Large databases are processed incrementally

4. **Audit trail:**
   - All API requests go through middleware
   - Log important operations (maintenance toggle, db cleanup, etc.)
   - Panel maintains separate audit log

## Troubleshooting

### Routes Not Showing

```bash
php artisan route:clear
php artisan route:list | grep webminder
```

### Config Not Loading

```bash
php artisan config:clear
php artisan config:cache
```

### Signature Verification Failures

Check:
1. Client ID and secret match between panel and `.env`
2. Timestamps are synchronized (both servers have NTP)
3. Request payload hasn't been modified
4. Correct HTTP method (POST vs GET)

### Maintenance Page Not Showing

Check:
1. Middleware is added to `app/Http/Kernel.php`
2. Run `php artisan config:clear`
3. Check cache driver is working: `php artisan tinker`
   ```php
   Cache::put('test', true);
   Cache::has('test');
   ```

### Database Operations Too Slow

Adjust in `.env`:
```bash
# Reduce batch size to prevent timeouts
WEBMINDER_DB_CLEAN_MAX_POSTS=250
```

Then run operation multiple times via panel.

## Monitoring & Logs

### Key Log Patterns

```
# Successful status check:
storage/logs/laravel.log: "Webminder status request" (if logging added)

# Signature failure:
storage/logs/laravel.log: 401 Invalid signature

# Maintenance mode toggled:
storage/logs/laravel.log: "Maintenance mode activated"

# Database cleanup:
storage/logs/laravel.log: "Deleted 42 revisions, 5 spam comments"
```

### Real-time Monitoring

```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log | grep -i webminder

# Filter by date
tail -f storage/logs/laravel.log | grep "2024-07-22"
```

## Performance Notes

- **Ping endpoint:** ~1ms (no database access)
- **Status endpoint:** ~50-200ms (queries DB, filesystem, cache)
- **DB cleanup:** Depends on database size; batched to prevent timeouts
- **Cache:** All configuration cached for instant access

## Future Enhancements

- [ ] Backup before major operations
- [ ] Rollback on failure
- [ ] Real-time operation progress updates
- [ ] Scheduled maintenance windows
- [ ] Webhook notifications
- [ ] Performance metrics trending

---

**Questions?** Check `WEBMINDER.md` for detailed API documentation.
