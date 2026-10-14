# 365CMS – Projektdokumentation | Abschnitt: Asset – AI Platform
> **Stand:** 2026-09-14 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-09-14

## English

This directory contains the AI platform asset used by the runtime. Application integration is centralized in `CMS/core/Services/AI/` and admin configuration in `CMS/admin/modules/system/AiServicesModule.php`. Keep provider policy, secrets, quotas, and output validation in those application services rather than in asset files.

## Deutsch

Dieses Verzeichnis enthält das von der Runtime verwendete AI-Platform-Asset. Die Anwendungsintegration ist unter `CMS/core/Services/AI/` und die Admin-Konfiguration in `CMS/admin/modules/system/AiServicesModule.php` zentralisiert. Provider-Policy, Secrets, Quotas und Ausgabevalidierung gehören in diese Anwendungsschicht, nicht in Asset-Dateien.
