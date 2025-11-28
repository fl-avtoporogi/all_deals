# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Bitrix24 integration system for Автопороги company that manages deal processing with automatic bonus calculations based on product bonus codes. The system consists of:

1. **Main webhook handler** (`index.php`) - Processes deals from Bitrix24, calculates bonuses and turnovers by categories A and B, **client bonus** (v4.0)
2. **Bonus code editor app** (`app_bonus_edit/`) - Bitrix24 embedded application for managing bonus codes with modular MVC architecture (v3.1)
3. **Deal refresh scripts** - Batch processing scripts for updating multiple deals with different optimization strategies

**Current Version:** v4.0 (Client Bonus Feature)
**Last Updated:** 2025-11-28

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
- `all_deals` - Stores calculated metrics: `turnover_category_a/b`, `bonus_category_a/b`, `quantity`, **`contact_responsible_id`, `client_bonus`** (v4.0)

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

**Key Functions:**
- `getContactResponsible($contactId)` - Fetches contact and its responsible manager (v4.0)
- `normalizeBonusCode($code)` - Converts Cyrillic А,В to Latin A,B
- `getBonusCodesMap()` - Returns cached bonus codes from DB
- `calculateBonusesAndTurnovers($products, $bonusCodes)` - Main calculation logic

**Client Bonus Feature (v4.0):**
- Automatically fetches contact linked to deal via Bitrix24 API
- Gets responsible manager assigned to contact
- Calculates 5% bonus: `opportunity × 0.05`
- Saves to DB: `contact_responsible_id`, `contact_responsible_name`, `client_bonus`, `client_bonus_rate`
- SQL migration: `migration_client_bonus.sql` (creates backup before altering table)

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

Three strategies available, choose based on dataset size:

**1. refresh_deals_NEW.php** (Recommended for 10K-50K deals)
```bash
php refresh_deals_NEW.php              # All deals
php refresh_deals_NEW.php limit 100    # First 100 deals
php refresh_deals_NEW.php reset        # Reset progress
```
- Batch API requests (50 deals per request)
- Caches references (stages, users, departments)
- Progress tracking: `refresh_progress_new.json`
- Speed: ~500-1000 deals/min
- Can interrupt and resume

**2. fast_update_deals.php** (For 50K+ deals - FASTEST)
```bash
php fast_update_deals.php                    # All deals
php fast_update_deals.php --limit=1000       # First 1000
php fast_update_deals.php --from-id=100000   # Starting from ID
php fast_update_deals.php --days=30          # Last 30 days
php fast_update_deals.php --funnel=5         # Specific funnel
```
- Uses official Bitrix24 optimization (`start=-1`)
- **3-25x faster** than serial processing
- Speed: ~2500-6000 deals/min (vs 100-200 in old method)
- Minimal SELECT queries
- Progress tracking: `fast_update_progress.json`

**3. refresh_deals.php** (Legacy - NOT RECOMMENDED)
```bash
php refresh_deals.php
```
- Serial processing with individual HTTP requests
- **Deprecated** - high API load
- Speed: ~100-200 deals/min
- Only use if other methods fail

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

## File Structure Overview

**Core Files:**
- `index.php` (770 lines) - Main webhook handler
- `refresh_deals_NEW.php` (~1000 lines) - Batch processing with progress
- `fast_update_deals.php` (~900 lines) - Optimized mass update
- `refresh_deals.php` (~400 lines) - Legacy serial processing

**Bonus Code Editor (app_bonus_edit/):**
- `api.php` (32 lines) - REST API gateway
- `src/Controllers/BonusController.php` - HTTP request handling
- `src/Services/BonusService.php` - Business logic & validation
- `src/Services/CsvImportService.php` - CSV parsing & encoding detection
- `src/Repository/BonusRepository.php` - Database queries (all SQL isolated here)
- `src/Core/Config.php` - Singleton configuration
- `src/Core/Database.php` - Singleton MySQL connection
- `src/Core/Logger.php` - Logging to `logs/bonus_changes.log`
- `src/Core/Cache.php` - Cache management
- `public/js/app.js` (~400 lines) - Frontend logic (vanilla JS)
- `public/css/styles.css` - Bitrix24-style design

**Database:**
- `migration_client_bonus.sql` - v4.0 migration script with backup

**Documentation:**
- `README.md` - Main user guide
- `TESTING_CLIENT_BONUS.md` - v4.0 feature testing guide
- `CLAUDE.md` - This file (for Claude Code AI assistant)
- `app_bonus_edit/README.md` - Web app documentation
- `app_bonus_edit/_docs/REFACTORING.md` - MVC architecture details
- `_docs/FAST_UPDATE_README.md` - Complete optimization guide
- `_docs/FILES_OVERVIEW.md` - File navigation

## File Locations

**NOT in repository but required:**
- `../db_connect.php` - Database credentials (one level up from /dev)
- `settings.json` - OAuth tokens (auto-created in app_bonus_edit/)
- Cache files in project root (auto-created)

**Logs:**
- `app_bonus_edit/logs/bonus_changes.log` - All bonus code modifications

## Key Architectural Patterns

### Singleton Pattern
`Config` and `Database` classes use singleton - access via `::getInstance()`:
```php
$config = Config::getInstance();
$db = Database::getInstance();
```

### Repository Pattern
All database operations isolated in `BonusRepository`:
```php
$repository = new BonusRepository($db);
$bonuses = $repository->findAll();
$repository->update($code, $bonus);
```

### Service Layer
Business logic in services, not controllers:
```php
$service = new BonusService($repository);
$service->validateBonus($code, $amount);
$service->importFromCsv($csvData);
```

### Natural Sorting
Bonus codes use `strnatcmp` for correct order: A1, A2, ..., A10, A11
```php
usort($bonuses, fn($a, $b) => strnatcmp($a['code'], $b['code']));
```

## Testing

### Webhook Testing
```bash
# Test webhook with real deal_id
curl "https://9dk.ru/webhooks/avtoporogi/all_deals/index.php?deal_id=55"

# Expected output: HTML with $dealData array dump and success message
```

### Database Testing
```sql
-- Check v4.0 columns exist
DESCRIBE all_deals;

-- Verify client bonus calculation
SELECT deal_id, opportunity, client_bonus,
       ROUND(opportunity * 0.05, 2) as expected_bonus
FROM all_deals WHERE client_bonus > 0
LIMIT 5;
```

### API Testing
```bash
# List bonus codes
curl "https://9dk.ru/webhooks/avtoporogi/all_deals/app_bonus_edit/api.php?action=list&member_id=test"

# Should return JSON with success: true and array of bonuses
```

### Full Testing Workflow (v4.0)
1. Run SQL migration: `migration_client_bonus.sql`
2. Test webhook with deal containing contact
3. Check DB: `SELECT contact_responsible_name, client_bonus FROM all_deals WHERE deal_id=55;`
4. Verify calculation: `client_bonus = opportunity × 0.05`
5. Test without contact: data should be NULL, bonus should be calculated
6. Check logs: `app_bonus_edit/logs/bonus_changes.log`

## Debugging Tips

**Issue:** Contact data not fetching
- Check Bitrix24 API access (tokens in `app_bonus_edit/settings.json`)
- Use `restSafe()` - won't crash if API fails
- Check `CONTACT_ID` field exists in deal

**Issue:** Wrong bonus calculation
- Verify `client_bonus_rate = 0.05` (5%)
- Check `opportunity` field is numeric
- Ensure rounding to 2 decimals

**Issue:** Cache stale data
- Clear manually: delete `*_cache.json` files
- TTL is 1 hour - cache auto-refreshes after
- Cache invalidates on API update actions

**Issue:** Database connection failing
- Check `../db_connect.php` exists one level up from /dev
- Verify credentials in config
- Ensure user has SELECT, INSERT, UPDATE permissions
