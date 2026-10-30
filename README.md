# Signal Desk

A small RSS news reader: GitHub Actions fetches feeds, the PHP API stores stories and coordinates embedding jobs, and a browser UI builds a local reading profile and personalized feed.

## Deploy the PHP app

1. Create a MySQL or MariaDB database and a database user with permission to create tables and read/write rows.
2. Upload `api.php`, `index.html`, `app.js`, and `styles.css` into `public_html/chat/` so the API is at `https://3rah.ir/chat/api.php`.
3. Configure the PHP environment variables `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`, and `WORKER_TOKEN`. Alternatively, copy `config.example.php` to `config.php`, replace every placeholder, and upload it beside `api.php`. Keep the real configuration out of Git.
4. Use HTTPS. The API creates its tables on the first request. The hosting account needs PDO MySQL enabled and permission for InnoDB foreign keys.

Create a long worker token locally with `openssl rand -hex 32`. Set that exact value in the PHP `WORKER_TOKEN` configuration and the GitHub repository secret named `WORKER_TOKEN`.

## Configure GitHub Actions

The workflow runs every three hours and can also be started from the Actions tab. Add these repository secrets under **Settings → Secrets and variables → Actions**:

- `NEWS_API_URL`: `https://3rah.ir/chat/api.php`
- `WORKER_TOKEN`: the shared random token configured in PHP
- `HF_TOKEN`: optional Hugging Face token if the Gemma model requires accepted gated-model access

The workflow installs Python dependencies and downloads/caches `google/embeddinggemma-300m` on the GitHub Actions runner. Model weights stay in the runner cache rather than being committed to the repository. Review and accept the model terms on Hugging Face if prompted. The embedding uses `sentence-transformers`; API storage is JSON in MySQL, so no vector database or extension is required.

Edit `feeds.json` to add or remove RSS sources. Each entry needs a display `name` and RSS `url`. The worker fetches at most 20 entries per feed per run, extracts page text when available, and falls back to the RSS description when a publisher blocks article fetching.

## How interests work

The browser creates a random profile ID stored in its local storage; there are no accounts or cross-device sync. Marked-read stories and followed sources are sent to the API. The API queues profile text, then the next worker run embeds it with the same Gemma model and stores the vector. The feed orders stories by vector similarity, recency, follows, and read state. The worker drains pending embeddings in batches of up to 25; jobs stay queued if a run times out. RSS-provided links and redirects are restricted to public HTTP(S) hosts to avoid fetching private network addresses.

## Local checks

```sh
php -l api.php
python -m compileall -q worker.py
```

The frontend is static and served from the same directory as `api.php`. For local preview, use any PHP-enabled web server; opening `index.html` directly does not provide API access.