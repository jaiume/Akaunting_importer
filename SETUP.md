# Setup Instructions

## Quick Start

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure the application:**
   - Edit `config/config.ini` with your database credentials
   - Update email (SMTP) settings if needed
   - Adjust authentication settings as needed

3. **Set up the database:**
   ```bash
   mysql -u root -p < database/schema.sql
   ```

4. **Configure web server:**
   - Point document root to the `public` directory
   - Ensure mod_rewrite is enabled (Apache)
   - For Nginx, configure URL rewriting

5. **Set file permissions:**
   ```bash
   chmod -R 755 public/uploads
   chmod -R 755 cache
   ```

## Project Structure

```
/
├── bootstrap.php              # Application bootstrap
├── composer.json              # Composer dependencies
├── config/                    # Configuration files
│   ├── config.ini            # Main configuration
│   ├── container.php         # DI container setup
│   └── routes.php            # Route definitions
├── database/                  # Database schemas
│   └── schema.sql            # Database schema
├── public/                    # Web root (document root)
│   ├── index.php             # Application entry point
│   ├── .htaccess             # Apache rewrite rules
│   └── uploads/              # File upload directory
├── src/App/                   # Application source code
│   ├── Middleware/           # Middleware classes
│   │   └── AuthenticationMiddleware.php
│   └── Services/             # Service classes
│       ├── AuthenticationService.php
│       ├── ConfigService.php
│       └── UtilityService.php
├── templates/                 # Twig templates
│   ├── base.html.twig        # Base template
│   ├── login.html.twig       # Login page
│   ├── dashboard.html.twig   # User dashboard
│   └── admin/                # Admin templates
│       └── dashboard.html.twig
└── vendor/                   # Composer dependencies (generated)

```

## Key Features

- **Slim Framework 4** - Modern PHP framework
- **PHP-DI** - Dependency injection container
- **Twig** - Templating engine
- **Bootstrap 5** - Responsive CSS framework (mobile & desktop)
- **Token Authentication** - Cookie-based token authentication
- **PDO** - Database abstraction

## Authentication Flow

1. User logs in with email/password
2. System generates a random token
3. Token is hashed and stored in database
4. Token is set as a cookie
5. Middleware verifies token on each request
6. Token expiry is extended on each request

## Configuration

Main configuration is in `config/config.ini`. Sections:

- `[app]` - Application settings
- `[database]` - Database connection
- `[auth]` - Authentication settings
- `[mail]` - Email/SMTP settings
- `[paths]` - Path configurations

## Routes

- `/` - Home (redirects to login or dashboard)
- `/login` - Login page (GET/POST)
- `/dashboard` - User dashboard (protected)
- `/admin` - Admin dashboard (protected, admin only)
- `/api/*` - API routes (protected)

## Next Steps

1. Create a user in the database
2. Implement login form processing
3. Add PDF/CSV import functionality
4. Configure Akaunting API integration














