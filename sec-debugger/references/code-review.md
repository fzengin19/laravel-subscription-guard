# Secure Code Review Checklist

When reviewing code for security vulnerabilities, systematically check for the following common issues:

## 1. Input Validation & Sanitization
- Are all external inputs (user input, headers, cookies, API payloads) validated against a strict allowlist?
- Is there protection against Injection attacks (SQLi, XSS, Command Injection, LDAP Injection)?

## 2. Authentication & Authorization
- Are permissions explicitly checked on every endpoint/function? (e.g., Missing Broken Object Level Authorization / IDOR)
- Is authentication state handled securely? (e.g., secure cookies, proper JWT validation, avoiding TOCTOU race conditions).

## 3. Data Protection
- Are secrets, API keys, or passwords hardcoded in the codebase?
- Is sensitive data encrypted at rest and in transit?
- Does the code inadvertently log sensitive information?

## 4. Error Handling
- Are verbose error messages or stack traces returned to the user? (Information Disclosure)
- Does the application fail securely? (e.g., fail closed rather than fail open).

## 5. Business Logic & Concurrency
- Are there Race Conditions (TOCTOU) in critical logic (e.g., rate limiting, purchasing, state changes)?
- Can the business logic be bypassed by sending unexpected parameter types (e.g., Mass Assignment, Type Confusion)?