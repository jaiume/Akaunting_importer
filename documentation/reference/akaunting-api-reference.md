# Akaunting REST API Reference

This document provides a comprehensive reference for the Akaunting REST API, enabling developers to integrate with the open-source accounting software.

**Repository**: [github.com/akaunting/akaunting](https://github.com/akaunting/akaunting)

---

## Table of Contents

1. [Overview](#overview)
2. [Authentication](#authentication)
3. [Base URL](#base-url)
4. [Response Format](#response-format)
5. [Endpoints](#endpoints)
   - [Ping](#ping)
   - [Users](#users)
   - [Companies](#companies)
   - [Dashboards](#dashboards)
   - [Items](#items)
   - [Contacts](#contacts)
   - [Documents](#documents)
   - [Accounts](#accounts)
   - [Transactions](#transactions)
   - [Transfers](#transfers)
   - [Reconciliations](#reconciliations)
   - [Categories](#categories)
   - [Currencies](#currencies)
   - [Taxes](#taxes)
   - [Settings](#settings)
   - [Reports](#reports)
   - [Translations](#translations)
6. [Data Models](#data-models)
7. [Error Handling](#error-handling)

---

## Overview

The Akaunting API is a RESTful API that allows developers to perform Create, Read, Update, and Delete (CRUD) operations on all entities within the accounting system. The API follows Laravel's resource controller conventions.

### Key Features

- RESTful design principles
- HTTP Basic Authentication
- JSON request/response format
- Pagination support
- Relationship loading (eager loading)
- Access Control List (ACL) based permissions

---

## Authentication

The API uses **HTTP Basic Authentication**. Users must have the `read-api` permission to access the API.

### Requirements

- Email address (username)
- Password
- User must have `read-api` permission (granted to `admin` role by default)

### Example Request

```bash
curl -X GET "https://your-akaunting-instance.com/api/ping" \
  -u "admin@company.com:your_password" \
  -H "Accept: application/json"
```

### Permissions

Access to specific CRUD actions is governed by Akaunting's Access Control List (ACL). Ensure the authenticated user has appropriate permissions for the requested operations.

---

## Base URL

```
https://your-akaunting-instance.com/api
```

All API endpoints are prefixed with `/api`.

---

## Response Format

### Success Response

All successful responses return JSON with the following structure:

**Single Resource:**
```json
{
  "data": {
    "id": 1,
    "name": "Example",
    "created_at": "2024-01-15T10:30:00+00:00",
    "updated_at": "2024-01-15T10:30:00+00:00"
  }
}
```

**Collection (Paginated):**
```json
{
  "data": [
    { "id": 1, "name": "Item 1" },
    { "id": 2, "name": "Item 2" }
  ],
  "links": {
    "first": "https://example.com/api/items?page=1",
    "last": "https://example.com/api/items?page=5",
    "prev": null,
    "next": "https://example.com/api/items?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "https://example.com/api/items",
    "per_page": 25,
    "to": 25,
    "total": 125
  }
}
```

### Date Format

All dates are returned in ISO 8601 format: `YYYY-MM-DDTHH:mm:ss+00:00`

---

## Endpoints

### Ping

Health check endpoint to verify API connectivity.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/ping` | Returns "pong" to verify API is accessible |

---

### Users

Manage system users.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/users` | List all users |
| `GET` | `/api/users/{id}` | Get a specific user |
| `POST` | `/api/users` | Create a new user |
| `PUT/PATCH` | `/api/users/{id}` | Update a user |
| `DELETE` | `/api/users/{id}` | Delete a user |
| `GET` | `/api/users/{id}/enable` | Enable a user |
| `GET` | `/api/users/{id}/disable` | Disable a user |

#### User Response Object

```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "locale": "en-US",
  "landing_page": "dashboard",
  "enabled": 1,
  "created_from": "api",
  "created_by": 1,
  "last_logged_in_at": "2024-01-15T10:30:00+00:00",
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "companies": { "data": [] },
  "roles": { "data": [] }
}
```

---

### Companies

Manage companies (multi-tenancy support).

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/companies` | List all companies |
| `GET` | `/api/companies/{id}` | Get a specific company |
| `POST` | `/api/companies` | Create a new company |
| `PUT/PATCH` | `/api/companies/{id}` | Update a company |
| `DELETE` | `/api/companies/{id}` | Delete a company |
| `GET` | `/api/companies/{id}/owner` | Check access to company |
| `GET` | `/api/companies/{id}/enable` | Enable a company |
| `GET` | `/api/companies/{id}/disable` | Disable a company |

#### Company Response Object

```json
{
  "id": 1,
  "name": "My Company",
  "email": "info@company.com",
  "currency": "USD",
  "domain": "company.com",
  "address": "123 Business St",
  "logo": "logos/company-logo.png",
  "enabled": 1,
  "created_from": "core",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Dashboards

Manage dashboard configurations and widgets.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/dashboards` | List all dashboards |
| `GET` | `/api/dashboards/{id}` | Get a specific dashboard |
| `POST` | `/api/dashboards` | Create a new dashboard |
| `PUT/PATCH` | `/api/dashboards/{id}` | Update a dashboard |
| `DELETE` | `/api/dashboards/{id}` | Delete a dashboard |
| `GET` | `/api/dashboards/{id}/enable` | Enable a dashboard |
| `GET` | `/api/dashboards/{id}/disable` | Disable a dashboard |

#### Dashboard Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "name": "Main Dashboard",
  "enabled": 1,
  "created_from": "core",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "widgets": { "data": [] }
}
```

---

### Items

Manage products and services inventory.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/items` | List all items |
| `GET` | `/api/items/{id}` | Get a specific item |
| `POST` | `/api/items` | Create a new item |
| `PUT/PATCH` | `/api/items/{id}` | Update an item |
| `DELETE` | `/api/items/{id}` | Delete an item |
| `GET` | `/api/items/{id}/enable` | Enable an item |
| `GET` | `/api/items/{id}/disable` | Disable an item |

#### Item Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | `product` or `service` |
| `name` | string | Yes | Item name |
| `sale_price` | decimal | Conditional | Sale price (required without purchase_price) |
| `purchase_price` | decimal | Conditional | Purchase price (required without sale_price) |
| `description` | string | No | Item description |
| `tax_ids` | array | No | Array of tax IDs |
| `category_id` | integer | No | Category ID |
| `enabled` | boolean | No | Is enabled (default: true) |
| `picture` | file | No | Item image |

#### Item Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "type": "product",
  "name": "Widget A",
  "description": "High quality widget",
  "sale_price": 99.99,
  "sale_price_formatted": "$99.99",
  "purchase_price": 49.99,
  "purchase_price_formatted": "$49.99",
  "category_id": 1,
  "picture": "items/widget-a.jpg",
  "enabled": 1,
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "taxes": { "data": [] },
  "category": { "id": 1, "name": "Products" }
}
```

---

### Contacts

Manage customers, vendors, and employees.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/contacts` | List all contacts |
| `GET` | `/api/contacts/{id}` | Get a contact by ID |
| `GET` | `/api/contacts/{email}` | Get a contact by email |
| `POST` | `/api/contacts` | Create a new contact |
| `PUT/PATCH` | `/api/contacts/{id}` | Update a contact |
| `DELETE` | `/api/contacts/{id}` | Delete a contact |
| `GET` | `/api/contacts/{id}/enable` | Enable a contact |
| `GET` | `/api/contacts/{id}/disable` | Disable a contact |

#### Contact Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | `customer`, `vendor`, or custom type |
| `name` | string | Yes | Contact name |
| `email` | string | No | Email address (unique per type) |
| `currency_code` | string | Yes | Currency code (e.g., `USD`) |
| `user_id` | integer | No | Associated user ID |
| `tax_number` | string | No | Tax identification number |
| `phone` | string | No | Phone number |
| `address` | string | No | Address |
| `website` | string | No | Website URL |
| `enabled` | boolean | No | Is enabled (default: true) |
| `logo` | file | No | Contact logo |
| `contact_persons` | array | No | Array of contact persons |

#### Contact Persons Object

```json
{
  "contact_persons": [
    {
      "name": "Jane Doe",
      "email": "jane@example.com",
      "phone": "+1-555-0123"
    }
  ]
}
```

#### Contact Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "user_id": null,
  "type": "customer",
  "name": "Acme Corporation",
  "email": "contact@acme.com",
  "tax_number": "TAX123456",
  "phone": "+1-555-0100",
  "address": "456 Customer Ave",
  "website": "https://acme.com",
  "currency_code": "USD",
  "enabled": 1,
  "reference": null,
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "contact_persons": { "data": [] }
}
```

---

### Documents

Manage invoices, bills, credit notes, and debit notes.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/documents` | List all documents |
| `GET` | `/api/documents/{id}` | Get a document by ID |
| `GET` | `/api/documents/{document_number}` | Get a document by document number |
| `POST` | `/api/documents` | Create a new document |
| `PUT/PATCH` | `/api/documents/{id}` | Update a document |
| `DELETE` | `/api/documents/{id}` | Delete a document |
| `GET` | `/api/documents/{id}/received` | Mark document as received |

#### Document Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/documents/{document}/transactions` | List document transactions |
| `POST` | `/api/documents/{document}/transactions` | Add transaction to document |
| `DELETE` | `/api/documents/{document}/transactions/{transaction}` | Remove transaction from document |

#### Document Types

- `invoice` - Sales invoices (income)
- `bill` - Purchase bills (expense)
- `credit-note` - Credit notes
- `debit-note` - Debit notes

#### Document Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Document type (`invoice`, `bill`, etc.) |
| `document_number` | string | Yes | Unique document number |
| `status` | string | Yes | Status: `draft`, `sent`, `received`, `viewed`, `partial`, `paid`, `cancelled` |
| `issued_at` | datetime | Yes | Issue date (`YYYY-MM-DD HH:mm:ss`) |
| `due_at` | datetime | Yes | Due date (`YYYY-MM-DD HH:mm:ss`) |
| `amount` | decimal | Yes | Total amount |
| `contact_id` | integer | Yes | Contact ID |
| `contact_name` | string | Yes | Contact name |
| `category_id` | integer | Yes | Category ID |
| `currency_code` | string | Yes | Currency code |
| `currency_rate` | decimal | Yes | Currency exchange rate |
| `items` | array | Yes | Array of line items |
| `notes` | string | No | Document notes |
| `order_number` | string | No | Related order number |
| `attachment` | file | No | Attachment file |

#### Document Items Array

```json
{
  "items": [
    {
      "name": "Product Name",
      "quantity": 2,
      "price": 99.99,
      "description": "Item description",
      "discount": 10,
      "tax_ids": [1, 2]
    }
  ]
}
```

#### Document Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "type": "invoice",
  "document_number": "INV-00001",
  "order_number": "ORD-001",
  "status": "sent",
  "issued_at": "2024-01-15T00:00:00+00:00",
  "due_at": "2024-02-15T00:00:00+00:00",
  "amount": 219.98,
  "amount_formatted": "$219.98",
  "category_id": 1,
  "currency_code": "USD",
  "currency_rate": 1,
  "contact_id": 1,
  "contact_name": "Acme Corporation",
  "contact_email": "contact@acme.com",
  "contact_tax_number": "TAX123456",
  "contact_phone": "+1-555-0100",
  "contact_address": "456 Customer Ave",
  "contact_city": "New York",
  "contact_zip_code": "10001",
  "contact_state": "NY",
  "contact_country": "US",
  "notes": "Thank you for your business!",
  "attachment": null,
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "category": {},
  "currency": {},
  "contact": {},
  "histories": { "data": [] },
  "items": { "data": [] },
  "item_taxes": { "data": [] },
  "totals": { "data": [] },
  "transactions": { "data": [] }
}
```

---

### Accounts

Manage bank accounts and financial accounts.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/accounts` | List all accounts |
| `GET` | `/api/accounts/{id}` | Get an account by ID |
| `GET` | `/api/accounts/{number}` | Get an account by account number |
| `POST` | `/api/accounts` | Create a new account |
| `PUT/PATCH` | `/api/accounts/{id}` | Update an account |
| `DELETE` | `/api/accounts/{id}` | Delete an account |
| `GET` | `/api/accounts/{id}/enable` | Enable an account |
| `GET` | `/api/accounts/{id}/disable` | Disable an account |

#### Account Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Account type: `bank`, `credit_card`, etc. |
| `name` | string | Yes | Account name |
| `number` | string | Yes | Account number |
| `currency_code` | string | Yes | Currency code |
| `opening_balance` | decimal | Yes | Opening balance amount |
| `bank_name` | string | No | Bank name |
| `bank_phone` | string | No | Bank phone number |
| `bank_address` | string | No | Bank address |
| `enabled` | boolean | No | Is enabled (default: true) |

#### Account Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "type": "bank",
  "name": "Business Checking",
  "number": "1234567890",
  "currency_code": "USD",
  "opening_balance": 10000.00,
  "opening_balance_formatted": "$10,000.00",
  "current_balance": 15250.75,
  "current_balance_formatted": "$15,250.75",
  "bank_name": "First National Bank",
  "bank_phone": "+1-555-0200",
  "bank_address": "789 Banking St",
  "enabled": 1,
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Transactions

Manage income and expense transactions.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/transactions` | List all transactions |
| `GET` | `/api/transactions/{id}` | Get a specific transaction |
| `POST` | `/api/transactions` | Create a new transaction |
| `PUT/PATCH` | `/api/transactions/{id}` | Update a transaction |
| `DELETE` | `/api/transactions/{id}` | Delete a transaction |

> **Note**: Transactions linked to documents (`document_id`) cannot be created, updated, or deleted via this endpoint. Use the Documents endpoint instead.

#### Transaction Types

- `income` - Money received
- `expense` - Money paid out

#### Transaction Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | `income` or `expense` |
| `number` | string | Yes | Unique transaction number |
| `account_id` | integer | Yes | Bank account ID |
| `paid_at` | datetime | Yes | Payment date (`YYYY-MM-DD HH:mm:ss`) |
| `amount` | decimal | Yes | Transaction amount |
| `currency_code` | string | Yes | Currency code |
| `currency_rate` | decimal | Yes | Currency exchange rate |
| `category_id` | integer | Yes | Category ID |
| `payment_method` | string | Yes | Payment method (e.g., `cash`, `bank_transfer`) |
| `contact_id` | integer | No | Contact ID |
| `description` | string | No | Transaction description |
| `reference` | string | No | External reference |
| `attachment` | file | No | Attachment file |

#### Recurring Transaction Fields

For recurring transactions, include these additional fields:

| Field | Type | Description |
|-------|------|-------------|
| `recurring_frequency` | string | `daily`, `weekly`, `monthly`, `yearly`, `custom` |
| `recurring_interval` | integer | Interval for custom frequency |
| `recurring_custom_frequency` | string | For custom: `daily`, `weekly`, `monthly`, `yearly` |
| `recurring_started_at` | datetime | Recurrence start date |
| `recurring_limit` | string | `date`, `count`, or empty for unlimited |
| `recurring_limit_date` | datetime | End date (if limit is `date`) |
| `recurring_limit_count` | integer | Count (if limit is `count`) |

#### Transaction Response Object

```json
{
  "id": 1,
  "number": "TXN-00001",
  "company_id": 1,
  "type": "income",
  "account_id": 1,
  "paid_at": "2024-01-15T00:00:00+00:00",
  "amount": 500.00,
  "amount_formatted": "$500.00",
  "currency_code": "USD",
  "currency_rate": 1,
  "document_id": null,
  "contact_id": 1,
  "description": "Payment received",
  "category_id": 1,
  "payment_method": "bank_transfer",
  "reference": "REF-12345",
  "parent_id": null,
  "split_id": null,
  "attachment": null,
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00",
  "account": {},
  "category": {},
  "currency": {},
  "contact": {},
  "taxes": { "data": [] }
}
```

---

### Transfers

Manage money transfers between accounts.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/transfers` | List all transfers |
| `GET` | `/api/transfers/{id}` | Get a specific transfer |
| `POST` | `/api/transfers` | Create a new transfer |
| `PUT/PATCH` | `/api/transfers/{id}` | Update a transfer |
| `DELETE` | `/api/transfers/{id}` | Delete a transfer |

#### Transfer Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_account_id` | integer | Yes | Source account ID |
| `to_account_id` | integer | Yes | Destination account ID |
| `amount` | decimal | Yes | Transfer amount |
| `transferred_at` | date | Yes | Transfer date (`YYYY-MM-DD`) |
| `payment_method` | string | Yes | Payment method |
| `description` | string | No | Transfer description |
| `reference` | string | No | External reference |

#### Transfer Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "from_account": "Business Checking",
  "from_account_id": 1,
  "to_account": "Savings Account",
  "to_account_id": 2,
  "amount": 1000.00,
  "amount_formatted": "$1,000.00",
  "currency_code": "USD",
  "paid_at": "2024-01-15T00:00:00+00:00",
  "created_from": "api",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Reconciliations

Manage bank account reconciliations.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/reconciliations` | List all reconciliations |
| `GET` | `/api/reconciliations/{id}` | Get a specific reconciliation |
| `POST` | `/api/reconciliations` | Create a new reconciliation |
| `PUT/PATCH` | `/api/reconciliations/{id}` | Update a reconciliation |
| `DELETE` | `/api/reconciliations/{id}` | Delete a reconciliation |

---

### Categories

Manage transaction and item categories.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/categories` | List all categories |
| `GET` | `/api/categories/{id}` | Get a specific category |
| `POST` | `/api/categories` | Create a new category |
| `PUT/PATCH` | `/api/categories/{id}` | Update a category |
| `DELETE` | `/api/categories/{id}` | Delete a category |
| `GET` | `/api/categories/{id}/enable` | Enable a category |
| `GET` | `/api/categories/{id}/disable` | Disable a category |

#### Category Types

- `income` - Income category
- `expense` - Expense category
- `item` - Item/inventory category
- `other` - Other categories

#### Category Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "name": "Sales",
  "type": "income",
  "color": "#28a745",
  "enabled": 1,
  "parent_id": null,
  "created_from": "core",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Currencies

Manage currencies and exchange rates.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/currencies` | List all currencies |
| `GET` | `/api/currencies/{id}` | Get a specific currency |
| `POST` | `/api/currencies` | Create a new currency |
| `PUT/PATCH` | `/api/currencies/{id}` | Update a currency |
| `DELETE` | `/api/currencies/{id}` | Delete a currency |
| `GET` | `/api/currencies/{id}/enable` | Enable a currency |
| `GET` | `/api/currencies/{id}/disable` | Disable a currency |

#### Currency Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "name": "US Dollar",
  "code": "USD",
  "rate": 1.00,
  "enabled": 1,
  "precision": 2,
  "symbol": "$",
  "symbol_first": 1,
  "decimal_mark": ".",
  "thousands_separator": ",",
  "created_from": "core",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Taxes

Manage tax rates.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/taxes` | List all taxes |
| `GET` | `/api/taxes/{id}` | Get a specific tax |
| `POST` | `/api/taxes` | Create a new tax |
| `PUT/PATCH` | `/api/taxes/{id}` | Update a tax |
| `DELETE` | `/api/taxes/{id}` | Delete a tax |
| `GET` | `/api/taxes/{id}/enable` | Enable a tax |
| `GET` | `/api/taxes/{id}/disable` | Disable a tax |

#### Tax Response Object

```json
{
  "id": 1,
  "company_id": 1,
  "name": "VAT",
  "rate": 20.00,
  "enabled": 1,
  "created_from": "core",
  "created_by": 1,
  "created_at": "2024-01-15T10:30:00+00:00",
  "updated_at": "2024-01-15T10:30:00+00:00"
}
```

---

### Settings

Manage application settings.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/settings` | List all settings |
| `GET` | `/api/settings/{id}` | Get a specific setting |
| `POST` | `/api/settings` | Create a new setting |
| `PUT/PATCH` | `/api/settings/{id}` | Update a setting |
| `DELETE` | `/api/settings/{id}` | Delete a setting |

---

### Reports

Access financial reports.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/reports` | List all reports |
| `GET` | `/api/reports/{id}` | Get a specific report |
| `POST` | `/api/reports` | Create a new report |
| `PUT/PATCH` | `/api/reports/{id}` | Update a report |
| `DELETE` | `/api/reports/{id}` | Delete a report |

---

### Translations

Access and manage translations.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/translations/{locale}/all` | Get all translations for a locale |
| `GET` | `/api/translations/{locale}/{file}` | Get translations from a specific file |

---

## Data Models

### Common Response Fields

Most resources include these standard fields:

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique identifier |
| `company_id` | integer | Company this resource belongs to |
| `enabled` | boolean | Whether resource is enabled |
| `created_from` | string | Origin: `core`, `api`, `import` |
| `created_by` | integer | User ID who created the resource |
| `created_at` | datetime | Creation timestamp (ISO 8601) |
| `updated_at` | datetime | Last update timestamp (ISO 8601) |

### Amount Fields

Amount fields typically include both raw and formatted versions:

| Field | Type | Description |
|-------|------|-------------|
| `amount` | decimal | Raw numeric amount |
| `amount_formatted` | string | Formatted amount with currency symbol |

---

## Error Handling

### HTTP Status Codes

| Status | Description |
|--------|-------------|
| `200` | Success |
| `201` | Created successfully |
| `204` | No content (successful deletion) |
| `400` | Bad request (validation errors) |
| `401` | Unauthorized (authentication required) |
| `403` | Forbidden (insufficient permissions) |
| `404` | Not found |
| `422` | Unprocessable entity (validation failed) |
| `500` | Internal server error |

### Error Response Format

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required."
    ]
  }
}
```

---

## Postman Collection

For easier API testing and integration, Akaunting provides Postman collections:

**Postman Workspace**: [postman.com/akaunting/workspace/akaunting](https://postman.com/akaunting/workspace/akaunting)

---

## Additional Resources

- **Akaunting Documentation**: [akaunting.com/hc/docs](https://akaunting.com/hc/docs)
- **Developer Portal**: [akaunting.com/hc/docs/developers](https://akaunting.com/hc/docs/developers)
- **GitHub Repository**: [github.com/akaunting/akaunting](https://github.com/akaunting/akaunting)
- **Community Forum**: [akaunting.com/forum](https://akaunting.com/forum)

---

## Extending the API

Developers can extend the API by creating custom modules. See the [Module Development Guide](https://akaunting.com/hc/docs/developers/modules) for more information.

Example of adding custom API routes in a module:

```php
use Illuminate\Support\Facades\Route;

Route::api('my-module', function () {
    Route::get('resources/{resource}/enable', 'Resources@enable')->name('.resources.enable');
    Route::get('resources/{resource}/disable', 'Resources@disable')->name('.resources.disable');
    Route::apiResource('resources', 'Resources');
});
```

---

*Last updated: December 2024*
*Based on Akaunting v3.x API*


