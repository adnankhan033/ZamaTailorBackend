# Fix Customer Profiles API Endpoint

## Quick Fix Commands

Run these commands in your Drupal root directory:

```bash
# 1. Clear all caches
drush cr

# 2. Verify module is enabled
drush pm:list | grep custom_rest_api

# 3. If module is not enabled, enable it
drush en custom_rest_api -y

# 4. Verify REST resource is enabled
drush config:get rest.resource.customer_profile_list_resource status

# 5. Grant permissions (if needed)
drush user:role:add "authenticated" "restful get customer_profile_list_resource"
drush user:role:add "tailor" "restful get customer_profile_list_resource"

# 6. Rebuild routes
drush cr

# 7. Verify route exists
drush ev "print_r(array_keys(\Drupal::service('router.route_provider')->getRoutesByPattern('/api/tailor/customer_profiles')))"
```

## Step-by-Step Fix

### Step 1: Verify Module is Enabled

```bash
drush pm:list | grep custom_rest_api
```

Should show:
```
custom_rest_api                    Enabled    8.x-1.0
```

If not enabled:
```bash
drush en custom_rest_api -y
drush cr
```

### Step 2: Verify REST Resource Configuration

```bash
drush config:get rest.resource.customer_profile_list_resource
```

Should show:
```yaml
status: true
methods:
  - GET
```

If status is `false`, enable it:
```bash
drush config:set rest.resource.customer_profile_list_resource status 1
drush cr
```

### Step 3: Verify Permissions

Check if your user role has permission:

```bash
# For authenticated users
drush user:role:permissions authenticated | grep customer_profile_list_resource

# Should show:
# restful get customer_profile_list_resource
```

If not found, grant it:
```bash
drush user:role:add "authenticated" "restful get customer_profile_list_resource"
drush user:role:add "tailor" "restful get customer_profile_list_resource"
drush cr
```

### Step 4: Rebuild Routes

```bash
drush cr
```

### Step 5: Test Route Registration

```bash
drush ev "print_r(array_keys(\Drupal::service('router.route_provider')->getRoutesByPattern('/api/tailor/customer_profiles')))"
```

Should show the route name.

### Step 6: Check Drupal Logs

```bash
drush watchdog:show --filter=rest --count=10
```

Look for any errors related to REST or the customer_profile_list_resource.

## Alternative: Enable via Drupal UI

1. Go to: `/admin/config/services/rest`
2. Find: **"Customer Profile List Resource"**
3. Click **"Edit"**
4. Make sure:
   - ✅ Status: **Enabled**
   - ✅ Methods: **GET** is checked
   - ✅ Formats: **json** is selected
   - ✅ Authentication: **jwt_auth** is selected
5. Click **"Save"**
6. Go to: `/admin/config/development/performance`
7. Click **"Clear all caches"**

## Check Permissions via UI

1. Go to: `/admin/people/permissions`
2. Search for: `customer_profile_list_resource`
3. Make sure **"authenticated"** and **"tailor"** roles have:
   - ✅ **"View customer profile list resource REST resource"** checked
4. Click **"Save permissions"**

## Verify REST Module is Enabled

```bash
drush pm:list | grep rest
```

Should show:
```
rest                              Enabled    8.x-1.0
```

If not:
```bash
drush en rest -y
drush cr
```

## Test the Endpoint

After running all fixes, test in Postman:

```
GET https://ca5e257a70db.ngrok-free.app/api/tailor/customer_profiles

Headers:
Authorization: Bearer YOUR_JWT_TOKEN
Content-Type: application/json
```

Expected responses:
- **200 OK** with `[]` (empty array) = Success! No profiles yet.
- **200 OK** with data = Success! Profiles found.
- **401 Unauthorized** = Token issue
- **403 Forbidden** = Permission issue
- **404 Not Found** = Route not registered (rerun `drush cr`)

## Common Issues

### Issue: 404 Not Found
**Cause:** Route not registered or cache issue

**Fix:**
```bash
drush cr
# If still 404, check if REST module is enabled
drush pm:list | grep rest
drush en rest -y
drush cr
```

### Issue: 403 Forbidden
**Cause:** Missing permissions

**Fix:**
```bash
drush user:role:add "authenticated" "restful get customer_profile_list_resource"
drush user:role:add "tailor" "restful get customer_profile_list_resource"
drush cr
```

### Issue: 401 Unauthorized
**Cause:** Invalid JWT token or missing Authorization header

**Fix:**
1. Get fresh token from `/api/tailor/login`
2. Make sure Authorization header is: `Bearer YOUR_TOKEN` (with space)
3. Check token hasn't expired

### Issue: 500 Internal Server Error
**Cause:** Server-side error

**Fix:**
1. Check logs: `/admin/reports/dblog`
2. Check if `customer_profile` content type exists
3. Verify PHP errors: Check Apache/Nginx error logs

## Verify Everything is Working

Run this complete check:

```bash
#!/bin/bash
echo "=== Checking Custom REST API Module ==="
echo "1. Module Status:"
drush pm:list | grep custom_rest_api

echo -e "\n2. REST Module Status:"
drush pm:list | grep rest

echo -e "\n3. REST Resource Status:"
drush config:get rest.resource.customer_profile_list_resource status

echo -e "\n4. Permissions:"
drush user:role:permissions authenticated | grep customer_profile_list_resource

echo -e "\n5. Clearing cache..."
drush cr

echo -e "\n=== Done! Test the endpoint now. ==="
```

Save this as `check_api.sh` and run: `bash check_api.sh`

