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
    def __init__(
        self,
        api_key: str | None = None,
        access_token: str | None = None,
        credential_index: int | None = None,
        credential_indexes: list[int] | None = None,
        rotate_on_limit: bool | None = None,
        round_robin: bool = False,
    ):
        """
        If api_key is omitted, loads credentials from config.
        credential_indexes limits the rotate pool (e.g. seasons: [0,1,2,3]).
        On HTTP 429, rotate_on_limit switches to the next key (wraps to first).
        round_robin advances to the next key after each successful request.
        """
        all_creds = list(config.TMDB_CREDENTIALS) or [
            (config.TMDB_API_KEY, config.TMDB_API_READ_ACCESS_TOKEN)
        ]

        if api_key is not None:
            self._credentials: list[tuple[str, str]] = [(api_key, access_token or "")]
            self._source_indexes = [-1]
            start = 0
        elif credential_indexes is not None:
            if not credential_indexes:
                raise ValueError("credential_indexes must not be empty")
            for idx in credential_indexes:
                if idx < 0 or idx >= len(all_creds):
                    raise ValueError(
                        f"credential index {idx} out of range "
                        f"(have {len(all_creds)} keys)"
                    )
            self._source_indexes = list(credential_indexes)
            self._credentials = [all_creds[i] for i in self._source_indexes]
            start = 0
        else:
            # Pin to a single key when SYNC_KEY_INDEX / credential_index is set
            # (parallel phase scripts). Full-pool rotate only when no pin.
            pin = (
                credential_index
                if credential_index is not None
                else config.SYNC_KEY_INDEX
            )
            if pin is not None:
                if pin < 0 or pin >= len(all_creds):
                    raise ValueError(
                        f"credential index {pin} out of range "
                        f"(have {len(all_creds)} keys)"
                    )
                self._source_indexes = [pin]
                self._credentials = [all_creds[pin]]
                start = 0
            else:
                self._credentials = all_creds
                self._source_indexes = list(range(len(all_creds)))
                start = 0

        self._cred_index = start
        self.api_key = ""
        self.access_token = ""
        self._apply_credential(self._cred_index)

        if rotate_on_limit is None:
            rotate_on_limit = len(self._credentials) > 1
        self.rotate_on_limit = rotate_on_limit
        self.round_robin = bool(round_robin) and len(self._credentials) > 1
        # Keys already tried during the current 429 storm (reset after full cycle sleep).
        self._limited_indexes: set[int] = set()

        self.base_url = config.TMDB_BASE_URL.rstrip("/")
        self.session = self._build_session()
        self.rate_limiter = RateLimiter()
        self.max_retries = config.MAX_RETRIES

    def _source_label(self) -> str:
        src = self._source_indexes[self._cred_index]
        return f"index {src}" if src >= 0 else "fixed"

    def _apply_credential(self, index: int) -> None:
        key, token = self._credentials[index]
        self._cred_index = index
        self.api_key = key or ""
        self.access_token = token or ""

    def _rebuild_auth_headers(self) -> None:
        self.session.headers.pop("Authorization", None)
        if self.access_token:
            self.session.headers["Authorization"] = f"Bearer {self.access_token}"

    def _rotate_credential(self, reason: str) -> bool:
        """
        Switch to the next key that has not yet been rate-limited this round.
        Returns False when every key has been limited (caller should sleep).
        """
        n = len(self._credentials)
        if n <= 1 or not self.rotate_on_limit:
            return False

        self._limited_indexes.add(self._cred_index)
        if len(self._limited_indexes) >= n:
            return False

        for _ in range(n - 1):
            nxt = (self._cred_index + 1) % n
            self._apply_credential(nxt)
            if self._cred_index not in self._limited_indexes:
                self._rebuild_auth_headers()
                logger.warning(
                    "API key rotate — %s → %s (pool %s/%s)",
                    reason,
                    self._source_label(),
                    self._cred_index + 1,
                    n,
                )
                return True
            # Mark and keep scanning (should not happen if set logic is correct)
            self._limited_indexes.add(self._cred_index)
        return False

    def _advance_round_robin(self) -> None:
        if not self.round_robin:
            return
        n = len(self._credentials)
        if n <= 1:
            return
        self._apply_credential((self._cred_index + 1) % n)
        self._rebuild_auth_headers()

    def _reset_limit_cycle(self) -> None:
        self._limited_indexes.clear()

    def _build_session(self) -> requests.Session:
        session = requests.Session()
        # Do not auto-retry 429 here — we rotate keys ourselves.
        retry = Retry(
            total=3,
            connect=3,
            read=3,
            backoff_factor=1,
            status_forcelist=(500, 502, 503, 504),
            allowed_methods=["GET"],
        )
        adapter = HTTPAdapter(max_retries=retry)
        session.mount("https://", adapter)
        headers = {
            "Accept": "application/json",
            "Connection": "close",
        }
        if self.access_token:
            headers["Authorization"] = f"Bearer {self.access_token}"
        session.headers.update(headers)
        return session

    def _reset_session(self) -> None:
        try:
            self.session.close()
        except Exception:
            pass
        self.session = self._build_session()

    def close(self) -> None:
        try:
            self.session.close()
        except Exception:
            pass

    def _format_endpoint(self, path: str, params: dict) -> str:
        safe = {k: v for k, v in params.items() if k != "api_key"}
        query = "&".join(f"{k}={v}" for k, v in sorted(safe.items()))
        endpoint = f"/{path.lstrip('/')}"
        return f"{endpoint}?{query}" if query else endpoint

    def _request(self, method: str, path: str, params: dict | None = None) -> Any:
        url = f"{self.base_url}/{path.lstrip('/')}"
        params = dict(params or {})
        endpoint = self._format_endpoint(path, params)
        last_error: Exception | None = None
        # With key rotation, allow at least one full pass over every credential
        # plus the normal retry budget after an all-keys sleep.
        effective_retries = self.max_retries
        if self.rotate_on_limit and len(self._credentials) > 1:
            effective_retries = max(
                self.max_retries,
                len(self._credentials) + self.max_retries,
            )
        for attempt in range(1, effective_retries + 1):
            # Always send the active key (may have rotated since last attempt).
            req_params = dict(params)
            if "api_key" not in req_params and self.api_key:
                req_params["api_key"] = self.api_key

            self.rate_limiter.wait()
            logger.info(
                "API CALL -> %s %s%s [%s, pool %s/%s]",
                method,
                endpoint,
                f" [retry {attempt}/{effective_retries}]" if attempt > 1 else "",
                self._source_label(),
                self._cred_index + 1,
                len(self._credentials),
            )
            try:
                response = self.session.request(
                    method, url, params=req_params, timeout=(10, 60)
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
                # Successful call — clear limit cycle so keys can be reused later.
                self._reset_limit_cycle()
                logger.info("API OK   <- %s %s [HTTP 200]", method, endpoint)
                self._advance_round_robin()
                return response.json()

            if response.status_code == 404:
                logger.warning("API 404  <- %s %s [Not Found]", method, endpoint)
                return None

            if response.status_code == 401:
                # Bad/disabled key — try next credential before failing hard.
                if self._rotate_credential("HTTP 401"):
                    continue
                logger.error(
                    "API 401  <- %s %s [Unauthorized — check API key]",
                    method, endpoint,
                )
                response.raise_for_status()

            if response.status_code == 429:
                retry_after = int(response.headers.get("Retry-After", 2 ** attempt))
                if self._rotate_credential("HTTP 429"):
                    # Next key immediately — no sleep.
                    continue
                first_src = self._source_indexes[0]
                logger.warning(
                    "API 429  <- %s %s [All %s pool keys limited — sleep %ss, back to index %s]",
                    method,
                    endpoint,
                    len(self._credentials),
                    retry_after,
                    first_src,
                )
                time.sleep(retry_after)
                self._reset_limit_cycle()
                self._apply_credential(0)
                self._rebuild_auth_headers()
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
            f"Failed after {effective_retries} retries: {method} {endpoint} ({last_error})"
        )

    def get(self, path: str, params: dict | None = None) -> Any:
        return self._request("GET", path, params)

    def paginate(self, path: str, params: dict | None = None, start_page: int = 1):
        # TMDB discover/list endpoints reject page > 500 (HTTP 400). Hard cap — do not raise.
        max_page = 500
        page = start_page
        if page > max_page:
            logger.info(
                "Skip paginate %s — start_page %s exceeds TMDB max %s (checkpoint done)",
                path, start_page, max_page,
            )
            return

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
            total_pages = min(int(data.get("total_pages", page) or page), max_page)
            if page >= total_pages:
                break
            if config.SYNC_MAX_PAGES and page >= config.SYNC_MAX_PAGES:
                break
            page += 1
            if page > max_page:
                break


def create_worker_clients(count: int | None = None) -> list[TMDBClient]:
    """One client per worker; each uses a distinct credential (no cross-key rotate)."""
    creds = config.TMDB_CREDENTIALS or [
        (config.TMDB_API_KEY, config.TMDB_API_READ_ACCESS_TOKEN)
    ]
    n = count if count is not None else config.effective_workers()
    n = max(1, min(n, len(creds)))
    clients = []
    for i in range(n):
        key, token = creds[i % len(creds)]
        clients.append(
            TMDBClient(api_key=key, access_token=token, rotate_on_limit=False)
        )
    return clients
