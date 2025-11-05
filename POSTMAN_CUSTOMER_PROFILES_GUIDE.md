# Postman Guide: Customer Profiles API

This guide shows you how to test the Customer Profiles API endpoint in Postman.

---

## Base URL
Replace `{{base_url}}` with your actual Drupal site URL:
- **Ngrok URL:** `https://ca5e257a70db.ngrok-free.app`
- **Local URL:** `http://localhost` (if testing locally)

---

## Step 1: Get JWT Token (Login)

### Request Setup

**Method:** `POST`  
**URL:** `{{base_url}}/api/tailor/login`

**Headers:**
```
Content-Type: application/json
```

**Body (raw JSON):**
```json
{
  "username": "test",
  "password": "admin"
}
```

**Or use your actual credentials:**
```json
{
  "username": "your_username",
  "password": "your_password"
}
```

### Expected Response (200 OK):
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2NhNWUyNTdhNzBkYi5uZ3Jvay1mcmVlLmFwcCIsImV4cCI6MTc...",
  "uid": "2",
  "name": "test",
  "mail": "test@example.com",
  "status": "1",
  "roles": ["authenticated", "tailor"],
  "created": "1234567890",
  "access": "1234567890",
  "login": "1234567890"
}
```

**Important:** Copy the `token` value - you'll need it for the next step!

---

## Step 2: Get Customer Profiles

### Request Setup

**Method:** `GET`  
**URL:** `{{base_url}}/api/tailor/customer_profiles`

**Headers:**
```
Content-Type: application/json
Authorization: Bearer YOUR_JWT_TOKEN_HERE
```

**Replace `YOUR_JWT_TOKEN_HERE` with the token from Step 1**

### Example Headers:
```
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2NhNWUyNTdhNzBkYi5uZ3Jvay1mcmVlLmFwcCIsImV4cCI6MTc...
```

**Body:** 
- **No body needed** for GET requests
- Leave body empty or set to "none"

### Expected Response (200 OK):

**If you have customer profiles:**
```json
[
  {
    "nid": 123,
    "title": "Customer Name",
    "status": true,
    "created": 1234567890,
    "updated": 1234567890,
    "author": {
      "uid": 2,
      "name": "test"
    },
    "field_local_app_unique_id": "unique_id_123",
    "field_phone": "+1234567890",
    "field_address": "123 Main St",
    "field_measurement": [
      {
        "field_family_members": "John Doe",
        "field_kam_lenght": "42"
      }
    ]
  }
]
```

**If you have no customer profiles:**
```json
[]
```

**Empty array `[]` means:**
- ✅ **The API is working correctly!**
- ✅ **You have successfully authenticated!**
- ℹ️ **You just don't have any customer profiles created yet for this user**

### Common Errors and Solutions:

#### ❌ Error 404: "No route found"
**Problem:** The endpoint is not registered or cache needs clearing.

**Solution:**
```bash
drush cr
```
Then try again.

#### ❌ Error 401: "Authentication required"
**Problem:** Invalid or missing JWT token.

**Solution:**
1. Make sure you copied the ENTIRE token from login response
2. Check the Authorization header has a space after "Bearer": `Bearer YOUR_TOKEN`
3. Get a fresh token by logging in again
4. Make sure token hasn't expired (tokens expire after 1 hour)

#### ❌ Error 403: "Access denied"
**Problem:** User doesn't have permission.

**Solution:**
```bash
# Grant permission to authenticated users
drush user:role:add "authenticated" "restful get customer_profile_list_resource"
# Or for specific role
drush user:role:add "tailor" "restful get customer_profile_list_resource"

# Clear cache
drush cr
```

#### ❌ Error 500: "Internal Server Error"
**Problem:** Server-side error.

**Solution:**
1. Check Drupal logs: `/admin/reports/dblog`
2. Verify module is enabled: `drush pm:list | grep custom_rest_api`
3. Clear cache: `drush cr`
4. Check if `customer_profile` content type exists

#### ❌ Getting "Method Not Allowed" or "405"
**Problem:** Wrong HTTP method or endpoint not enabled.

**Solution:**
1. Make sure you're using `GET` method (not POST)
2. Verify REST resource is enabled in Drupal:
   - Go to `/admin/config/services/rest`
   - Find "Customer Profile List Resource"
   - Make sure it's enabled
   - Check "GET" method is enabled
3. Clear cache: `drush cr`

---

## Detailed Postman Setup Instructions

### Option 1: Manual Setup (Recommended for Testing)

#### 1. Create New Request
1. Click **"New"** → **"HTTP Request"**
2. Name it: `Get Customer Profiles`

#### 2. Set Method and URL
- **Method:** Select `GET` from dropdown
- **URL:** Enter `https://ca5e257a70db.ngrok-free.app/api/tailor/customer_profiles`
  - (Replace with your actual base URL)

#### 3. Set Headers
1. Click **"Headers"** tab
2. Click **"Add Header"** or use the bulk edit option
3. Add these headers:

| Key | Value |
|-----|-------|
| `Content-Type` | `application/json` |
| `Authorization` | `Bearer YOUR_JWT_TOKEN_HERE` |

**Important:** Replace `YOUR_JWT_TOKEN_HERE` with the actual token from Step 1

#### 4. Body (Not Needed)
- Click **"Body"** tab
- Select **"none"** (GET requests don't need a body)

#### 5. Send Request
- Click **"Send"** button
- Check the response in the bottom panel

---

### Option 2: Using Postman Environment Variables (Advanced)

#### 1. Create Environment
1. Click **"Environments"** (gear icon) → **"Add"**
2. Name: `ZamaTailor API`
3. Add variables:
   - `base_url`: `https://ca5e257a70db.ngrok-free.app`
   - `jwt_token`: `(leave empty, set after login)`

#### 2. Set Active Environment
- Select `ZamaTailor API` from the environment dropdown (top right)

#### 3. Create Login Request
- **Method:** `POST`
- **URL:** `{{base_url}}/api/tailor/login`
- **Body (raw JSON):**
```json
{
  "username": "test",
  "password": "admin"
}
```
- **Test Script** (to save token automatically):
```javascript
if (pm.response.code === 200) {
    var jsonData = pm.response.json();
    pm.environment.set("jwt_token", jsonData.token);
    console.log("JWT token saved to environment");
}
```

#### 4. Create Customer Profiles Request
- **Method:** `GET`
- **URL:** `{{base_url}}/api/tailor/customer_profiles`
- **Headers:**
  - `Authorization`: `Bearer {{jwt_token}}`
  - `Content-Type`: `application/json`

---

## Troubleshooting Empty Response

### Issue: Empty Array `[]`

**This is normal if:**
- ✅ You're authenticated correctly
- ✅ The user doesn't have any customer profiles yet

**To create test data:**
1. Go to your Drupal admin panel
2. Create customer profile content for the user you logged in with
3. Make sure the customer profile is published
4. Make sure the author (uid) matches your logged-in user

### Issue: 401 Unauthorized

**Error:**
```json
{
  "message": "Authentication required. Please provide a valid JWT token in Authorization header."
}
```

**Solutions:**
- ✅ Make sure you included the `Authorization` header
- ✅ Make sure the header value starts with `Bearer ` (with a space after Bearer)
- ✅ Make sure the JWT token is valid (not expired)
- ✅ Get a fresh token by logging in again

### Issue: 403 Forbidden

**Error:**
```json
{
  "message": "You do not have permission to access this resource."
}
```

**Solutions:**
- ✅ Make sure the user has the `restful get customer_profile_list_resource` permission
- ✅ Check if the user role has the correct permissions in Drupal
- ✅ Clear Drupal cache: `drush cr`

### Issue: 500 Internal Server Error

**Error:**
```json
{
  "message": "Failed to retrieve customer profiles: [error message]"
}
```

**Solutions:**
- ✅ Check Drupal logs at `/admin/reports/dblog`
- ✅ Make sure the `customer_profile` content type exists
- ✅ Verify the module is enabled: `drush pm:list | grep custom_rest_api`
- ✅ Clear Drupal cache: `drush cr`

---

## Quick Test Checklist

- [ ] Step 1: Login successful - got JWT token
- [ ] Step 1: Token is complete (starts with `eyJ` and is very long)
- [ ] Step 2: Copied JWT token correctly (entire token, no truncation)
- [ ] Step 2: Set Authorization header with `Bearer ` prefix (space after Bearer!)
- [ ] Step 2: Set Content-Type header to `application/json`
- [ ] Step 2: Method is `GET` (not POST, not PUT)
- [ ] Step 2: URL is correct: `/api/tailor/customer_profiles` (plural, not singular)
- [ ] Step 2: No body needed (or set to "none")
- [ ] Step 2: Base URL is correct (check your ngrok/local URL)
- [ ] Step 2: Got response (even if empty array `[]` or error message)

## Step-by-Step Verification

### 1. Verify Endpoint is Enabled
Run this in Drupal or via Drush:
```bash
drush config:get rest.resource.customer_profile_list_resource
```

Should show:
```yaml
status: true
methods:
  - GET
```

### 2. Verify Permissions
Check if your user role has permission:
```bash
drush user:role:list
drush user:role:list authenticated
```

### 3. Test JWT Token is Valid
First, test the login endpoint works:
```
POST https://ca5e257a70db.ngrok-free.app/api/tailor/login
Body: {"username": "test", "password": "admin"}
```

If login works, you'll get a token. Use that token in the next step.

### 4. Test Customer Profiles Endpoint
```
GET https://ca5e257a70db.ngrok-free.app/api/tailor/customer_profiles
Headers:
  Authorization: Bearer YOUR_TOKEN_HERE
  Content-Type: application/json
```

### 5. Check Response Status
- **200 OK** = Success! (Even if array is empty `[]`)
- **401 Unauthorized** = Token issue
- **403 Forbidden** = Permission issue
- **404 Not Found** = Endpoint not registered (clear cache)
- **500 Error** = Server error (check logs)

---

## Example Complete Request

### Request 1: Login
```
POST https://ca5e257a70db.ngrok-free.app/api/tailor/login

Headers:
Content-Type: application/json

Body:
{
  "username": "test",
  "password": "admin"
}
```

### Request 2: Get Customer Profiles
```
GET https://ca5e257a70db.ngrok-free.app/api/tailor/customer_profiles

Headers:
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2NhNWUyNTdhNzBkYi5uZ3Jvay1mcmVlLmFwcCIsImV4cCI6MTc...

Body:
(none)
```

**Important Notes:**
- Make sure there's a **space** between `Bearer` and the token
- The token should be the **complete** token from login (very long string)
- URL uses **plural** `customer_profiles` (not singular `customer_profile`)
- Method must be **GET** (capital letters)
- No trailing slash in URL

---

## Security Notes

1. **JWT tokens expire** - If you get 401 errors, get a fresh token by logging in again
2. **Only returns profiles for the authenticated user** - The API filters by the JWT token's user ID
3. **Never share your JWT token** - It's like a password
4. **Test with different users** - Each user will only see their own profiles

---

## Need Help?

If you're still getting empty responses:
1. Check Drupal logs: `/admin/reports/dblog`
2. Verify the user has customer profiles created
3. Verify the profiles belong to the correct user (author uid matches JWT token uid)
4. Test with a user that you know has profiles

