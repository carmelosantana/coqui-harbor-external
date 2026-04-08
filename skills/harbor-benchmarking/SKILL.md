---
name: harbor-benchmarking
description: Operational SOP for running Harbor benchmark campaigns against Coqui agents, analyzing results, and tracking performance over time.
version: 1
---

# Harbor Benchmarking Skill

You are executing a Harbor benchmark campaign. Follow this operational procedure precisely.

## Pre-Flight Checklist

Before running any benchmark:

1. **Verify dependencies** — call `harbor_check` to confirm Harbor CLI, Docker, and Python are available.
2. **Validate tasks** — call `harbor_task_validate` on each task directory to catch structural issues before wasting compute.
3. **Record baseline** — if this is a regression test, note the previous job directory for comparison. Create a `plan` artifact documenting the benchmark goals.

## Creating Benchmark Tasks

When creating new tasks for Coqui evaluation:

1. Use `harbor_task_init` to scaffold the task directory structure.
2. Write a clear `instruction.md` that mirrors real user requests. Avoid overly artificial or contrived scenarios.
3. Configure `task.toml` with appropriate timeouts:
   - `agent.timeout_sec`: 300–600 for coding tasks, 60–120 for search/analysis tasks
   - `verifier.timeout_sec`: 60 is usually sufficient
4. Create a `tests/test.sh` that produces `reward.txt` with a float 0.0–1.0:
   - `1.0` = fully correct
   - `0.0` = completely wrong
   - Partial scores for partial correctness
5. Provide a `solution/` reference when possible — useful for Oracle agent baseline.

### Task Categories

Organize tasks by capability being tested:

| Category | Example Tasks | Key Metrics |
|----------|---------------|-------------|
| File editing | Create, modify, refactor files | Correctness, number of edits |
| Shell usage | Run commands, parse output | Command accuracy, output interpretation |
| Multi-step | Complex workflows requiring planning | Completion rate, iteration count |
| Tool use | Specific toolkit operations | Tool selection accuracy, efficiency |
| Error recovery | Tasks with intentional obstacles | Recovery success rate |

## Running Evaluations

### Single Task Run

```
harbor_run(path: "tasks/my-task", agent: "coqui_harbor_agent.agent:CoquiExternalAgent", model: "anthropic/claude-sonnet-4-20250514")
```

### Full Dataset Run

```
harbor_run(path: "tasks/", agent: "coqui_harbor_agent.agent:CoquiExternalAgent", model: "anthropic/claude-sonnet-4-20250514", concurrency: 4)
```

### Background Execution (Recommended)

For evaluations with many tasks, use background tools:

```
start_background_tool(tool_name: "harbor_run", arguments: "{\"path\": \"tasks/\", ...}", title: "Harbor benchmark: coding tasks")
```

Then monitor with `harbor_run_status` or `task_status`.

## Analyzing Results

After a run completes, follow this analysis sequence:

### 1. Summary Overview
```
harbor_results(job_dir: "jobs/<job-name>", include_trials: false)
```

Check: pass_rate, mean reward, duration stats.

### 2. Failure Triage
```
harbor_failures(job_dir: "jobs/<job-name>", trajectory_tail: 10)
```

For each failure, classify the root cause:
- **Hallucination** — agent referenced non-existent API/file/method
- **Tool misuse** — wrong tool selected or incorrect parameters
- **Planning failure** — agent didn't break down the problem correctly
- **Iteration exhaustion** — ran out of iterations before completing
- **Environment issue** — Docker/timeout/infrastructure problem

### 3. Deep Inspection (for interesting failures)
```
harbor_trial_inspect(trial_dir: "jobs/<job-name>/<trial>", include_trajectory: true)
```

Look for patterns in the trajectory: wasted iterations, repeated errors, tool selection issues.

### 4. Regression Detection
```
harbor_compare(job_dirs: "jobs/baseline,jobs/current")
```

Flag any tasks where reward decreased (regression). Create todos for investigating regressions.

## Reporting

After analysis, create a structured artifact:

```
artifact_create(
    type: "document",
    title: "Benchmark Report: <campaign-name>",
    content: "<markdown report with tables, scores, and recommendations>"
)
```

### Report Template

```markdown
# Benchmark Report: [Campaign Name]

**Date:** [date]
**Model:** [model]
**Tasks:** [count] | **Pass Rate:** [rate]% | **Mean Reward:** [reward]

## Summary
[Overall findings in 2-3 sentences]

## Results
| Task | Reward | Duration | Status |
|------|--------|----------|--------|
| ... | ... | ... | ... |

## Failures
| Task | Root Cause | Trajectory Summary |
|------|------------|-------------------|
| ... | ... | ... |

## Regressions (vs. baseline)
| Task | Previous | Current | Delta |
|------|----------|---------|-------|
| ... | ... | ... | ... |

## Recommendations
1. [Specific, actionable improvement recommendation]
2. [...]
```

## Performance Tracking

For ongoing performance monitoring:

1. **Save results as artifacts** — create a `document` artifact after each benchmark run.
2. **Use todos** — create todos for investigating failures and regressions.
3. **Schedule regular runs** — use `schedule_create` to run benchmarks automatically (e.g., after releases).
4. **Compare over time** — use `harbor_compare` with multiple job directories to track trends.

## Common Patterns

### A/B Model Comparison
Run the same dataset with different models, then compare:
```
harbor_run(path: "tasks/", model: "anthropic/claude-sonnet-4-20250514")
harbor_run(path: "tasks/", model: "openai/gpt-4o")
harbor_compare(job_dirs: "jobs/run-sonnet,jobs/run-gpt4o")
```

### Pre/Post Change Validation
Run baseline before a code change, then after:
```
# Before change
harbor_run(path: "tasks/", ...) → jobs/baseline
# Apply change
# After change
harbor_run(path: "tasks/", ...) → jobs/after-change
harbor_compare(job_dirs: "jobs/baseline,jobs/after-change")
```

### Role-Specific Benchmarking
Test specific roles with targeted task sets:
```
harbor_run(path: "tasks/coding/", agent: "...", extra_args: "--env COQUI_ROLE=coder")
harbor_run(path: "tasks/research/", agent: "...", extra_args: "--env COQUI_ROLE=explorer")
```
