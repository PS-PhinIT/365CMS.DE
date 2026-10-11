# 365CMS Threat Model

## Project Description

365CMS is a self-hosted, framework-less PHP CMS and portal platform.

Technology:

- PHP 8.4+
- MySQL / MariaDB
- PDO database layer
- Plugin architecture
- Theme architecture
- Optional AI services
- Microsoft Graph integration
- LDAP integration

## Security Objectives

Protect:

- Administrator accounts
- Member accounts
- Session integrity
- Tenant/site separation
- Database contents
- Uploaded files
- Personal data
- AI processing context

## Trust Boundaries

The following inputs must always be considered untrusted:

- HTTP requests
- Form submissions
- Login requests
- API requests
- File uploads
- Media metadata
- Editor.js content
- SEO metadata
- Import files
- Plugin configuration
- Theme configuration
- Marketplace packages
- AI prompts
- Microsoft Graph responses
- LDAP responses

## Critical Findings

Rate as Critical:

- Authentication bypass
- Authorization bypass
- Privilege escalation
- SQL injection
- Remote code execution
- Arbitrary file write
- Arbitrary file read
- Tenant isolation bypass
- Access to other users' private content
- Access to protected admin functions
- Marketplace package trust bypass

## High Findings

Rate as High:

- Stored XSS
- Path traversal
- SSRF
- Unsafe file upload
- Session fixation
- CSRF affecting administrators
- WebAuthn bypass
- MFA bypass
- LDAP privilege escalation
- Backup disclosure
- AI context disclosure

## Medium Findings

Rate as Medium:

- Reflected XSS
- Information disclosure
- Missing permission enforcement
- Security header weaknesses
- Open debug functions

## Areas Requiring Additional Attention

### Authentication

Review:

- Login
- Password reset
- MFA
- TOTP
- Passkeys
- WebAuthn
- Session handling

### Authorization

Review:

- Roles
- Groups
- Capabilities
- Admin permission checks
- Member area isolation

### Content Management

Review:

- Pages
- Posts
- Revisions
- Categories
- Tags
- Editor.js content

### Upload Security

Review:

- Upload validation
- MIME validation
- Extension validation
- Image processing
- Upload storage

### Extensions

Review:

- Theme installation
- Plugin installation
- Marketplace packages
- SHA-256 validation
- Dynamic code loading

### Operations

Review:

- Backup and restore
- Cron execution
- Mail queue
- Audit logs
- Cache handling

### Privacy Features

Review:

- GDPR exports
- GDPR deletion requests
- Personal data exposure

### AI Features

Review:

- Prompt injection
- Stored prompt injection
- Context leakage
- Cross-user data exposure
- Unauthorized content access
- AI-assisted privilege escalation

## Out Of Scope

- Self-XSS
- Theoretical denial of service
- Missing best-practice recommendations without security impact
