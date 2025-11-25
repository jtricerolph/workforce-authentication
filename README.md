# Workforce Authentication Plugin

A WordPress plugin that integrates with Workforce (Tanda) HR system for authentication and permissions management.

## Features

- **OAuth Token Setup** - Get bearer token using email/password with customizable scopes
- **Location Selection** - Choose which Workforce locations to sync
- **Department Sync** - Automatically sync departments and staff assignments
- **Database Storage** - Store departments and user-department relationships

## Setup Steps

### 1. Install & Activate

1. Upload plugin to `/wp-content/plugins/workforce-authentication/`
2. Activate through WordPress admin
3. Go to **Workforce Auth** in admin menu

### 2. Get API Token

1. Enter your Workforce email and password
2. Select required scopes (default: me, user, department)
3. Click "Get Access Token"

### 3. Select Locations

1. Plugin will load all your Workforce locations
2. Select which locations to include
3. Click "Save Locations & Continue"

### 4. Sync Departments

1. Click "Sync Departments Now"
2. Plugin will sync all departments and staff from selected locations

## Database Tables

### `wp_workforce_departments`
Stores department information:
- `workforce_id` - ID from Workforce API
- `location_id` - Location this department belongs to
- `name` - Department name
- `colour` - Display color
- `export_name` - Export name if set
- `updated_at` - Last update timestamp from API
- `record_id` - Record ID from API

### `wp_workforce_department_users`
Stores user-department relationships:
- `department_id` - Reference to department
- `workforce_user_id` - Workforce user ID
- `is_manager` - Whether user is manager (1) or staff (0)

## API Endpoints Used

- `POST /api/oauth/token` - Get bearer token
- `GET /api/v2/locations` - Fetch locations
- `GET /api/v2/departments` - Fetch departments with staff
- `GET /api/v2/users/me` - Test connection

## Available Scopes

- `me` - Access current user information
- `user` - Manage employee personal information
- `department` - Manage location and department information
- `roster` - Manage roster and schedule information
- `timesheet` - Manage timesheet and shift information
- `cost` - Access wage and cost information
- `leave` - Manage leave requests and balances
- And more...

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Workforce (Tanda) account with API access

## Next Steps

This plugin provides the foundation for:
- User authentication using Workforce credentials
- Permission management based on departments
- PWA module access control
- Integration with other hotel management plugins

## License

GPL-2.0+

## Author

JTR
