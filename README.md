# Akaunting Importer

A PHP application for importing bank statements (PDF and CSV) into multiple Akaunting installations.

## Features

- Import PDF bank statements
- Import CSV bank statements  
- Support for multiple Akaunting installations
- Token-based authentication
- Responsive Bootstrap UI (mobile & desktop)
- Dependency injection with PHP-DI
- Twig templating

## Requirements

- PHP 8.1 or higher
- MySQL/MariaDB
- Composer
- Apache/Nginx with mod_rewrite

## Installation

1. Clone or download this repository

2. Install dependencies:
```bash
composer install
```

3. Configure the application:
   - Copy `config/config.ini` and update with your database credentials and settings
   - Update database connection settings in `config/config.ini`

4. Set up the database:
```bash
mysql -u root -p < database/schema.sql
```

5. Configure web server:
   - Point document root to `/public` directory
   - Ensure mod_rewrite is enabled
   - For Apache, `.htaccess` files are included

6. Set permissions:
```bash
chmod -R 755 public/uploads
chmod -R 755 cache
```

## Configuration

Edit `config/config.ini` to configure:
- Database connection
- Authentication settings
- Email (SMTP) settings
- Application settings

## Project Structure

```
/
├── config/          # Configuration files
│   ├── config.ini   # Main configuration
│   ├── container.php # DI container setup
│   └── routes.php   # Route definitions
├── database/        # Database schemas
├── public/          # Web root
│   ├── index.php    # Entry point
│   └── uploads/     # File uploads
├── src/App/         # Application code
│   ├── Middleware/  # Middleware classes
│   └── Services/    # Service classes
├── templates/       # Twig templates
└── vendor/          # Composer dependencies
```

## Usage

1. Access the application in your browser
2. Login with your credentials
3. Use the dashboard to import PDF or CSV files
4. Map transactions to your Akaunting installations

## License

MIT







