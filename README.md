# LigaKita

A web-based football tournament management system for district and club tournaments.
Diploma project (DITU 3964), UTeM, Semester 1 Session 2026/2027.

Built with plain PHP 8 (PDO), MySQL/MariaDB and Bootstrap 5. Bootstrap and Bootstrap Icons
are included in `assets/vendor/`, so the system runs without an internet connection.

## Modules finished

| Module | Programs | Status |
|---|---|---|
| 1.0 User Management | lk_1001 Login / logout, lk_1002 Team manager accounts, change password | Done |
| 2.0 Competition, Category and Group Management | lk_2001 Competitions, lk_2002 Categories, lk_2003 Competition rules, lk_2004 Groups | Done |
| 3.0 to 9.0 | Venues, registration, players, fixtures, results, standings, reports | Not started (shown as "soon" in the menu) |

## Running it on your computer (XAMPP or Laragon)

1. Install [XAMPP](https://www.apachefriends.org/) (PHP 8.1 or newer) or [Laragon](https://laragon.org/).
   Start **Apache** and **MySQL**.
2. Put the project in the web folder so the path is:
   - XAMPP: `C:\xampp\htdocs\ligakita`
   - Laragon: `C:\laragon\www\ligakita`

   With Git: open a terminal in `htdocs` (or `www`) and run
   `git clone https://github.com/ahmdabqri/LigaKita.git ligakita`
3. Open phpMyAdmin (http://localhost/phpmyadmin), go to **Import** and import
   `database/ligakita.sql`. It creates the `ligakita` database with all 15 tables and the admin account.
4. Optional: import `database/sample_data.sql` too. It adds fictional sample data
   (2 competitions, 4 categories, rules for U15, 12 team managers and 12 approved U15 teams)
   so the groups page has teams to draw.
5. Open http://localhost/ligakita/

### Logins

| Role | Username | Password |
|---|---|---|
| Administrator | `admin` | `admin123` |
| Team manager (sample data only) | `benutfc_mgr` (and the other `..._mgr` accounts) | `manager123` |

These are demo passwords for local testing only. Change the admin password after the first login
(top right menu, **Change password**).

### Database settings

The defaults in `includes/config.php` match XAMPP and Laragon (host `127.0.0.1`, user `root`, no password).
If your MySQL is different, copy the array from `includes/config.php` into a new file
`includes/config.local.php` and change the values there. That file is ignored by Git.

## Folder layout

```
ligakita/
├── index.php              Public home page (tournament list)
├── login.php, logout.php  lk_1001
├── change_password.php
├── admin/                 Administrator pages
│   ├── index.php          Dashboard
│   ├── accounts.php       lk_1002 Team manager accounts
│   ├── competitions.php   Competition list
│   ├── competition_form.php  lk_2001 Create / edit competition
│   ├── categories.php     lk_2002 Categories and age limits
│   ├── rules.php          lk_2003 Competition rules
│   └── groups.php         lk_2004 Groups and group draw
├── manager/               Team manager pages
├── includes/              Config, database connection, login checks, shared layout
├── assets/                CSS, JavaScript and vendor libraries (Bootstrap)
└── database/              ligakita.sql (structure) and sample_data.sql
```

## Security notes

- Passwords are stored with `password_hash()` (bcrypt), never as plain text.
- All database queries use PDO prepared statements.
- Every form has a CSRF token; all output is escaped with `htmlspecialchars()`.
- Pages check the user's role (admin or team manager) before showing anything.
- New team manager accounts get a temporary password and must change it at first login.
