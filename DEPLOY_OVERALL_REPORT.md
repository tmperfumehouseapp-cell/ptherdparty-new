# Deploy: Overall Report (admin panel)

Files: `overall_report.php` (site root), `report_lib.php` and `header.php` (both go in `includes/`).
The ready-made `overall_report_deploy.zip` already has this layout.

1. Hostinger > Websites > admin site > File Manager, open the folder that contains `index.php`.
2. Open `includes/`, download `header.php` as a backup.
3. Back in the main folder, upload `overall_report_deploy.zip` and Extract it there. Overwrite when asked
   (only `includes/header.php` is replaced).
4. Log in as a manager: "Overall Report" appears below Home.
5. Rollback: upload the backed-up `header.php` to `includes/`.

The page only reads data. No database changes are needed.
