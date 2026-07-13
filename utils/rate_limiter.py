import time

import config


class RateLimiter:
    def __init__(self, sleep_seconds: float | None = None):
        self.sleep_seconds = sleep_seconds or config.RATE_LIMIT_SLEEP

    def wait(self) -> None:
        time.sleep(self.sleep_seconds)
