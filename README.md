# MDash

MDash is a PHP + MySQL web application for building AI-generated dashboards from uploaded data sources.

The project provides:
- authentication and role-aware access (`user`, `admin`);
- upload and metadata management for data files;
- template and makeup profile management;
- dashboard definition, prompt composition, and AI generation (Gemini/OpenRouter);
- AI profile management with owner-only connection tests and diagnostics;
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
  - one default AI profile (`id_ai_db`)

### 6. Prompt composition and AI generation
- `dashboard_prompt.php` composes the final prompt and calls the selected provider (`gemini` or `openrouter`).
- AI profile selection is per generation run (dropdown), filtered to active/accessible profiles.
- API key is loaded from the selected AI profile in DB (no generation fallback to `.env`).
- Final prompt sections are added only when non-empty.
- Empty prompt fields are not included in the final prompt.
- Duplicate sections are prevented.
- Provider-specific request format is handled automatically:
  - Gemini: `:generateContent` endpoint + Gemini payload
  - OpenRouter: `/chat/completions` endpoint + chat payload
- Generation includes a fullscreen loading overlay with JS canvas animation and rotating phrases read from `options` table entries.
- Generation log includes practical error hints (invalid endpoint/API key/token quota/model availability).
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
- `ai_db.php`: AI profiles listing, visibility, delete, and connection test
- `insert_ai.php`: create AI profile
- `edit_ai.php`: update AI profile
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
id_ai_db INT NOT NULL DEFAULT 0,
data_filter_prompt TEXT NOT NULL,
data_manipulation_prompt TEXT NOT NULL,
dashboard_prompt_1 TEXT NOT NULL,
dashboard_prompt_2 TEXT NOT NULL,
id_template INT NOT NULL DEFAULT 0
```

### `ai_db`

```sql
id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
title VARCHAR(255) NOT NULL,
provider VARCHAR(100) NOT NULL,
model VARCHAR(100) NOT NULL,
api_key TEXT NOT NULL,
web_end_point TEXT NOT NULL,
date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
id_owner INT NOT NULL,
is_public TINYINT(1) NOT NULL DEFAULT 0,
is_hidden TINYINT(1) NOT NULL DEFAULT 0,
last_test_status VARCHAR(20) NOT NULL DEFAULT '',
last_test_message TEXT NOT NULL,
last_test_at DATETIME NULL DEFAULT NULL,
INDEX idx_ai_db_owner (id_owner),
INDEX idx_ai_db_visibility (is_public, is_hidden),
INDEX idx_ai_db_date (date_creation)
```

### `results`

```sql
id INT NOT NULL PRIMARY KEY,
path TEXT NOT NULL,
id_template INT NOT NULL,
id_ai_db INT NOT NULL DEFAULT 0,
ai_title VARCHAR(255) NOT NULL DEFAULT '',
ai_provider VARCHAR(100) NOT NULL DEFAULT '',
ai_model VARCHAR(100) NOT NULL DEFAULT '',
final_prompt TEXT NOT NULL,
thumbnail_path TEXT NOT NULL,
HTML LONGTEXT NOT NULL,
id_owner INT NOT NULL,
is_public INT NOT NULL DEFAULT 0,
is_hidden INT NOT NULL DEFAULT 0
```

### `options`

Generic key/value options table used for app-level settings and runtime text content.

```sql
option_key VARCHAR(191) NOT NULL PRIMARY KEY,
option_value LONGTEXT NOT NULL,
value_type VARCHAR(20) NOT NULL DEFAULT 'text',
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

For loading overlay phrases, one phrase per row is stored as:

- `dashboard.loading_phrases.en.001`
- `dashboard.loading_phrases.en.002`
- ...
- `dashboard.loading_phrases.en.120`

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
- data source section includes file metadata and URL when available;
- selected AI profile (provider/model/endpoint) is used for each generation run;
- loading overlay phrases are fetched from `options` table (`dashboard.loading_phrases.en.*`).

## Environment Variables

Expected variables (with current defaults where present):
- `DB_HOST` (default `localhost`)
- `DB_NAME` (default `mdash`)
- `DB_USER` (default `root`)
- `DB_PASS` (default `zxca$dqwe123`)

Note: generation now uses API keys stored in `ai_db.api_key` per profile.

## Notes

- Some schema compatibility logic supports old installations (for example `is_active`/`is_enabled` coexistence on `users`).
- IDs for `results.id` and `makeup.id_makeup` currently use `MAX(id)+1` strategy.
- Ownership checks are enforced for mutable actions (edit/delete/hide/upload-thumbnail).
- Supported AI providers at runtime are currently `gemini` and `openrouter`.

