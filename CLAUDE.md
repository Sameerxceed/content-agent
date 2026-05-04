# ContentAgent — AI Content Automation Platform

## Project Overview
ContentAgent is a PHP + MySQL SaaS platform that automates content creation and distribution for any website. An AI agent (Claude Haiku) scans a customer's website, researches keywords, generates blog posts and news, and publishes content — either through a hosted blog engine or external CMS connectors.

## Tech Stack
- **Backend:** PHP 8+ (no framework, clean procedural/class-based)
- **Database:** MySQL 8
- **AI:** Claude Haiku API (via Anthropic SDK / REST)
- **Hosting:** Linode VPS
- **Cron:** Linux cron for scheduled agent tasks
- **Frontend:** Plain HTML/CSS/JS for dashboard (no React, no build step)

## Architecture Principles
- Keep it simple. PHP renders HTML. No SPA, no build tools.
- Every feature should work as a standalone PHP script before being integrated.
- Agent scripts in `/agent/` are CLI-runnable: `php agent/scanner.php --site=1`
- Blog engine serves content on customer's domain via reverse proxy.
- All AI calls go through `includes/haiku.php` wrapper — single point of control.

## Code Conventions
- PHP files use `<?php` long tags, no short tags
- MySQL via PDO with prepared statements (no mysqli, no raw queries)
- Config in `config/config.php` (not checked into git — use config.example.php)
- Snake_case for variables and functions, PascalCase for classes
- No composer dependencies unless absolutely necessary (keep deployment simple)
- Error handling: log to file, never expose errors to users

## Database
- MySQL on Linode
- Migrations in `/database/migrations/` as numbered SQL files
- Seed data in `/database/seeds/`

## Security
- All API endpoints require API key or session auth
- Haiku API key stored in config, never in code
- Customer CMS credentials encrypted at rest
- CSRF tokens on all dashboard forms
- Rate limiting on public endpoints

## Deployment
- Git pull on Linode, no build step
- Apache or Nginx with PHP-FPM
- Let's Encrypt SSL
- Cron jobs registered via crontab
