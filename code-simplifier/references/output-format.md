# Simplification Output Format

Whenever you propose a code simplification, you must format your response exactly like this:

```markdown
### 1. Original Code Location
[Specify the file path and line numbers, e.g., `src/Authentication/LoginController.php:45-60`]

### 2. Simplification Explanation
[Provide a brief, 1-2 sentence explanation of what you are simplifying and the technique you are using (e.g., "Flattening nested if-statements by using early returns/guard clauses.")]

### 3. Simplified Version
```[language]
// Insert the refactored code here
```

### 4. Reasoning
[Explain *why* this version is better. Focus on readability, maintainability, cyclomatic complexity reduction, or idiomatic language features. Reiterate that business logic remains unchanged.]
```