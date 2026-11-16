# Deploy: Overall Report + red theme (Order Desk admin)

`overall_report_deploy.zip` already has the right folders:

| File in the zip | Goes to |
|---|---|
| `overall_report.php` | site root (next to `index.php`) |
| `includes/report_lib.php`, `includes/header.php` | `includes/` |
| `assets/theme-red.css` | `assets/` |

1. Hostinger > Websites > admin site > File Manager, open the folder that contains `index.php`.
2. Download `includes/header.php` as a backup.
3. Upload the zip to that folder and Extract it. Overwrite when asked (only `includes/header.php` is replaced).
4. Log in as a manager: the new red look is on every page, and "Overall Report" is under Home.

Rollback: restore the backed-up `header.php`, or just delete `assets/theme-red.css` to drop the red look.
No database changes. The Overall Report only reads data.
