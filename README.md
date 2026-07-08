# MDash

MDash is a PHP + MySQL web application for building AI-generated dashboards from uploaded data sources.

The project provides:
- authentication and role-aware access (`user`, `admin`);
- upload and metadata management for data files;
- template and makeup profile management;
- dashboard definition, prompt composition, and AI generation (Gemini);
- generated result persistence with preview and thumbnail management.

## What The Project Does

### 1. Authentication and session flow
- Login is handled by `index.php` + `_act.php`.
- Authenticated users are tracked in PHP session and `mdash_user` cookie.
- Protected pages redirect to login when no auth cookie/session is present.
- Logout is available from all main pages.

### 2. Data source management
- `upload.php` uploads a file and stores it under `uploads/<id>/filename.csv`.
- Upload metadata can be completed/edited in `upload.php` and `edit_upload.php`.
- `database_list.php` lists user-owned uploads and public uploads.

### 3. Templates management
- `templates.php` and `edit_template.php` manage template prompts.
- Owner/public/hidden controls are supported.
- Access to edit/delete is owner-scoped.

### 4. Makeup management
- `makeup.php` lists user-owned + public makeup profiles.
- `insert_makeup.php` and `edit_makeup.php` manage:
  - `prompt_makeup`
  - 5-color palette (`palette`) as JSON
  - visibility (`is_private`) and hidden state (`is_hidden`)
- Palette UI supports bidirectional sync:
  - color pickers update JSON automatically
  - manual JSON edits update each picker color when JSON is valid

### 5. Dashboard definition and editing
- `dashboard_builder.php` creates dashboard definitions.
- `edit_dashboard.php` updates existing definitions.
- A dashboard can reference:
  - one data source (`id_datasource`)
  - one template (`id_template`)
  - one makeup profile (`id_makeup`)

### 6. Prompt composition and AI generation
- `dashboard_prompt.php` composes the final prompt and calls Gemini.
- Final prompt sections are added only when non-empty.
- Empty prompt fields are not included in the final prompt.
- Duplicate sections are prevented.
- Generated HTML is saved to `results/<id>/dashboard.html`.
- A thumbnail is generated/saved and recorded in DB.

### 7. Generated results management
- `results.php` lists generated dashboards (owner + public visibility rules).
- Owner actions include hide/reveal, permanent delete, and custom thumbnail paste.
- Pasted clipboard screenshot is stored in `results/<id>/thumbnail.*`.

## Pages And Main Responsibilities

- `index.php`: login page
- `main.php`: home navigation hub
- `upload.php`: file upload + metadata completion
- `database_list.php`: list/manage uploaded data sources
- `edit_upload.php`: edit upload metadata
- `templates.php`: template CRUD UI
- `edit_template.php`: template update UI
- `makeup.php`: makeup listing and owner actions
- `insert_makeup.php`: create makeup profile
- `edit_makeup.php`: update makeup profile
- `dashboard_builder.php`: create dashboard definitions
- `edit_dashboard.php`: edit dashboard definitions
- `dashboards.php`: dashboard listing + preview + delete
- `dashboard_prompt.php`: final prompt preview and AI generation
- `results.php`: generated output management
- `admin.php`: administrative management console
- `config.php`: technical diagnostics page
- `_act.php`: login/logout endpoint and users schema compatibility
- `_act_db.php`: generic admin/data actions and template API actions

## Database Structure (Current Runtime Schema)

Note: tables are created/updated at runtime by page endpoints (`CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` compatibility checks).

### `users`

Runtime may include the union of these fields depending on migration path:

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(100) NOT NULL UNIQUE,
password_hash VARCHAR(255) NOT NULL,
email VARCHAR(255) NULL/NOT NULL (depending on migration path),
role VARCHAR(20) NOT NULL DEFAULT 'user',
is_admin TINYINT(1) NOT NULL DEFAULT 0,
is_enabled TINYINT(1) NOT NULL DEFAULT 1,
is_manager TINYINT(1) NOT NULL DEFAULT 0,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
first_login_at DATETIME NULL,
last_login_at DATETIME NULL,
last_login_ip VARCHAR(45) NULL,
last_login_agent TEXT NULL
```

### `uploads`

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
path VARCHAR(255) NOT NULL,
filename VARCHAR(255) NOT NULL,
description MEDIUMTEXT NOT NULL,
tags TEXT NOT NULL,
long_description MEDIUMTEXT NOT NULL,
prompt_1 MEDIUMTEXT NOT NULL,
prompt_2 MEDIUMTEXT NOT NULL,
id_owner INT NOT NULL,
is_public TINYINT(1) NOT NULL DEFAULT 0,
AI_1 MEDIUMTEXT NOT NULL,
AI_2 MEDIUMTEXT NOT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

### `templates`

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
title VARCHAR(255) NOT NULL,
prompt MEDIUMTEXT NOT NULL,
date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
id_owner INT NOT NULL,
is_hidden TINYINT(1) NOT NULL DEFAULT 0,
is_public TINYINT(1) NOT NULL DEFAULT 0,
INDEX idx_templates_owner (id_owner),
INDEX idx_templates_date (date),
INDEX idx_templates_hidden_public (is_hidden, is_public)
```

### `makeup`

```sql
id_makeup INT NOT NULL PRIMARY KEY,
date_makeup DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
id_owner INT NOT NULL,
prompt_makeup TEXT NOT NULL,
is_private INT NOT NULL DEFAULT 1,
is_hidden INT NOT NULL DEFAULT 0,
name VARCHAR(255) NOT NULL,
palette TEXT NOT NULL,
INDEX idx_makeup_owner (id_owner),
INDEX idx_makeup_private_hidden (is_private, is_hidden)
```

`palette` stores a JSON array of exactly 5 hex colors, for example:

```json
["#2563EB", "#0F766E", "#7C3AED", "#F59E0B", "#DC2626"]
```

### `dashboards`

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
title VARCHAR(255) NOT NULL,
date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
id_datasource INT DEFAULT NULL,
id_makeup INT NOT NULL DEFAULT 0,
data_filter_prompt TEXT NOT NULL,
data_manipulation_prompt TEXT NOT NULL,
dashboard_prompt_1 TEXT NOT NULL,
dashboard_prompt_2 TEXT NOT NULL,
id_template INT NOT NULL DEFAULT 0
```

### `results`

```sql
id INT NOT NULL PRIMARY KEY,
path TEXT NOT NULL,
id_template INT NOT NULL,
final_prompt TEXT NOT NULL,
thumbnail_path TEXT NOT NULL,
id_owner INT NOT NULL,
is_public INT NOT NULL DEFAULT 0,
is_hidden INT NOT NULL DEFAULT 0
```

## Data And Filesystem Layout

- Uploaded source files: `uploads/<upload_id>/<filename>.csv`
- Generated dashboards: `results/<result_id>/dashboard.html`
- Generated or pasted thumbnails: `results/<result_id>/thumbnail.svg|png|jpg|webp`

## Prompt Generation Rules

The final prompt is composed in section blocks.

Current behavior:
- section is included only when content is non-empty;
- no placeholder text is inserted for empty prompt fields;
- duplicate sections are prevented;
- makeup section includes both `prompt_makeup` and `palette` JSON;
- data source section includes file metadata and URL when available.

## Environment Variables

Expected variables (with current defaults where present):
- `DB_HOST` (default `localhost`)
- `DB_NAME` (default `mdash`)
- `DB_USER` (default `root`)
- `DB_PASS` (default `zxca$dqwe123`)
- `GEMINI_API_KEY` or `GOOGLE_API_KEY` (required for generation)

## Notes

- Some schema compatibility logic supports old installations (for example `is_active`/`is_enabled` coexistence on `users`).
- IDs for `results.id` and `makeup.id_makeup` currently use `MAX(id)+1` strategy.
- Ownership checks are enforced for mutable actions (edit/delete/hide/upload-thumbnail).

