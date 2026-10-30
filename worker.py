import hashlib
import ipaddress
import logging
import os
import re
import socket
import time
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.parse import urljoin, urlparse

import feedparser
import requests
import trafilatura
from sentence_transformers import SentenceTransformer


API_URL = os.environ.get("NEWS_API_URL", "").rstrip("/")
WORKER_TOKEN = os.environ.get("WORKER_TOKEN", "")
MODEL_ID = os.environ.get("EMBEDDING_MODEL", "google/embeddinggemma-300m")
FEEDS_FILE = Path(os.environ.get("FEEDS_FILE", "feeds.json"))
USER_AGENT = "SignalDeskNewsBot/1.0 (+RSS reader; contact: admin@example.com)"
MAX_ARTICLE_CHARS = 50_000
MAX_PER_FEED = 20

logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"))
log = logging.getLogger("news-worker")


def api_request(session, action, payload=None):
    response = session.post(
        API_URL,
        params={"action": action},
        json=payload or {},
        timeout=90,
    )
    response.raise_for_status()
    result = response.json()
    if not isinstance(result, dict):
        raise RuntimeError(f"API returned an unexpected response for {action}")
    return result


def parse_date(value):
    if not value:
        return None
    try:
        if isinstance(value, time.struct_time):
            parsed = datetime.fromtimestamp(time.mktime(value), timezone.utc)
        else:
            parsed = parsedate_to_datetime(str(value))
            if parsed.tzinfo is None:
                parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    except (TypeError, ValueError, OverflowError):
        return None


def clean_html(value):
    if not value:
        return ""
    text = re.sub(r"<[^>]+>", " ", str(value))
    return re.sub(r"\s+", " ", text).strip()


def is_public_http_url(value):
    try:
        parsed = urlparse(value)
        if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password:
            return False
        try:
            addresses = [ipaddress.ip_address(parsed.hostname)]
        except ValueError:
            records = socket.getaddrinfo(parsed.hostname, parsed.port or (443 if parsed.scheme == "https" else 80))
            addresses = [ipaddress.ip_address(record[4][0]) for record in records]
        return bool(addresses) and all(address.is_global for address in addresses)
    except (OSError, ValueError):
        return False


def fetch_public_url(session, url, timeout):
    current_url = url
    for _ in range(6):
        if not is_public_http_url(current_url):
            raise ValueError("URL must resolve to a public HTTP(S) address")
        response = session.get(current_url, timeout=timeout, allow_redirects=False)
        if response.is_redirect:
            location = response.headers.get("Location")
            if not location:
                response.raise_for_status()
            current_url = urljoin(current_url, location)
            continue
        response.raise_for_status()
        return response
    raise requests.RequestException("Too many redirects")


def extract_article(session, url, fallback):
    try:
        response = fetch_public_url(session, url, timeout=20)
        extracted = trafilatura.extract(
            response.text,
            include_comments=False,
            include_tables=False,
            favor_precision=True,
        )
        if extracted:
            return extracted[:MAX_ARTICLE_CHARS]
    except (requests.RequestException, ValueError) as error:
        log.debug("Article fetch failed for %s: %s", url, error)
    return fallback[:MAX_ARTICLE_CHARS]


def fetch_feed(session, feed):
    feed_url = feed.get("url", "").strip()
    source_name = feed.get("name", "").strip()
    if not feed_url or not source_name:
        log.warning("Skipping feed without a name or URL")
        return []

    try:
        response = fetch_public_url(session, feed_url, timeout=30)
        parsed = feedparser.parse(response.content)
        if parsed.bozo and not parsed.entries:
            log.warning("Could not parse feed %s: %s", feed_url, parsed.bozo_exception)
            return []
    except (requests.RequestException, ValueError) as error:
        log.warning("Could not fetch feed %s: %s", feed_url, error)
        return []

    articles = []
    for entry in parsed.entries[:MAX_PER_FEED]:
        url = entry.get("link", "").strip()
        title = clean_html(entry.get("title", ""))
        if not url or not title or urlparse(url).scheme not in ("http", "https"):
            continue

        content_entries = entry.get("content") or [{}]
        fallback = clean_html(content_entries[0].get("value", ""))
        fallback = fallback or clean_html(entry.get("summary", ""))
        content = extract_article(session, url, fallback)
        if not content:
            content = title
        summary = clean_html(entry.get("summary", ""))[:10_000]
        articles.append(
            {
                "source_name": source_name,
                "source_url": feed_url,
                "guid": str(entry.get("id") or url),
                "url": url,
                "title": title[:512],
                "summary": summary,
                "content": content,
                "published_at": parse_date(
                    entry.get("published") or entry.get("updated") or entry.get("created")
                ),
            }
        )
    log.info("Fetched %d articles from %s", len(articles), source_name)
    return articles


def load_feeds():
    import json

    feeds = json.loads(FEEDS_FILE.read_text(encoding="utf-8"))
    if not isinstance(feeds, list):
        raise ValueError("feeds.json must contain a JSON array")
    return feeds


def embed_jobs(session, model):
    total = 0
    while True:
        result = api_request(session, "claim_jobs", {"limit": 25})
        jobs = result.get("jobs", [])
        if not jobs:
            break
        for job in jobs:
            try:
                vector = model.encode(
                    job["text"],
                    normalize_embeddings=True,
                    convert_to_numpy=True,
                    show_progress_bar=False,
                ).tolist()
                if job["type"] == "article":
                    completion = {"type": "article", "article_id": job["article_id"]}
                else:
                    completion = {"type": "interest", "job_id": job["job_id"]}
                completion["embedding"] = vector
                api_request(session, "complete_job", completion)
                total += 1
            except Exception:
                log.exception("Embedding job failed: %s", job.get("article_id", job.get("job_id")))
                raise
    log.info("Embedded %d queued items", total)


def main():
    if not API_URL or not WORKER_TOKEN:
        raise SystemExit("Set NEWS_API_URL and WORKER_TOKEN in the environment")

    session = requests.Session()
    session.headers.update(
        {
            "Authorization": f"Bearer {WORKER_TOKEN}",
            "User-Agent": USER_AGENT,
            "Accept": "application/json",
        }
    )
    feeds = load_feeds()
    batch = []
    seen = set()
    for feed in feeds:
        for article in fetch_feed(session, feed):
            identity = hashlib.sha256(article["url"].encode()).hexdigest()
            if identity not in seen:
                seen.add(identity)
                batch.append(article)

    if batch:
        result = api_request(session, "ingest", {"articles": batch})
        log.info("Ingested %s article records", result.get("saved", 0))

    token = os.environ.get("HF_TOKEN")
    log.info("Loading embedding model %s", MODEL_ID)
    model = SentenceTransformer(MODEL_ID, token=token or None)
    embed_jobs(session, model)


if __name__ == "__main__":
    main()