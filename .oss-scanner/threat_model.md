# 365CMS Threat Model

## Description

365CMS ist ein webbasiertes Content-Management-System
mit Mehrbenutzerbetrieb, Rollenverwaltung und KI-Unterstützung.

## Trust Boundaries

Untrusted Input:

- Login Formulare
- API Requests
- Datei Uploads
- HTML Editor Inhalte
- Markdown Inhalte
- Plugin Konfigurationen
- AI Prompts
- JSON Import Dateien

## Critical Findings

Als kritisch einstufen:

- Remote Code Execution
- Authentication Bypass
- Privilege Escalation
- SQL Injection
- Zugriff auf fremde Mandanten

## High Findings

- Stored XSS
- SSRF
- Path Traversal
- Arbitrary File Upload

## Medium Findings

- Reflected XSS
- Information Disclosure

## Out Of Scope

- Self XSS
- DoS ohne Datenverlust

## Additional Review Areas

- Prompt Injection
- Stored Prompt Injection
- Cross User Prompt Leakage
- AI Function Abuse
- Data Exfiltration through LLM Features
- Unauthorized Context Exposure
