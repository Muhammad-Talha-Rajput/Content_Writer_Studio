# Grands Digital Content Studio

An AI-assisted content workspace for Grands Digital. Teams manage clients, brand profiles, projects and briefs, attach references, and generate drafts through a tracked planning, research, writing and quality-check workflow.

**Status:** active development. Client management, source processing, AI generation and an initial review workflow are implemented. Final milestone verification is pending. This repository contains application source, not a production deployment.

## Features

- Sanctum cookie authentication, invitations, recovery, roles and client-scoped permissions.
- Brand profiles, project defaults and structured blog, caption and email briefs.
- Private PDF, DOCX and text uploads, and supported public website retrieval.
- Background malware scanning, text extraction, source snapshots and PDF page previews.
- Configurable OpenRouter, Gemini, Ollama and Hugging Face providers with encrypted server-side credentials.
- Durable generation jobs, step history, usage limits, bounded retries and checkpoint continuation.
- Quality checks against the brief and references. Corrections patch the existing draft; blocking findings prevent successful version creation.
- Manual edits create immutable versions. Assigned reviewers can approve or request changes, and Markdown/HTML export is limited to the current approved version.

## Stack and workflow

| Component | Technology |
| --- | --- |
| Browser app | React 19, Vite 8, Tailwind CSS 4, React Router, Axios |
| API | PHP 8.2+, Laravel 12, Eloquent |
| Authentication | Sanctum stateful sessions, CSRF protection |
| Data | MySQL/MariaDB; database sessions and queues |
| PDF extraction | Python pypdf; selectable text only, no OCR |
| PDF preview | pypdfium2/PDFium and Pillow, displayed in React |
| Workers | Separate sources and generation queue connections |

```text
Brief + brand profile + selected processed references
  -> Plan -> Research -> Write -> Quality
                           ^        |
                           +-- targeted corrections
  -> Saved draft version when blocking checks pass
```

Research uses selected references; it does not autonomously search the entire web. Missing evidence requires user input. Cloud providers receive brief and reference excerpts. Ollama sends requests to the configured local service.

## Requirements

- PHP 8.2+, Composer 2, PDO MySQL, PDO SQLite (tests), mbstring, OpenSSL, cURL, DOM/XML, fileinfo and ZIP.
- Node.js 22.12+ and npm.
- MySQL/MariaDB; the Windows provisioning helper targets local XAMPP MySQL.
- Python 3.10+ and packages in `backend/requirements-sources.txt`.
- Windows Defender or configured ClamAV for upload scanning.
- An enabled AI provider and valid model for real generation. Automated tests use provider fakes.

## Local setup (Windows PowerShell)

```powershell
git clone https://github.com/Muhammad-Talha-Rajput/Content_Writer_Studio.git
cd Content_Writer_Studio
. .\scripts\use-project-temp.ps1
cd backend
composer install
python -m pip install -r requirements-sources.txt
cd ..
```

Dot-source the helper in every development terminal. It keeps temporary files and caches inside `work/` and loads the included PHP ZIP/upload settings.

Start XAMPP MySQL. For a fresh local installation:

```powershell
php scripts/setup-local.php
cd backend
php artisan migrate
php artisan studio:create-admin admin@example.com --name="Studio Administrator"
cd ../frontend
npm ci
cd ..
```

The local setup creates environment files, generates an application key and database password, and provisions `grands_digital_studio` with a dedicated `grands_studio` account at `127.0.0.1:3306`. It requires database administrator access, defaulting to the local XAMPP root account. If necessary, supply `DB_ADMIN_USERNAME` and `DB_ADMIN_PASSWORD` privately through the environment. It does not change an existing database user's password. Do not use this provisioning script in production.

For other environments, copy the backend and frontend `.env.example` files to `.env`, configure the database manually, run `php artisan key:generate`, `php artisan migrate` and administrator provisioning from `backend/`, then install frontend dependencies. No default login is shipped. Preserve an existing `APP_KEY`: replacing it prevents decryption of saved provider credentials.

### Start the application

Use separate terminals at the root, initializing the temporary-directory helper in each:

```powershell
# Terminal 1: API
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
# Terminal 2: frontend
cd frontend
npm run dev
```

```powershell
# Terminal 3: reference processing
.\scripts\start-source-worker.ps1
```

```powershell
# Terminal 4: AI generation
.\scripts\start-generation-worker.ps1
```

Open **http://127.0.0.1:5173**. Keep the database and both workers running. Worker launchers retry process/database failures and prevent duplicate launchers for the same queue. They do not install automatic startup after reboot. If a job remains queued, check its worker and database first.

Vite proxies `/api` and `/sanctum` to the API. Use the same browser hostname consistently for cookie sessions.

### Configure and generate

1. Verify `SOURCE_PYTHON_BINARY`, `SOURCE_SCANNER` and `SOURCE_SCANNER_BINARY` in the private backend environment. Scanner failures block processing.
2. Sign in as administrator, open **AI providers**, save credentials and a valid model ID, and enable the provider.
3. Create a client, brand profile, project and brief with audience, purpose, format, length and call to action.
4. Upload/retrieve references, wait until ready, select them, choose a provider and generate.
5. Inspect history and findings. Supply missing evidence or use continuation/draft correction where available.

An API key identifies a provider account, not a model. Provider availability, quotas and pricing are external constraints. Free access is not guaranteed; Hugging Face requires a supported, verified free route. There is no automatic cross-provider fallback.

## Verification

Initialize the temporary-directory helper, then:

```powershell
cd backend
php artisan test
cd ../frontend
node --test src/lib/generationProgress.test.js
npm run lint
npm run build
```

Backend tests use in-memory SQLite and mocked providers; never reset the development database to run tests. Coverage includes access controls, source safety, providers, checkpoints and quality gates. Tests do not verify live provider quotas or model quality. Frontend lint currently reports existing React warnings. GitHub Actions runs the backend suite and frontend checks on pushes and pull requests.

## Deployment notes

- Serve Laravel's `public/` directory and the built frontend through a production web server.
- Configure HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, secure cookies, trusted origins and correct Sanctum domains.
- Keep secrets outside Git. Preserve the application key and grant the runtime database user only required privileges.
- Supervise both workers using their connection-specific settings. Generation timeout must stay below reservation duration (supplied values: 1860 and 2000 seconds).
- Keep sources private, restrict storage permissions, configure malware scanning, and monitor failures and spending.
- Securely back up the database, private sources and application key; test restores and review release migrations.

## Repository layout

```text
backend/    Laravel API, migrations, extraction helpers and tests
frontend/   React application and workflow tests
scripts/    Local setup, backup and worker helpers
.github/    Continuous integration
```

Environment files, credentials, dependencies, database dumps, uploads, caches, logs, generated output and internal work/history documents are excluded. Dependency lockfiles and empty storage placeholders are included.

## Licensing

No open-source license has been granted for the custom application code. Obtain permission from the rights holder before reuse or redistribution. Third-party dependencies retain their respective licenses; consult their package metadata and installed license files. The Laravel scaffold's existing MIT metadata does not license all custom application code.
