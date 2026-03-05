# Root-Cause Debugging Playbook

Follow this systematic approach when debugging complex issues or vulnerabilities:

## 1. Reproduce & Comprehend
- Understand exactly what the observed behavior is vs. the expected behavior.
- Identify the trigger: What specific input or state causes the failure?

## 2. Isolate the Component
- Trace the execution flow backwards from the point of failure.
- Narrow down the problem to a specific function, class, or service boundary.

## 3. Formulate Hypotheses
- Create 2-3 technical hypotheses about *why* the failure occurs (e.g., "The database lock is released before the transaction commits," "The input string contains a null byte causing premature truncation").

## 4. Verify the Root Cause
- Mentally (or via code execution) test the hypotheses against the provided code.
- Confirm the exact line(s) of code or architectural flaw responsible.

## 5. Devise the Fix
- Propose a solution that addresses the root cause, not just the symptom.
- Ensure the fix does not introduce regressions or new vulnerabilities.