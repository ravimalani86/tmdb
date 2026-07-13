import time
from typing import Any

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

import config
from utils.logger import get_logger
from utils.rate_limiter import RateLimiter

logger = get_logger(__name__)


class TMDBClient:
    def __init__(self):
        self.base_url = config.TMDB_BASE_URL.rstrip("/")
        self.session = self._build_session()
        self.rate_limiter = RateLimiter()
        self.max_retries = config.MAX_RETRIES

    def _build_session(self) -> requests.Session:
        session = requests.Session()
        retry = Retry(
            total=3,
            connect=3,
            read=3,
            backoff_factor=1,
            status_forcelist=(429, 500, 502, 503, 504),
            allowed_methods=["GET"],
        )
        adapter = HTTPAdapter(max_retries=retry)
        session.mount("https://", adapter)
        session.headers.update(
            {
                "Authorization": f"Bearer {config.TMDB_API_READ_ACCESS_TOKEN}",
                "Accept": "application/json",
                "Connection": "close",
            }
        )
        return session

    def _reset_session(self) -> None:
        try:
            self.session.close()
        except Exception:
            pass
        self.session = self._build_session()

    def _format_endpoint(self, path: str, params: dict) -> str:
        safe = {k: v for k, v in params.items() if k != "api_key"}
        query = "&".join(f"{k}={v}" for k, v in sorted(safe.items()))
        endpoint = f"/{path.lstrip('/')}"
        return f"{endpoint}?{query}" if query else endpoint

    def _request(self, method: str, path: str, params: dict | None = None) -> Any:
        url = f"{self.base_url}/{path.lstrip('/')}"
        params = dict(params or {})
        if "api_key" not in params:
            params["api_key"] = config.TMDB_API_KEY

        endpoint = self._format_endpoint(path, params)
        last_error: Exception | None = None
        for attempt in range(1, self.max_retries + 1):
            self.rate_limiter.wait()
            logger.info(
                "API CALL -> %s %s%s",
                method,
                endpoint,
                f" [retry {attempt}/{self.max_retries}]" if attempt > 1 else "",
            )
            try:
                response = self.session.request(
                    method, url, params=params, timeout=(10, 60)
                )
            except requests.RequestException as exc:
                last_error = exc
                wait = min(2 ** attempt, 60)
                logger.warning(
                    "API FAIL <- %s %s — %s — retry in %ss",
                    method, endpoint, exc, wait,
                )
                if attempt % 3 == 0:
                    self._reset_session()
                time.sleep(wait)
                continue

            if response.status_code == 200:
                logger.info("API OK   <- %s %s [HTTP 200]", method, endpoint)
                return response.json()

            if response.status_code == 404:
                logger.warning("API 404  <- %s %s [Not Found]", method, endpoint)
                return None

            if response.status_code == 401:
                logger.error("API 401  <- %s %s [Unauthorized — check API key]", method, endpoint)
                response.raise_for_status()

            if response.status_code == 429:
                retry_after = int(response.headers.get("Retry-After", 2 ** attempt))
                logger.warning(
                    "API 429  <- %s %s [Rate limited — sleep %ss]",
                    method, endpoint, retry_after,
                )
                time.sleep(retry_after)
                continue

            if response.status_code >= 500:
                wait = min(2 ** attempt, 60)
                logger.warning(
                    "API %s <- %s %s [Server error — retry in %ss]",
                    response.status_code, method, endpoint, wait,
                )
                time.sleep(wait)
                continue

            response.raise_for_status()

        raise RuntimeError(
            f"Failed after {self.max_retries} retries: {method} {endpoint} ({last_error})"
        )

    def get(self, path: str, params: dict | None = None) -> Any:
        return self._request("GET", path, params)

    def paginate(self, path: str, params: dict | None = None, start_page: int = 1):
        page = start_page
        params = dict(params or {})
        while True:
            params["page"] = page
            data = self.get(path, params)
            if not data:
                break
            results = data.get("results", [])
            if not results:
                break
            yield page, data
            total_pages = data.get("total_pages", page)
            if page >= total_pages:
                break
            if config.SYNC_MAX_PAGES and page >= config.SYNC_MAX_PAGES:
                break
            page += 1
