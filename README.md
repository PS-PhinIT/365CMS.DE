<div align="center">

<!-- Falls du ein Logo im Repo hast, kannst du es hier einbinden: -->
<!-- <img src="CMS/assets/images/LOGO_365CMS-150px.png" alt="365CMS Logo" width="110" /> -->

# 365CMS

**A modern, framework-less, security-first PHP 8.4+ CMS & Portal Platform.**  
*Zero bloat. Full enterprise security. All-in-one core (Forms, Newsletter, Knowledgebase, Downloads & Feeds).*

[![Version](https://img.shields.io/badge/Version-4.0.0%20Stable-blue?style=flat-square)](https://github.com/DEIN-USER/365CMS/releases)
[![PHP Version](https://img.shields.io/badge/PHP-8.4.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-PDO%20(v25)-4479A1?style=flat-square&logo=mysql&logoColor=white)](#requirements)
[![Security](https://img.shields.io/badge/Security-CSP%20%7C%20Trusted%20Types%20%7C%20WebAuthn-success?style=flat-square)](#security--architecture)
[![Website](https://img.shields.io/badge/Website-365cms.de-0ea5e9?style=flat-square)](https://365cms.de/)

[🌐 Official Website](https://365cms.de/) • [📖 Documentation](DOC/README.md) • [🚀 Quick Start](#quick-start) • [🇩🇪 Deutsche Version](#deutsch)

</div>

---

<p align="center">
  <img src="SCREENSHOTS/365CMS-Dashboard_1.png" alt="365CMS Admin Dashboard" width="100%" />
</p>

| Posting List | Block Editor (New Post) | Media Library | Website Health |
|:---:|:---:|:---:|:---:|
| ![365CMS Post List](SCREENSHOTS/365CMS-PostList_1.png) | ![365CMS New Posting](SCREENSHOTS/365CMS-NewPost_1.png) | ![365CMS Mediathek](SCREENSHOTS/365CMS-Medien_1.png) | ![365CMS Website Health](SCREENSHOTS/365CMS-Website-Zustand_1.png) |

---

## ✨ Why 365CMS?

Most traditional CMS platforms either drown in legacy plugin dependencies or require complex Node.js/Docker build pipelines just to run a website. **365CMS** bridges the gap:

- ⚡ **Framework-less & Fast:** Pure, modern PHP `8.4.1+` architecture with zero external framework overhead, lazy sessions, OPcache warmup, and native ES modules without a build step.
- 🔋 **Batteries Included (No Plugin Tax):** Essential portal features ship directly in the core—including a **Form Builder**, **Double-Opt-In Newsletter**, **Knowledgebase/Glossary**, **Download Manager**, and **RSS Feed Aggregator**.
- 🛡️ **Security & Hardening by Default:** Strict Content Security Policy (CSP) with nonces and **Trusted Types**, authenticated encryption (`XChaCha20-Poly1305`), Passkeys (WebAuthn), TOTP MFA, LDAP, SSRF-protected HTTP client, and an integrated Security Audit suite.
- 🇪🇺 **Privacy & GDPR Native:** Built-in consent-aware analytics, local WOFF2 font hosting, automated data retention rules, GDPR export/deletion workflows, and an accessibility statement generator.
- 🧱 **Modern Block Editing:** Powered by a customized **Editor.js** canvas with block-level revisions/diffs, publish checklists, distraction-free focus mode, and optional admin-only AI translation & SEO assistance.

---

## 🚀 Core Capabilities

| Domain | Highlights & Features |
| :--- | :--- |
| **Content & Editorial** | Pages, posts, **Editor.js** blocks (SunEditor legacy fallback), block-diff revisions & restore, enforced publish checklists, focus mode, editorial calendar, scheduled publishing, native **DE/EN** localization, hub sites & interactive data tables. |
| **Built-in Portal Modules** | **Forms** (15 field types, spam protection, private file uploads), **Newsletter** (campaigns, segments, RFC 8058 one-click unsubscribe), **Knowledgebase** (auto-linking glossary), **Downloads** (versioned, rate-limited, private storage), and **Feed Aggregator** (OPML, digests). |
| **Modern Admin Shell** | Customizable sidebar profiles (*Classic*, *Structured*, or drag-and-drop *Custom Layout*), MultiSites context switcher, per-site plugin assignment, tile-based configuration pages, and live system health notices. |
| **Member Area (`/member`)** | Self-service dashboard, profile editor, security management (Passkeys, MFA, active session revocation), private media, internal messaging, favorites, subscriptions, and GDPR self-export. |
| **SEO & Compliance** | Dynamic XML sitemaps, IndexNow integration, 301 redirects & 404 monitor, Schema.org JSON-LD, consent-gated tracking (GA4, GTM, Matomo), cookie manager, legal text generator, and automated retention cleanup. |
| **Security & Auth** | Per-action CSRF tokens, RBAC capability matrix, CSP + Trusted Types runtime, account & IP rate limiting, TOTP MFA, WebAuthn/Passkeys, LDAP/AD auth, encrypted backups, and full audit logging. |
| **Admin-Only AI (Optional)** | Block-by-block Editor.js translation and SEO metadata generation with strict provider policies, SSRF guards, and atomic token quotas. *Zero public AI routes.* |
| **Operations & Dev** | Web installer (6 steps), CLI/Web cron runner, SMTP & Microsoft Graph mail queue, ZIP content import/export, SHA-256 verified marketplace, and 16 automated test suites (`TESTS/run-all.php`). |

---

## 📋 Requirements

- **PHP:** `8.4.1+` with extensions `pdo`, `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `gd`, `zip`, `fileinfo`
- **Database:** MySQL `8.0+` or MariaDB `10.6+` (`utf8mb4`, PDO, schema `v25`)
- **Web Server:** Apache 2.4 (`mod_rewrite` + `.htaccess`) or Nginx (`try_files` + security rules) serving `CMS/`
- **Permissions:** Writable directories for `config/`, `uploads/`, `cache/`, `logs/`, `backups/`, and `private/` (or `CMS_PRIVATE_PATH`)

---

## ⚡ Quick Start

1. **Deploy:** Upload the contents of [`CMS/`](CMS/) to your web root (or point your virtual host document root to `CMS/`).
2. **Install:** Open `https://your-domain.tld/install.php` in your browser and complete the 6-step setup wizard (system check, database, site & core modules, admin sidebar profile, administrator account).
3. **Lockdown:** The installer writes `CMS/config/app.php` with cryptographic keys and automatically locks itself.
4. **Configure:** Log in at `/cms-login`, open `/admin`, and verify mail delivery, backup paths (`CMS_BACKUP_PATH`), and the **Security Audit**.
5. **Automate:** Set up a recurring cron job (at least hourly):
   ```bash
   php /path/to/CMS/cron.php --task=all
