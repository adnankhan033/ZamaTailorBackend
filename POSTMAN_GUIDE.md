# Postman API Testing Guide for Article Resource

## Base URL
```
{{global_url}}
```

Replace `{{global_url}}` with your actual domain (e.g., `https://localhost:63968` or your production URL).

---

## 1. GET Article (Retrieve Single Article)

**Method:** `GET`  
**URL:** `{{global_url}}/api/tailor/article/{nid}`

**Headers:**
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer YOUR_JWT_TOKEN"
}
```

**Example:**
- **Method:** `GET`
- **URL:** `{{global_url}}/api/tailor/article/4124`

**Response (200 OK):**
```json
{
  "nid": 4124,
  "title": "Article Title",
  "body": "Article body content",
  "status": true,
  "created": 1234567890,
  "updated": 1234567890,
  "author": {
    "uid": 1,
    "name": "admin"
  }
}
```

---

## 2. POST Article (Create New Article)

**Method:** `POST`  
**URL:** `{{global_url}}/api/tailor/article`

**Headers:**
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer YOUR_JWT_TOKEN"
}
```

**Body (raw JSON):**
```json
{
  "title": "My New Article Title",
  "body": "This is the article body content",
  "body_format": "basic_html",
  "status": 1
}
```

**Body Fields:**
- `title` (required): The article title
- `body` (optional): The article body content
- `body_format` (optional): Text format, defaults to `basic_html`
- `status` (optional): `1` for published, `0` for unpublished (default: `1`)

**Response (201 Created):**
```json
{
  "message": "Article created successfully",
  "nid": 4125,
  "title": "My New Article Title",
  "body": "This is the article body content",
  "status": true,
  "created": 1234567890,
  "author": {
    "uid": 1,
    "name": "admin"
  }
}
```

---

## 3. PATCH Article (Update Article)

**Method:** `PATCH`  
**URL:** `{{global_url}}/api/tailor/article/{nid}`

**Headers:**
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer YOUR_JWT_TOKEN"
}
```

**Body (raw JSON):**
```json
{
  "title": "Updated Article Title",
  "body": "Updated article body content",
  "body_format": "basic_html",
  "status": 1
}
```

**Note:** All fields in the body are optional. Only include the fields you want to update.

**Example:**
- **Method:** `PATCH`
- **URL:** `{{global_url}}/api/tailor/article/4124`
- **Body:** Only title
```json
{
  "title": "Just Update The Title"
}
```

**Response (200 OK):**
```json
{
  "message": "Article updated successfully",
  "nid": 4124,
  "title": "Updated Article Title",
  "body": "Updated article body content",
  "status": true,
  "updated": 1234567890,
  "author": {
    "uid": 1,
    "name": "admin"
  }
}
```

---

## 4. DELETE Article (Delete Article)

**Method:** `DELETE`  
**URL:** `{{global_url}}/api/tailor/article/{nid}`

**Headers:**
```json
{
  "Content-Type": "application/json",
  "Authorization": "Bearer YOUR_JWT_TOKEN"
}
```

**Example:**
- **Method:** `DELETE`
- **URL:** `{{global_url}}/api/tailor/article/4124`

**Response (200 OK):**
```json
{
  "message": "Article deleted successfully",
  "nid": 4124,
  "title": "Article Title"
}
```

---

## Getting Your JWT Token

To get your JWT token, first login using the login endpoint:

**Method:** `POST`  
**URL:** `{{global_url}}/api/tailor/login`

**Body (raw JSON):**
```json
{
  "username": "your_username",
  "password": "your_password"
}
```

**Response (200 OK):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "message": "Login successful"
}
```

Copy the `token` value and use it in the `Authorization` header as `Bearer YOUR_JWT_TOKEN`.

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Authentication required."
}
```

### 403 Forbidden
```json
{
  "message": "You do not have permission to view/update/delete this article."
}
```

### 404 Not Found
```json
{
  "message": "Article not found."
}
```

### 405 Method Not Allowed
```json
{
  "message": "No route found for \"[METHOD] [URL]\": Method Not Allowed"
}
```

### 422 Unprocessable Entity
```json
{
  "message": "Title is required."
}
```

---

## Quick Postman Setup

1. Create a new Collection called "Article API"
2. Create Environment Variables:
   - `base_url`: Your base URL (e.g., `https://localhost:63968`)
   - `jwt_token`: Your JWT token (get from login endpoint)
3. In each request, use `{{base_url}}` for the URL
4. In the Authorization tab, select "Bearer Token" and use `{{jwt_token}}`

