# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Bitrix24 integration system for Автопороги company that manages deal processing with automatic bonus calculations based on product bonus codes. The system consists of:

1. **Main webhook handler** (`index.php`) - Processes deals from Bitrix24, calculates bonuses and turnovers by categories A and B
2. **Bonus code editor app** (`app_bonus_edit/`) - Bitrix24 embedded application for managing bonus codes with modular MVC architecture
3. **Deal refresh scripts** - Batch processing scripts for updating multiple deals

## Architecture

### Main System (index.php)

**Single-file webhook handler** that:
- Receives deal_id from Bitrix24 business process via webhook
- Uses CRest SDK for Bitrix24 API communication
- Connects to MySQL database via `../db_connect.php` (located one level up)
- Calculates turnovers and bonuses by product categories (A/B)
- Uses file-based caching (`userfields_cache.json`, `bonus_codes_cache.json`, TTL: 1 hour)

**Key tables:**
- `bonus_codes` - Maps bonus code (e.g., "A1", "B5") to bonus amount and product name
- `all_deals` - Stores calculated metrics: `turnover_category_a/b`, `bonus_category_a/b`, `quantity`

**bonus_codes table structure:**
```sql
CREATE TABLE bonus_codes (
    code VARCHAR(10) PRIMARY KEY,
    bonus_amount DECIMAL(10,2) NOT NULL,
    Name VARCHAR(100),  -- Product/component name (e.g., "Porог", "Arка")
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
```

**Bonus code normalization:**
- Cyrillic А,В → Latin A,B (prevents user input errors)
- Empty codes → product skipped
- Code extraction from product's `property221` field

**Test URL:** `https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827`

### Bonus Code Editor App (app_bonus_edit/)

**MVC-based Bitrix24 embedded application** (v4.0) with modular architecture:

```
src/
├── autoload.php              # PSR-4 autoloader
├── Controllers/
│   └── BonusController.php  # HTTP handlers (list, update, import_csv)
├── Services/
│   ├── BonusService.php     # Business logic, validation
│   └── CsvImportService.php # CSV parsing, encoding detection
├── Repository/
│   └── BonusRepository.php  # Database queries, natural sort
├── Core/
│   ├── Config.php           # Singleton config, db_connect.php search
│   ├── Database.php         # Singleton MySQL connection
│   ├── Logger.php           # File logging to logs/bonus_changes.log
│   └── Cache.php            # Cache management
└── Utils/
    ├── AccessControl.php    # Whitelist checking
    └── Response.php         # JSON responses

public/
├── js/app.js                # Vanilla JS (no frameworks)
└── css/styles.css           # Bitrix24-style design
```

**Key features:**
- OAuth via CRest SDK (Client ID: `local.692466e0264de0.30890025`)
- 3-column table layout with natural sorting (A1, A2, ..., A10, A11)
- Displays product/component names from `Name` field (read-only, non-editable)
- Auto-save on input change
- CSV import with encoding detection (UTF-8, Windows-1251)
- Search by code AND product name
- All changes logged to `logs/bonus_changes.log`
- Cache invalidation on updates

**API endpoints:**
- `GET api.php?action=list&member_id=XXX` - Get all bonus codes
- `POST api.php?action=update&member_id=XXX` - Update bonuses
- `POST api.php?action=import_csv&member_id=XXX` - Import CSV

**Database connection:**
- Searches for `db_connect.php` in two locations:
  1. `../../db_connect.php` (2 levels up)
  2. `../../../db_connect.php` (3 levels up)

**Production URLs:**
- Handler: `https://9dk.ru/webhooks/avtoporogi/all_deals/app_bonus_edit/index.php`
- Install: `https://9dk.ru/webhooks/avtoporogi/all_deals/app_bonus_edit/install.php`

## Common Tasks

### Manage Bonus Codes

Use the web application to add/edit/import bonus codes:

```bash
# Open in browser or Bitrix24
https://9dk.ru/webhooks/avtoporogi/all_deals/app_bonus_edit/index.php
```

**CSV import format** (through app UI):
```csv
Код;Наименование;Бонус
A1;Порог;35
B5;Усилитель 200;50
```

Features:
- Add codes manually through table
- Bulk import from CSV
- Edit bonus amounts with auto-save
- View product names
- Search by code or name

### Test Main Webhook

```bash
# Replace 101827 with actual deal_id
curl "https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=101827"
```

### Refresh Multiple Deals (Batch Processing)

```bash
# Process all deals (recommended method)
php refresh_deals_NEW.php

# Test on limited number of deals
php refresh_deals_NEW.php limit 100

# Reset progress and start over
php refresh_deals_NEW.php reset
```

**Features:**
- Automatic progress tracking (`refresh_progress_new.json`)
- Can interrupt and resume
- Parallel HTTP processing
- Detailed logging

**Alternative (faster for huge datasets):**
```bash
php fast_update_deals.php
```
- 3-25x faster than serial processing
- Parallel processing of deals

### Test Bonus Editor API

```bash
curl "https://9dk.ru/webhooks/avtoporogi/all_deals/app_bonus_edit/api.php?action=list&member_id=test"
```

## Development Guidelines

### Adding New Endpoint to Bonus Editor

1. Add method to `src/Controllers/BonusController.php`:
```php
public function myAction() {
    $userId = $this->checkAccess();
    $data = $this->bonusService->getMyData();
    Response::success($data);
}
```

2. Add case in `handleRequest()`:
```php
case 'my_action':
    $this->myAction();
    break;
```

3. Access via: `api.php?action=my_action&member_id=XXX`

### Modifying Database Logic

Edit `src/Repository/BonusRepository.php` - all SQL queries isolated here. Always use prepared statements.

### Changing Business Logic

Edit `src/Services/BonusService.php` or `CsvImportService.php`. Services handle:
- Validation of bonus amounts and codes
- Logging of all changes to `logs/bonus_changes.log`
- Cache invalidation when codes are updated
- CSV parsing with encoding detection

### UI Changes

- **HTML:** `app_bonus_edit/index.php`
- **CSS:** `app_bonus_edit/public/css/styles.css` (Bitrix24 color palette: #F5F7FA, #2FC6F6, #55D0A4)
- **JS:** `app_bonus_edit/public/js/app.js` (vanilla JS, no frameworks)

### Design System (Bitrix24 Style)

**Colors:**
- Background: `#F5F7FA`
- Accent: `#2FC6F6` (Bitrix24 blue)
- Success: `#55D0A4`
- Borders: `#E0E0E0` (1px thin)
- Text: `#525C69`, `#6F7580`

**Principles:**
- Flat design with minimal shadows (`0 1px 2px rgba(0,0,0,0.05)`)
- Border radius: 4-6px
- Font size: 13-14px
- Compact spacing

## Important Notes

### Code Normalization

Always normalize bonus codes when processing:
```php
function normalizeBonusCode($code) {
    $code = str_replace(['А', 'В', 'а', 'в'], ['A', 'B', 'A', 'B'], $code);
    return strtoupper(trim($code));
}
```

### Natural Sorting

Bonus codes must use natural sort (`strnatcmp`) to get correct order: A1, A2, ..., A10, A11 (not A1, A10, A11, A2).

Implemented in `BonusRepository::findAll()`.

### Product Name Field

The `Name` field in `bonus_codes` table stores human-readable names (e.g., "Порог", "Усилитель 200").

**Important:**
- Field is displayed in read-only format in the app UI
- Not editable through the app (intentionally)
- To update: Edit directly in DB or re-import CSV with new names
- Examples: "Порог", "Арка", "Заглушка", "Усилитель 200", "Лонжерон" etc.

### Caching

Two cache types with 1-hour TTL:
- `userfields_cache.json` - Bitrix24 user field metadata
- `bonus_codes_cache.json` - Bonus codes from database
- Cache invalidated automatically on bonus updates

### Singleton Pattern

`Config` and `Database` classes use Singleton pattern - always access via `::getInstance()`.

### OAuth Tokens

The `settings.json` file is auto-generated during OAuth installation and contains access tokens. Never commit this file.

### Database Connection

The system expects `db_connect.php` to exist outside the project directory (one or more levels up). This file contains:
```php
$config = [
    'db' => [
        'servername' => '...',
        'username' => '...',
        'password' => '...',
        'dbname' => '...'
    ]
];
```

### Error Handling

- Main webhook: Stops on critical errors, logs details
- Bonus editor: Returns JSON errors, logs to `logs/bonus_changes.log`
- Empty bonus codes: Products skipped silently
- Invalid codes: Logged but processing continues

## Documentation

- **Main system:** `README.md` - Complete system guide and quick start
- **Bonus editor:** `app_bonus_edit/README.md` - Full web app documentation and features
- **Architecture:** `app_bonus_edit/_docs/REFACTORING.md` - MVC architecture details
- **Quick guides:** `_docs/` folder - Optimization guides and use cases
- **Developer guide:** This file (CLAUDE.md)

## File Locations

**NOT in repository but required:**
- `../db_connect.php` - Database credentials (one level up from /dev)
- `settings.json` - OAuth tokens (auto-created in app_bonus_edit/)
- Cache files in project root (auto-created)

**Logs:**
- `app_bonus_edit/logs/bonus_changes.log` - All bonus code modifications

## Testing

Always test both systems after changes:
1. **Webhook:** Test with real deal_id
2. **Bonus editor:** Test list/update/import via Bitrix24 or direct API calls
3. **Check logs** for errors
4. **Verify cache** invalidation after updates
