# Structured Report Template

For every vulnerability or bug identified, use the following exact Markdown format:

```markdown
## [Vulnerability/Bug Name]

**Severity**: [Critical | High | Medium | Low]

### Description
[A concise explanation of the vulnerability or bug, including the root cause and the specific component/file involved.]

### Exploit Scenario
[A step-by-step hypothetical scenario demonstrating how an attacker or user could trigger the issue and what the impact would be.]

### Fix
[A specific explanation of how to resolve the issue, including code snippets demonstrating the secure/corrected implementation.]

### Test
[Instructions on how to test that the fix was successful (e.g., a specific payload to send, a unit test concept, or verification steps).]
```