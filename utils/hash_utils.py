import hashlib
import json
from typing import Any


def compute_hash(data: Any) -> str:
    payload = json.dumps(data, sort_keys=True, default=str, ensure_ascii=False)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()
