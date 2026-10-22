# Examcenter

Examcenter is an offline-first PHP/MariaDB assessment platform.

## Current architecture

Examcenter Pro Architecture v2 combines:

- Core CBT that remains usable offline.
- Universal institution and organisational modelling.
- Signed, installation-bound and machine-bound Pro entitlements.
- Legacy-license compatibility during migration.
- Idempotent database migrations and legacy-to-universal backfill.

See `docs/PRO_ARCHITECTURE.md` for the architecture contract.
See `docs/UNIVERSAL_SCHEMA.md` for the canonical Universal schema contract.
See `docs/SYSTEM_AUDIT.md` for the latest system audit and remaining gaps.

## Local setup

1. Install XAMPP with Apache and MariaDB/MySQL.
2. Copy Examcenter into `C:/xampp/htdocs/`.
3. Start Apache and MariaDB.
4. Configure database values through environment variables when they differ from the defaults.
5. Run the legacy schema in `help.txt` for an existing installation.
6. Run the additive Universal migration:

```
php database/migrate.php
```

7. For an existing installation, run the legacy mapping:

```
php database/backfill_legacy.php
```

The migration does not delete or rename legacy CBT tables.

## Pro licensing

Configure:

- `EXAMCENTER_PRO_SERVER`
- `EXAMCENTER_LICENSE_SERVER` for legacy compatibility only

The Pro client consumes the v2 entitlement API and verifies signed packages locally. Core CBT is not blocked when the Pro server is unavailable.

## Security

Runtime installation IDs, encrypted license files, caches, logs and secrets must not be committed. Use `.env.example` as the configuration template.

## Offline AI theory marking

Examcenter uses `llama-server` from llama.cpp for local Qwen 3 1.7B inference. It does not require Ollama. The runtime is checked and started on demand when a local AI feature is requested, so ordinary objective exams can still be submitted if the AI model or runtime is unavailable. Failed AI grading is recorded for teacher retry; Examcenter does not download a model during an exam.

Configure these optional environment variables when the defaults do not match the local machine:

- `EXAMCENTER_LLAMA_SERVER`: full path to `llama-server.exe` (or `llama-server`).
- `EXAMCENTER_LLAMA_MODEL_PATH`: full path to the existing Qwen 3 1.7B Q4_K_M GGUF file.
- `EXAMCENTER_LLAMA_ENDPOINT`: local server URL; default `http://127.0.0.1:11435`.
- `EXAMCENTER_LLAMA_MODEL_ALIAS`: API model alias; default `qwen3-1.7b`.
- `EXAMCENTER_LLAMA_CONTEXT_SIZE`: context size; default `4096`.
- `EXAMCENTER_LLAMA_PARALLEL`: llama-server inference slots; default `1` until benchmarks justify increasing it.

Examcenter searches common llama.cpp/Hugging Face cache locations for the local Qwen GGUF. If it cannot find the file or server executable, AI grading reports a clear error rather than downloading model files or blocking objective result storage. During development, make sure a compatible `llama-server` binary is available; `llama-cli` alone is a command-line runner and does not provide the HTTP API used by the PHP grader.


## Offline AI theory grading queue

Theory answers are saved before AI grading begins. A persistent MariaDB job is then processed by the CLI worker; browser submission requests never wait for llama.cpp inference. Each model request contains no more than five questions. Longer theory assessments are split into five-question batches within the same durable job.

For an existing database created before this queue change, apply the queue/result migration once after the theory assessment schema and the combined-score migration. Do not run this ALTER migration on a fresh database already created from the updated `help.txt`; that schema already contains these columns and table:

~~~sql
SOURCE database/migrations/20261022_0002_theory_grading_queue.sql;
~~~

Start the worker from the Examcenter project root on the main school computer. On the bundled Windows build, `scripts/start_theory_grading_worker.bat` attempts to locate the bundled PHP CLI and launches the worker minimized. The app launcher/Inno Setup entry should call this helper after MariaDB is ready; the repository does not currently contain the Inno Setup script to wire this automatically. For development, you can also start it manually:

~~~powershell
php scripts/theory_grading_worker.php
~~~

For a one-job diagnostic run:

~~~powershell
php scripts/theory_grading_worker.php --once
~~~

The worker processes one queue job at a time. llama-server defaults to one parallel inference slot (`EXAMCENTER_LLAMA_PARALLEL=1`). Do not increase concurrency until the target computer has been benchmarked. Keep the worker running on the host computer during grading; it does not need to run on student workstations. If the worker stops, pending jobs remain in MariaDB and resume when it starts again. Jobs abandoned in processing are recovered after a 15-minute lease. Failures retry automatically up to three times; teachers can requeue terminal failures from the Theory Grading Queue page without resubmitting student answers.

The migration adds `objective_score`, `objective_total`, `theory_score`, and `theory_grading_status` to `results`. While theory marks are pending, the result page shows a provisional objective score; after the worker saves the marks, it computes the final combined score from the saved objective score plus the theory score, avoiding double-counting on retries.
