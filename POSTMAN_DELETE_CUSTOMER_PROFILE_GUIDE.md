# Postman Guide: Delete Customer Profile by Phone Number

This guide explains how to test the DELETE endpoint for customer profiles using Postman.

## Endpoint Information

- **Method**: `DELETE`
- **URL**: `{{base_url}}/api/tailor/customer_profile_delete/{field_phone}`
- **Authentication**: JWT Bearer Token (required)
- **Content-Type**: `application/json`

## Prerequisites

1. You must have a valid JWT token from the login endpoint
2. The customer profile must exist with the specified phone number
3. The customer profile must belong to the user authenticated by the JWT token (same author/uid)

## Step-by-Step Setup

### Step 1: Get Your JWT Token

First, log in to get your JWT token:

1. **Method**: `POST`
2. **URL**: `{{base_url}}/api/tailor/login`
3. **Headers**:
   ```
   Content-Type: application/json
   ```
4. **Body** (raw JSON):
   ```json
   {
     "username": "your_username",
     "password": "your_password"
   }
   ```
5. Copy the `token` from the response

### Step 2: Get a Customer Profile Phone Number

Get a list of your customer profiles to find a phone number:

1. **Method**: `GET`
2. **URL**: `{{base_url}}/api/tailor/customer_profiles`
3. **Headers**:
   ```
   Authorization: Bearer YOUR_JWT_TOKEN_HERE
   Content-Type: application/json
   ```
4. Copy a `field_phone` value from one of the profiles in the response

### Step 3: Delete Customer Profile

Now delete the customer profile by phone number:

1. **Method**: `DELETE`
2. **URL**: `{{base_url}}/api/tailor/customer_profile_delete/{field_phone}`
   - Replace `{field_phone}` with the actual phone number (e.g., `3333` or `0333`)
   - Example: `{{base_url}}/api/tailor/customer_profile_delete/3333`
3. **Headers**:
   ```
   Authorization: Bearer YOUR_JWT_TOKEN_HERE
   Content-Type: application/json
   ```
4. **Body**: None (DELETE requests typically don't have a body)

## Complete Example

### Request

```
DELETE {{base_url}}/api/tailor/customer_profile_delete/3333
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2V4YW1wbGUuY29tIiwiZXhwIjoxNzYyMzMxMzQ0LCJkcnVwYWwiOnsidWlkIjoiMiJ9fQ.example
Content-Type: application/json
```

### Success Response (200 OK)

```json
{
  "message": "Customer profile deleted successfully",
  "field_phone": "3333",
  "nid": "4212",
  "title": "hello...4",
  "field_local_app_unique_id": "2_3444"
}
```

### Error Responses

#### 400 Bad Request - Missing Phone Number

```json
{
  "message": "field_phone is required."
}
```

#### 401 Unauthorized - Missing/Invalid JWT Token

```json
{
  "message": "Authentication required. Please provide a valid JWT token."
}
```

#### 403 Forbidden - Profile Doesn't Belong to User

```json
{
  "message": "You do not have permission to delete this customer profile."
}
```

#### 404 Not Found - Profile Not Found

```json
{
  "message": "Customer profile not found with field_phone: 3333 for current user."
}
```

## Important Security Notes

1. **User Ownership**: The endpoint only deletes customer profiles that belong to the authenticated user (same uid as JWT token)
2. **Phone Number Match**: The phone number must match exactly (case-sensitive)
3. **Single Profile**: If multiple profiles exist with the same phone number for the same user, only the first one found will be deleted

## Postman Collection Setup

### Environment Variables

Create a Postman environment with:

```
base_url: https://your-drupal-site.com
jwt_token: (will be set after login)
```

### Pre-request Script (for login)

If you want to auto-login before each request:

```javascript
pm.sendRequest({
    url: pm.environment.get("base_url") + "/api/tailor/login",
    method: 'POST',
    header: {
        'Content-Type': 'application/json'
    },
    body: {
        mode: 'raw',
        raw: JSON.stringify({
            username: "your_username",
            password: "your_password"
        })
    }
}, function (err, res) {
    if (!err && res.json().token) {
        pm.environment.set("jwt_token", res.json().token);
    }
});
```

### DELETE Request Setup

1. Create a new request
2. Set method to `DELETE`
3. Set URL: `{{base_url}}/api/tailor/customer_profile_delete/3333`
4. In Headers tab, add:
   - Key: `Authorization`
   - Value: `Bearer {{jwt_token}}`
   - Key: `Content-Type`
   - Value: `application/json`

## Testing Checklist

- [ ] JWT token is valid and not expired
- [ ] Phone number exists in your customer profiles
- [ ] Customer profile belongs to the authenticated user
- [ ] URL includes the phone number as a path parameter
- [ ] Authorization header includes the Bearer token
- [ ] Content-Type header is set to application/json

## Troubleshooting

### Issue: 404 Not Found

**Possible causes:**
- Phone number doesn't exist
- Phone number belongs to a different user
- Phone number has extra spaces or formatting

**Solution:**
- Use GET `/api/tailor/customer_profiles` to verify the exact phone number format
- Ensure you're using the phone number from a profile that belongs to your user

### Issue: 403 Forbidden

**Possible causes:**
- Customer profile belongs to a different user
- JWT token is for a different user

**Solution:**
- Verify the JWT token belongs to the user who owns the customer profile
- Check the `uid` in the JWT token payload matches the profile's author

### Issue: 401 Unauthorized

**Possible causes:**
- Missing Authorization header
- Invalid JWT token format
- Expired JWT token

**Solution:**
- Re-login to get a fresh JWT token
- Ensure Authorization header format is: `Bearer YOUR_TOKEN_HERE`

## Quick Test Example

Here's a complete cURL command you can use:

```bash
curl -X DELETE \
  'https://your-drupal-site.com/api/tailor/customer_profile_delete/3333' \
  -H 'Authorization: Bearer YOUR_JWT_TOKEN_HERE' \
  -H 'Content-Type: application/json'
```

Replace:
- `https://your-drupal-site.com` with your actual Drupal base URL
- `3333` with the actual phone number
- `YOUR_JWT_TOKEN_HERE` with your actual JWT token

