# Threat Modeling Lite

When performing a lightweight threat model, use the STRIDE methodology adapted for rapid analysis.

## Process
1. **Identify the Assets**: What data or resources need protection? (e.g., PII, credentials, financial records).
2. **Identify the Architecture**: Briefly outline how data flows through the application (trust boundaries, APIs, databases).
3. **Analyze Threats**: Consider the following categories:
   - **Spoofing**: Can an attacker pretend to be someone else?
   - **Tampering**: Can an attacker modify data in transit or at rest?
   - **Repudiation**: Can a user deny performing an action without the system being able to prove otherwise?
   - **Information Disclosure**: Can an attacker access unauthorized information?
   - **Denial of Service**: Can an attacker disrupt the availability of the system?
   - **Elevation of Privilege**: Can a regular user gain administrative privileges?

## Output
Produce a brief summary of the highest-risk threats identified during this process, and then format any actionable vulnerabilities using the standard report format (see [report-template.md](report-template.md)).