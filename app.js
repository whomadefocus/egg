const API_URL = new URL("api.php", window.location.href);
const USER_KEY = "signal-desk-user-v1";

function makeUserId() {
  if (crypto.randomUUID) return crypto.randomUUID().replaceAll("-", "");
  return Array.from(crypto.getRandomValues(new Uint8Array(20)), (value) => value.toString(16).padStart(2, "0")).join("");
}

let userId = localStorage.getItem(USER_KEY);
if (!userId || !/^[A-Za-z0-9_-]{8,80}$/.test(userId)) {
  userId = makeUserId();
  localStorage.setItem(USER_KEY, userId);
}

const state = { articles: [], sources: [], view: "for-you", search: "", loading: false, worker: null };
const articleList = document.querySelector("#article-list");
const sourceList = document.querySelector("#source-list");
const status = document.querySelector("#feed-status");
const toast = document.querySelector("#toast");
let toastTimer;

async function request(action, { method = "GET", body } = {}) {
  const url = new URL(API_URL);
  url.searchParams.set("action", action);
  if (method === "GET") url.searchParams.set("user_id", userId);
  const response = await fetch(url, {
    method,
    headers: method === "POST" ? { "Content-Type": "application/json" } : {},
    body: method === "POST" ? JSON.stringify({ ...body, action, user_id: userId }) : undefined,
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || `Request failed (${response.status})`);
  return result;
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("is-visible");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove("is-visible"), 3000);
}

function safeDate(value) {
  if (!value) return null;
  const date = new Date(value.replace(" ", "T") + (value.endsWith("Z") ? "" : "Z"));
  return Number.isNaN(date.getTime()) ? null : date;
}

function relativeDate(value) {
  const date = safeDate(value);
  if (!date) return "RECENT";
  const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60_000));
  if (minutes < 60) return `${minutes} MIN AGO`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} HR AGO`;
  const days = Math.floor(hours / 24);
  return days < 7 ? `${days} DAY${days === 1 ? "" : "S"} AGO` : date.toLocaleDateString(undefined, { month: "short", day: "numeric" }).toUpperCase();
}

function renderSources() {
  document.querySelector("#source-count").textContent = String(state.sources.length).padStart(2, "0");
  sourceList.replaceChildren();
  if (!state.sources.length) {
    const empty = document.createElement("p");
    empty.className = "empty-sources";
    empty.textContent = "Sources arrive with the first successful crawl.";
    sourceList.append(empty);
    return;
  }

  for (const source of state.sources) {
    const row = document.createElement("div");
    row.className = "source-row";
    const name = document.createElement("span");
    name.className = "source-name";
    name.textContent = source.name;
    const follow = document.createElement("button");
    follow.className = `follow-button${source.following ? " is-following" : ""}`;
    follow.type = "button";
    follow.textContent = source.following ? "✓" : "+";
    follow.title = `${source.following ? "Unfollow" : "Follow"} ${source.name}`;
    follow.setAttribute("aria-label", follow.title);
    follow.setAttribute("aria-pressed", String(source.following));
    follow.addEventListener("click", () => toggleFollow(source, follow));
    row.append(name, follow);
    sourceList.append(row);
  }
}

function renderWorker(worker = {}) {
  state.worker = worker;
  const strip = document.querySelector("#worker-strip");
  const phaseLabel = document.querySelector("#worker-phase");
  const message = document.querySelector("#worker-message");
  const fill = document.querySelector("#worker-progress-fill");
  const label = document.querySelector("#worker-progress-label");
  let phase = worker.phase || "offline";
  const updatedAt = safeDate(worker.updated_at);
  const ageSeconds = updatedAt ? (Date.now() - updatedAt.getTime()) / 1000 : Infinity;
  if (ageSeconds > 75 && !["error", "stopped"].includes(phase)) phase = "offline";

  const labels = {
    starting: "STARTING",
    crawling: "CRAWLING",
    ingesting: "SAVING POSTS",
    embedding: "VECTORIZING",
    waiting: "LISTENING",
    stopped: "RUN WINDOW ENDED",
    error: "WORKER ERROR",
    offline: "OFFLINE",
  };
  strip.dataset.phase = phase;
  phaseLabel.textContent = labels[phase] || phase.toUpperCase();
  message.textContent = phase === "offline"
    ? "Waiting for the next worker run"
    : worker.message || "Worker is checking for updates";

  const completed = Number(worker.completed) || 0;
  const total = Number(worker.total) || 0;
  const percent = total ? Math.max(0, Math.min(100, (completed / total) * 100)) : 0;
  fill.style.width = `${percent}%`;
  label.textContent = total
    ? `${completed} / ${total}`
    : updatedAt && ageSeconds <= 75
      ? `UPDATED ${relativeDate(worker.updated_at)}`
      : "NO RECENT ACTIVITY";
}

function renderInterest(data) {
  const card = document.querySelector(".interest-card");
  const message = document.querySelector("#interest-message");
  const updated = document.querySelector("#interest-updated");
  const fill = document.querySelector("#interest-progress");
  const reads = Number(data.read_count) || 0;
  const follows = Number(data.follow_count) || 0;
  const status = data.interest_status || "idle";
  card.dataset.state = status;
  document.querySelector("#reading-count").textContent = `${reads} read · ${follows} followed`;

  const messages = {
    idle: reads || follows ? "Waiting for enough signals to shape your feed." : "Read stories or follow sources to build your signal.",
    pending: "Your latest reading is queued for learning.",
    processing: "Finding patterns in what you read.",
    complete: "Your feed is tuned to your reading.",
    superseded: "Your newest reading is replacing an older update.",
  };
  message.textContent = messages[status] || messages.idle;
  const progress = status === "complete" ? 100 : status === "processing" ? 66 : status === "pending" || status === "superseded" ? 28 : 0;
  fill.style.width = `${progress}%`;
  updated.textContent = data.interest_updated_at
    ? `UPDATED ${relativeDate(data.interest_updated_at)}`
    : status === "processing"
      ? "LEARNING"
      : status === "pending"
        ? "QUEUED"
        : "NOT TRAINED";
}

function storyMatches(article, query) {
  const haystack = `${article.title} ${article.summary || ""} ${article.source_name} ${article.content || ""}`.toLocaleLowerCase();
  return haystack.includes(query);
}

function visibleArticles() {
  let articles = [...state.articles];
  if (state.view === "following") articles = articles.filter((article) => article.following);
  if (state.view === "latest") {
    articles.sort((left, right) => (safeDate(right.published_at || right.created_at)?.getTime() || 0) - (safeDate(left.published_at || left.created_at)?.getTime() || 0));
  }
  if (state.search) articles = articles.filter((article) => storyMatches(article, state.search));
  return articles;
}

function emptyMessage() {
  if (state.search) return ["No matching stories", "Try another search. The signal moves fast."];
  if (state.view === "following") return ["No followed stories yet", "Follow a source to see its latest stories here."];
  if (state.view === "for-you" && state.articles.length) return ["Your profile is warming up", "Read a few stories and your feed will learn what to surface."];
  return ["The desk is quiet", "Stories will appear after the scheduled RSS crawl runs."];
}

function renderArticles() {
  const articles = visibleArticles();
  document.querySelector("#story-count").textContent = String(articles.length).padStart(2, "0");
  articleList.replaceChildren();

  if (!articles.length) {
    const [heading, description] = emptyMessage();
    const empty = document.createElement("div");
    empty.className = "empty-feed";
    const title = document.createElement("h2");
    title.textContent = heading;
    const copy = document.createElement("p");
    copy.textContent = description;
    empty.append(title, copy);
    articleList.append(empty);
    return;
  }

  for (const [index, article] of articles.entries()) {
    const card = document.createElement("article");
    card.className = "article";
    card.style.animationDelay = `${Math.min(index, 7) * 35}ms`;

    const number = document.createElement("div");
    number.className = "article-index";
    number.textContent = String(index + 1).padStart(2, "0");

    const body = document.createElement("div");
    body.className = "article-body";
    const meta = document.createElement("div");
    meta.className = "article-meta";
    const source = document.createElement("span");
    source.className = "meta-source";
    source.textContent = article.source_name;
    const divider = document.createElement("span");
    divider.className = "meta-divider";
    divider.textContent = "/";
    const timestamp = document.createElement("span");
    timestamp.textContent = relativeDate(article.published_at || article.created_at);
    meta.append(source, divider, timestamp);
    if (article.following) {
      const tag = document.createElement("span");
      tag.className = "article-tag";
      tag.textContent = "FOLLOWING";
      meta.append(tag);
    }

    if (/^https?:\/\//i.test(article.cover_image_url || "")) {
      const cover = document.createElement("img");
      cover.className = "article-cover";
      cover.src = article.cover_image_url;
      cover.alt = "";
      cover.loading = "lazy";
      cover.referrerPolicy = "no-referrer";
      cover.addEventListener("error", () => cover.remove(), { once: true });
      body.append(meta, cover);
    } else {
      body.append(meta);
    }

    const heading = document.createElement("h2");
    const link = document.createElement("a");
    link.href = article.url;
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.textContent = article.title;
    link.addEventListener("click", () => markRead(article, true), { once: true });
    heading.append(link);

    const description = document.createElement("p");
    description.className = "article-summary";
    description.textContent = article.summary || article.content || "Open the source for the full story.";

    const bottom = document.createElement("div");
    bottom.className = "article-bottom";
    const time = document.createElement("time");
    time.dateTime = article.published_at || article.created_at || "";
    time.textContent = article.read ? "IN YOUR READING HISTORY" : "OPEN ORIGINAL STORY ↗";
    const read = document.createElement("button");
    read.className = `read-toggle${article.read ? " is-read" : ""}`;
    read.type = "button";
    read.textContent = article.read ? "Read" : "Mark read";
    read.setAttribute("aria-pressed", String(article.read));
    read.addEventListener("click", () => markRead(article, !article.read));
    bottom.append(time, read);
    body.append(heading, description, bottom);
    card.append(number, body);
    articleList.append(card);
  }
}

async function loadFeed({ quiet = false } = {}) {
  if (state.loading) return;
  state.loading = true;
  if (!quiet) status.textContent = "Connecting to your desk…";
  try {
    const data = await request("feed");
    state.articles = Array.isArray(data.articles) ? data.articles : [];
    state.sources = Array.isArray(data.sources) ? data.sources : [];
    renderSources();
    renderArticles();
    renderWorker(data.worker);
    renderInterest(data);
    if (!quiet) status.textContent = data.interest_updated_at ? "PROFILE UPDATED FROM YOUR READING" : "READ STORIES TO SHAPE YOUR PERSONAL FEED";
  } catch (error) {
    if (!quiet) {
      status.textContent = error.message.includes("Failed to fetch")
        ? "Could not reach the API. Check the PHP deployment and database settings."
        : error.message;
      renderSources();
      renderArticles();
    }
  } finally {
    state.loading = false;
  }
}

async function toggleFollow(source, button) {
  button.disabled = true;
  try {
    const following = !source.following;
    const result = await request("follow", { method: "POST", body: { source_id: source.id, following } });
    source.following = following;
    for (const article of state.articles) {
      if (article.source_id === source.id) article.following = following;
    }
    renderSources();
    renderArticles();
    if (result.interest_job_id) showToast("Follow saved. Your interest profile is queued for an update.");
    else showToast(following ? `Following ${source.name}.` : `Unfollowed ${source.name}.`);
  } catch (error) {
    showToast(error.message);
    button.disabled = false;
  }
}

async function markRead(article, read) {
  if (!read || article.read) return;
  article.read = true;
  renderArticles();
  try {
    const result = await request("read", { method: "POST", body: { article_id: article.id } });
    if (result.interest_job_id) status.textContent = "READING SAVED · INTEREST PROFILE QUEUED";
  } catch (error) {
    article.read = false;
    renderArticles();
    showToast(error.message);
  }
}

document.querySelectorAll(".tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    state.view = tab.dataset.view;
    document.querySelectorAll(".tab").forEach((item) => {
      const active = item === tab;
      item.classList.toggle("is-active", active);
      item.setAttribute("aria-pressed", String(active));
    });
    renderArticles();
  });
});

document.querySelector("#search").addEventListener("input", (event) => {
  state.search = event.target.value.trim().toLocaleLowerCase();
  renderArticles();
});

document.addEventListener("keydown", (event) => {
  if (event.key === "/" && !["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) {
    event.preventDefault();
    document.querySelector("#search").focus();
  }
  if (event.key === "Escape" && document.activeElement.id === "search") {
    document.querySelector("#search").value = "";
    state.search = "";
    renderArticles();
    document.activeElement.blur();
  }
});

document.querySelector("#refresh-interests").addEventListener("click", async (event) => {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const result = await request("refresh_interests", { method: "POST" });
    showToast(result.queued ? "Interest profile queued. It will update after the next worker run." : "Read or follow a source first to build an interest profile.");
  } catch (error) {
    showToast(error.message);
  } finally {
    button.disabled = false;
  }
});

document.querySelector("#add-source-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const button = form.querySelector("button[type='submit']");
  const note = document.querySelector("#source-form-note");
  button.disabled = true;
  note.textContent = "Adding source…";
  try {
    const result = await request("add_source", {
      method: "POST",
      body: {
        name: form.elements.namedItem("name").value.trim(),
        feed_url: form.elements.namedItem("feed_url").value.trim(),
      },
    });
    form.reset();
    note.textContent = result.duplicate ? "Already in the directory. Now following it." : "Added and followed. The worker will check it shortly.";
    await loadFeed({ quiet: true });
  } catch (error) {
    note.textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

document.querySelector("#edition-date").textContent = new Intl.DateTimeFormat(undefined, {
  weekday: "short", month: "short", day: "numeric",
}).format(new Date()).toUpperCase();

loadFeed();
setInterval(() => {
  renderWorker(state.worker || {});
  loadFeed({ quiet: true });
}, 12_000);