# COPS Phase 4c, part 2 - any device, any spreadsheet, and geolocation (API, bundle 16a)

Applies on top of bundle 16. Answers three decisions: (1) the import must adapt to many biometric devices, (2) users must not need to know about CSV, (3) location can be used when clocking in.

## 1. Import profiles: common parameters for everything, specific parameters per device
An **import profile** says how to read one kind of file. Nothing is device specific in the code: everything below is a setting a user can change in the app.

| Parameter | Meaning |
|---|---|
| layout | `punches` (one row per punch) or `daily` (one row per person and day with several time columns: In 1, Out 1, In 2...) |
| sheet | Excel sheet name (empty = first sheet) |
| header_row | row that holds the column names (1 = first; 0 = none; reports with title lines use 3, 4...) |
| identify_by | `employee_number`, `device_code` (the number the device uses) or `either` |
| employee / timestamp / date / time / direction column | a header text, an Excel letter (B) or a position (#2). Empty = the usual names are recognised (badge, employee number, user id, timestamp, date, time, direction, status, state...) |
| in / out / ignore values | what the device writes for in, out and rows to skip, e.g. `0,3,4` / `1,2,5` / `9` or `Entree` / `Sortie` / `Pause`. Defaults: common words in English and French (in, out, entry, exit, check-in, c/in, entree, sortie...). Numbers are never assumed: they mean different things on different devices |
| date_formats | optional list of PHP formats for text dates (default tries Y-m-d, d/m/Y, d-m-Y, d.m.Y and the same with times; **day first**) |
| device_timezone | when the device clock is not on the organization's timezone |
| slots (daily layout) | each time column and whether it is an in or an out |

**Shipped profiles** (read-only starting points, duplicate to adapt): Automatic; Device badge number; Numeric status 0 = in, 1 = out (ZKTeco-style); Daily report with In / Out columns.
**Device codes** map the number a device uses to an employee (many-to-one: one person may have several codes, a code belongs to one person). After an import the **unknown codes are listed** so they can be mapped at once, then the same file is imported again (safe).
**Inspect** reads a file and returns its sheets, the likely header row, the columns and a few rows: the screen uses it to build a profile by choosing columns from a list. **Preview** is a dry run: exactly what would be imported, skipped or rejected, with a sample, and nothing saved.

## 2. Files: no CSV knowledge needed
Accepted: **Excel (.xlsx, .xlsm, .xls), OpenDocument (.ods), an HTML table saved with an .xls name** (some devices do this), and text files (comma, semicolon, tab or pipe separated; UTF-8 or Windows-1252). The format is detected from the content, not the name. Excel is read with PhpSpreadsheet, already installed with Laravel Excel (no new package). Real Excel date and time cells are read correctly (dates are day numbers, times are fractions of a day), as are dates written as text. Limits: 5 MB and 20 000 rows per file; password-protected workbooks and the 1904 date system (old Mac Excel) are not supported.

## 3. Geolocation (setting `geolocation_mode`)
- **0 off** (default): no location is asked or kept.
- **1 record**: the browser's position is kept with each punch (and matched to a work site when inside one); nobody is blocked.
- **2 required**: clocking is allowed only inside an active **work site** (a circle: latitude, longitude, radius 10-5000 m); the position must be at least as precise as `geolocation_max_accuracy_meters` (default 100). With no active site "required" behaves as "record", so a missing setup never locks everyone out.
Coordinates are stored only when the mode is not off. The clock screen must tell staff that their location is requested and kept (the API returns `geolocation` mode). As you asked, no legal-review step is imposed by the product; the transparency notice stays because staff should know what is collected.
**Honest limit:** a browser location can be faked by someone determined. Geolocation raises the bar; combined with the network restriction (an allowed office IP range) it is much stronger. Imported device punches carry no location.

## Also fixed in this bundle
`PUT hr/settings` accepted only the two recruitment keys: the attendance settings (overtime minimum, pairing window, punch interval) could not be changed. It now accepts every HR setting, each checked against its own range.

## Deployment
1. `python scripts/apply-bundle-16a.py <path to cops-api>`
2. `php artisan migrate`
3. `php artisan db:seed --class=HrAttendanceSeeder` (adds the shipped profiles; idempotent)
4. `php artisan test`

## Regression checklist additions
- Excel (.xlsx and .xls), HTML-as-.xls and text files import; the same punches from two formats are not duplicated.
- Unknown device codes are listed; mapping them and re-importing works; a code cannot be given to two people.
- Preview changes nothing; shipped profiles cannot be edited or deleted, but can be duplicated.
- Daily report with title lines imports with the right header row.
- Geolocation off / record / required behave as above; required with no site does not block.
- Every HR setting can be changed within its range and refused outside it.
