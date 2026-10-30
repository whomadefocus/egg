import hashlib
import ipaddress
import logging
import os
import re
import socket
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
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
ARTICLE_FETCH_WORKERS = max(1, min(12, int(os.environ.get("ARTICLE_FETCH_WORKERS", "6"))))
WORKER_RUN_SECONDS = max(0, int(os.environ.get("WORKER_RUN_SECONDS", "0")))
POLL_INTERVAL_SECONDS = max(5, int(os.environ.get("POLL_INTERVAL_SECONDS", "20")))
CRAWL_INTERVAL_SECONDS = max(60, int(os.environ.get("CRAWL_INTERVAL_SECONDS", "900")))

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


def valid_image_url(value):
    value = str(value or "").strip()
    parsed = urlparse(value)
    if parsed.scheme not in ("http", "https") or not parsed.hostname or parsed.username or parsed.password:
        return ""
    return value[:2048]


def resolve_image_url(base_url, value):
    value = str(value or "").strip()
    return valid_image_url(urljoin(base_url, value)) if value else ""


class ImageSourceParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.image_url = ""

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if tag.lower() == "img" and not self.image_url:
            self.image_url = attributes.get("src", "") or attributes.get("data-src", "")


def image_from_entry(entry, feed_url):
    for field in ("media_thumbnail", "media_content"):
        for item in entry.get(field, []) or []:
            image_url = resolve_image_url(feed_url, item.get("url", ""))
            if image_url:
                return image_url
    for link in entry.get("links", []) or []:
        if link.get("rel") == "enclosure" and str(link.get("type", "")).startswith("image/"):
            image_url = resolve_image_url(feed_url, link.get("href", ""))
            if image_url:
                return image_url
    for content in entry.get("content", []) or []:
        parser = ImageSourceParser()
        parser.feed(content.get("value", ""))
        image_url = resolve_image_url(feed_url, parser.image_url)
        if image_url:
            return image_url
    return ""


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
        response = fetch_public_url(session, url, timeout=(5, 8))
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


def fetch_feed(session, feed, on_progress=None):
    feed_url = feed.get("url", "").strip()
    source_name = feed.get("name", "").strip()
    if not feed_url or not source_name:
        log.warning("Skipping feed without a name or URL")
        return []

    log.info("Fetching RSS feed: %s", source_name)
    try:
        response = fetch_public_url(session, feed_url, timeout=(8, 15))
        parsed = feedparser.parse(response.content)
        if parsed.bozo and not parsed.entries:
            log.warning("Could not parse feed %s: %s", feed_url, parsed.bozo_exception)
            return []
    except (requests.RequestException, ValueError) as error:
        log.warning("Could not fetch feed %s: %s", feed_url, error)
        return []

    candidates = []
    for entry in parsed.entries[:MAX_PER_FEED]:
        url = entry.get("link", "").strip()
        title = clean_html(entry.get("title", ""))
        if not url or not title or urlparse(url).scheme not in ("http", "https"):
            continue

        content_entries = entry.get("content") or [{}]
        fallback = clean_html(content_entries[0].get("value", ""))
        fallback = fallback or clean_html(entry.get("summary", ""))
        candidates.append(
            {
                "source_name": source_name,
                "source_url": feed_url,
                "guid": str(entry.get("id") or url),
                "url": url,
                "title": title[:512],
                "summary": clean_html(entry.get("summary", ""))[:10_000],
                "cover_image_url": image_from_entry(entry, feed_url),
                "fallback": fallback,
                "published_at": parse_date(
                    entry.get("published") or entry.get("updated") or entry.get("created")
                ),
            }
        )

    if not candidates:
        log.info("No usable RSS articles from %s", source_name)
        return []

    log.info(
        "%s: found %d articles; fetching page text with %d workers",
        source_name,
        len(candidates),
        min(ARTICLE_FETCH_WORKERS, len(candidates)),
    )
    articles = [None] * len(candidates)
    with ThreadPoolExecutor(max_workers=min(ARTICLE_FETCH_WORKERS, len(candidates))) as executor:
        futures = {}
        for index, candidate in enumerate(candidates):
            future = executor.submit(extract_candidate, candidate)
            futures[future] = index

        completed = 0
        for future in as_completed(futures):
            index = futures[future]
            candidate = candidates[index]
            try:
                content = future.result()
            except Exception:
                log.exception("Article extraction failed: %s", candidate["url"])
                content = candidate["fallback"]
            articles[index] = {
                key: value for key, value in candidate.items() if key != "fallback"
            }
            articles[index]["content"] = (content or candidate["title"])[:MAX_ARTICLE_CHARS]
            completed += 1
            if on_progress and (completed % 3 == 0 or completed == len(candidates)):
                on_progress(completed, len(candidates))
            if completed % 5 == 0 or completed == len(candidates):
                log.info("%s: extracted page text for %d/%d articles", source_name, completed, len(candidates))

    log.info("Fetched %d articles from %s", len(articles), source_name)
    return articles


def extract_candidate(candidate):
    with requests.Session() as article_session:
        article_session.headers.update({"User-Agent": USER_AGENT, "Accept": "text/html,application/xhtml+xml"})
        return extract_article(article_session, candidate["url"], candidate["fallback"])


def load_feeds():
    import json

    feeds = json.loads(FEEDS_FILE.read_text(encoding="utf-8"))
    if not isinstance(feeds, list):
        raise ValueError("feeds.json must contain a JSON array")
    return feeds


def embed_jobs(session, model):
    total = 0
    while True:
        log.info("Checking API for pending embedding jobs")
        result = api_request(session, "claim_jobs", {"limit": 25})
        jobs = result.get("jobs", [])
        if not jobs:
            break
        log.info("Embedding batch of %d jobs", len(jobs))
        report_worker_status(session, "embedding", f"Vectorizing {len(jobs)} queued posts and interests", 0, len(jobs))
        vectors = model.encode(
            [job["text"] for job in jobs],
            normalize_embeddings=True,
            convert_to_numpy=True,
            show_progress_bar=False,
        )
        batch_completed = 0
        for job, vector in zip(jobs, vectors):
            try:
                if job["type"] == "article":
                    completion = {"type": "article", "article_id": job["article_id"]}
                else:
                    completion = {"type": "interest", "job_id": job["job_id"]}
                completion["embedding"] = vector.tolist()
                api_request(session, "complete_job", completion)
                total += 1
                batch_completed += 1
                report_worker_status(
                    session,
                    "embedding",
                    f"Saved {batch_completed}/{len(jobs)} vectors in this batch",
                    batch_completed,
                    len(jobs),
                )
            except Exception:
                log.exception("Embedding job failed: %s", job.get("article_id", job.get("job_id")))
                raise
    log.info("Embedded %d queued items", total)


def report_worker_status(session, phase, message, completed=0, total=0):
    try:
        api_request(
            session,
            "worker_status",
            {"phase": phase, "message": message, "completed": completed, "total": total},
        )
    except Exception:
        log.exception("Could not publish worker status")


def discover_sources(session, configured_feeds):
    response = api_request(session, "list_sources")
    feeds_by_url = {feed["url"]: feed for feed in configured_feeds if feed.get("url")}
    for source in response.get("sources", []):
        url = source.get("url", "").strip()
        name = source.get("name", "").strip()
        if url and name:
            feeds_by_url[url] = {"name": name, "url": url}
    return list(feeds_by_url.values())


def crawl_sources(session, feeds):
    articles = []
    seen = set()
    report_worker_status(session, "crawling", f"Checking {len(feeds)} RSS sources", 0, len(feeds))
    for index, feed in enumerate(feeds, start=1):
        report_worker_status(
            session,
            "crawling",
            f"Reading {feed.get('name', 'RSS source')} ({index}/{len(feeds)})",
            index - 1,
            len(feeds),
        )
        def page_progress(completed, total):
            report_worker_status(
                session,
                "crawling",
                f"Reading {feed.get('name', 'RSS source')}: {completed}/{total} posts",
                completed,
                total,
            )

        for article in fetch_feed(session, feed, page_progress):
            identity = hashlib.sha256(article["url"].encode()).hexdigest()
            if identity not in seen:
                seen.add(identity)
                articles.append(article)

    if articles:
        report_worker_status(session, "ingesting", f"Saving {len(articles)} crawled posts", 0, len(articles))
        result = api_request(session, "ingest", {"articles": articles})
        log.info("Ingested %s article records", result.get("saved", 0))
    else:
        log.info("No articles collected from configured sources")
    return len(articles)


def run_worker(session, model, configured_feeds):
    started = time.monotonic()
    next_source_check = 0.0
    next_crawl = 0.0
    current_feeds = []
    source_signature = ()
    while True:
        now = time.monotonic()
        if WORKER_RUN_SECONDS and now - started >= WORKER_RUN_SECONDS:
            break

        if now >= next_source_check:
            current_feeds = discover_sources(session, configured_feeds)
            updated_signature = tuple(sorted(feed["url"] for feed in current_feeds))
            if updated_signature != source_signature:
                source_signature = updated_signature
                next_crawl = now
            next_source_check = now + POLL_INTERVAL_SECONDS

        if now >= next_crawl:
            crawl_sources(session, current_feeds)
            next_crawl = time.monotonic() + CRAWL_INTERVAL_SECONDS

        embed_jobs(session, model)
        remaining = WORKER_RUN_SECONDS - (time.monotonic() - started) if WORKER_RUN_SECONDS else POLL_INTERVAL_SECONDS
        if remaining <= 0:
            break
        report_worker_status(session, "waiting", "Listening for new sources and embedding requests", 0, 0)
        time.sleep(min(POLL_INTERVAL_SECONDS, remaining))

    report_worker_status(session, "stopped", "Worker run window ended", 0, 0)


def main():
    if not API_URL or not WORKER_TOKEN:
        raise SystemExit("Set NEWS_API_URL and WORKER_TOKEN in the environment")

    token = os.environ.get("HF_TOKEN")
    if MODEL_ID == "google/embeddinggemma-300m" and not token:
        raise SystemExit(
            "HF_TOKEN is empty. Accept access to google/embeddinggemma-300m on Hugging Face, "
            "then add a read token as the GitHub Actions HF_TOKEN secret."
        )

    session = requests.Session()
    session.headers.update(
        {
            "Authorization": f"Bearer {WORKER_TOKEN}",
            "User-Agent": USER_AGENT,
            "Accept": "application/json",
        }
    )
    report_worker_status(session, "starting", "Loading the Gemma embedding model", 0, 0)
    log.info("Loading embedding model %s before starting the crawl", MODEL_ID)
    try:
        model = SentenceTransformer(MODEL_ID, token=token or None)
    except Exception as error:
        report_worker_status(session, "error", f"Could not load embedding model: {error}"[:180], 0, 0)
        raise SystemExit(
            f"Could not load {MODEL_ID}. Confirm your Hugging Face account has accepted the "
            f"model access terms and HF_TOKEN is a valid read token. Details: {error}"
        ) from error
    log.info("Embedding model ready")
    report_worker_status(session, "starting", "Embedding model ready; loading sources", 0, 0)
    feeds = load_feeds()
    log.info("Starting worker loop with %d configured feeds", len(feeds))
    try:
        run_worker(session, model, feeds)
    except Exception as error:
        report_worker_status(session, "error", str(error)[:180], 0, 0)
        raise


if __name__ == "__main__":
    main()